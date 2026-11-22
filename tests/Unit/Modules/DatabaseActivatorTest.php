<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\Exceptions\CoreModuleCannotBeDisabled;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Each test gets its own sqlite file database (connection `module_status_test`),
 * the array cache store and a temporary statuses file. The real
 * modules_statuses.json is never read or written.
 */
beforeEach(function (): void {
    $this->dbFile = tempnam(sys_get_temp_dir(), 'mst-db-');
    $this->statusesFile = tempnam(sys_get_temp_dir(), 'mst-json-');
    file_put_contents($this->statusesFile, json_encode(['Core' => true, 'Content' => true, 'Ecommerce' => false]));

    config([
        'database.connections.module_status_test' => [
            'driver' => 'sqlite',
            'database' => $this->dbFile,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
        'modules.activators.database' => [
            'class' => DatabaseActivator::class,
            'statuses-file' => $this->statusesFile,
            'cache_store' => 'array',
            'connection' => 'module_status_test',
        ],
    ]);
    DB::purge('module_status_test');
    Cache::store('array')->flush();
    DatabaseActivator::flushProcessState();
    Log::spy();

    $this->migrate = function (): void {
        $migration = require base_path('database/migrations/2026_11_06_100000_create_module_statuses_table.php');
        $migration->up();
    };
    $this->activator = fn (): DatabaseActivator => new DatabaseActivator($this->app);
    $this->rows = fn (): array => DB::connection('module_status_test')->table('module_statuses')
        ->orderBy('name')->pluck('enabled', 'name')->map(fn ($v) => (bool) $v)->all();
});

afterEach(function (): void {
    DB::purge('module_status_test');
    @unlink($this->dbFile);
    @unlink($this->statusesFile);
    DatabaseActivator::flushProcessState();
});

test('migration creates exactly the agreed columns', function (): void {
    ($this->migrate)();

    expect(Schema::connection('module_status_test')->getColumnListing('module_statuses'))
        ->toEqualCanonicalizing(['name', 'enabled', 'version', 'changed_by', 'changed_at']);
});

test('missing table falls back to the statuses file', function (): void {
    $activator = ($this->activator)();

    expect($activator->hasStatus('Content', true))->toBeTrue()
        ->and($activator->hasStatus('Ecommerce', false))->toBeTrue()
        ->and($activator->hasStatus('Core', true))->toBeTrue();
});

test('empty table imports the file once and ignores later file changes', function (): void {
    ($this->migrate)();

    expect(($this->activator)()->hasStatus('Content', true))->toBeTrue()
        ->and(($this->rows)())->toBe(['Content' => true, 'Core' => true, 'Ecommerce' => false]);
    Log::shouldHaveReceived('info')->once();

    file_put_contents($this->statusesFile, json_encode(['Core' => true, 'Content' => false, 'Ecommerce' => true]));
    Cache::store('array')->flush();

    $fresh = ($this->activator)();
    expect($fresh->hasStatus('Content', true))->toBeTrue()
        ->and($fresh->hasStatus('Ecommerce', false))->toBeTrue();
});

test('core is always enabled even when the stored row says otherwise', function (): void {
    ($this->migrate)();
    DB::connection('module_status_test')->table('module_statuses')->insert(['name' => 'Core', 'enabled' => false]);

    $activator = ($this->activator)();

    expect($activator->hasStatus('Core', true))->toBeTrue()
        ->and($activator->hasStatus('Core', false))->toBeFalse()
        ->and($activator->hasStatus(app('modules')->find('Core'), true))->toBeTrue();
});

test('every core disabling write throws', function (Closure $call): void {
    ($this->migrate)();
    $activator = ($this->activator)();
    $core = app('modules')->find('Core');

    expect(fn () => $call($activator, $core))->toThrow(CoreModuleCannotBeDisabled::class);
    expect($activator->hasStatus('Core', true))->toBeTrue();
})->with([
    'disable' => [fn ($a, $core) => $a->disable($core)],
    'setActive false' => [fn ($a, $core) => $a->setActive($core, false)],
    'setActiveByName false' => [fn ($a, $core) => $a->setActiveByName('Core', false)],
    'delete' => [fn ($a, $core) => $a->delete($core)],
]);

test('enable and disable round trip is visible to a fresh activator sharing the cache', function (): void {
    ($this->migrate)();
    $activator = ($this->activator)();
    expect($activator->hasStatus('Ecommerce', false))->toBeTrue();

    $activator->setActiveByName('Ecommerce', true);
    expect($activator->hasStatus('Ecommerce', true))->toBeTrue()
        ->and(($this->activator)()->hasStatus('Ecommerce', true))->toBeTrue();

    $activator->setActiveByName('Ecommerce', false);
    expect(($this->activator)()->hasStatus('Ecommerce', false))->toBeTrue();

    $row = DB::connection('module_status_test')->table('module_statuses')->where('name', 'Ecommerce')->first();
    expect($row->changed_at)->not->toBeNull();
});

test('unknown module is disabled', function (): void {
    ($this->migrate)();
    $activator = ($this->activator)();

    expect($activator->hasStatus('NoSuchModule', false))->toBeTrue()
        ->and($activator->hasStatus('NoSuchModule', true))->toBeFalse();
});

test('different databases use different cache keys', function (): void {
    $first = ($this->activator)()->cacheKey();

    config(['database.connections.module_status_other' => [
        'driver' => 'sqlite', 'database' => sys_get_temp_dir().'/other-site.sqlite', 'prefix' => '',
    ], 'modules.activators.database.connection' => 'module_status_other']);

    $second = ($this->activator)()->cacheKey();

    expect($first)->toStartWith('module-statuses:v1:')
        ->and($second)->toStartWith('module-statuses:v1:')
        ->and($first)->not->toBe($second);
});

test('database error after a warm cache serves the cached values and warns once', function (): void {
    ($this->migrate)();
    $warm = ($this->activator)();
    $warm->setActiveByName('Ecommerce', true);
    expect(($this->activator)()->hasStatus('Ecommerce', true))->toBeTrue();

    // Break the connection without changing its config (same cache key) and let the fresh cache entry expire.
    DB::purge('module_status_test');
    unlink($this->dbFile);
    $this->travel(2)->days();

    $first = ($this->activator)();
    $second = ($this->activator)();

    expect($first->hasStatus('Ecommerce', true))->toBeTrue()
        ->and($second->hasStatus('Ecommerce', true))->toBeTrue()
        ->and($second->hasStatus('Content', true))->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});

test('database error without any cached value falls back to the file', function (): void {
    unlink($this->dbFile);

    $activator = ($this->activator)();

    expect($activator->hasStatus('Content', true))->toBeTrue()
        ->and($activator->hasStatus('Ecommerce', false))->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});

test('warm cache answers without any query', function (): void {
    ($this->migrate)();
    ($this->activator)()->hasStatus('Content', true);

    $connection = DB::connection('module_status_test');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $fresh = ($this->activator)();
    expect($fresh->hasStatus('Content', true))->toBeTrue()
        ->and($fresh->hasStatus('Ecommerce', false))->toBeTrue()
        ->and($connection->getQueryLog())->toBe([]);
});

test('reset keeps only core', function (): void {
    ($this->migrate)();
    $activator = ($this->activator)();
    $activator->setActiveByName('Ecommerce', true);

    $activator->reset();

    expect(($this->rows)())->toBe(['Core' => true])
        ->and($activator->hasStatus('Content', false))->toBeTrue()
        ->and($activator->hasStatus('Core', true))->toBeTrue();
});

test('setVersion stores the version without enabling a new module', function (): void {
    ($this->migrate)();
    $activator = ($this->activator)();

    $activator->setVersion('Content', '2026.11.0');
    $activator->setVersion('Brand', '1.0.0');
    $activator->setVersion('Core', '2026.03.0');

    $versions = DB::connection('module_status_test')->table('module_statuses')->pluck('version', 'name')->all();
    expect($versions['Content'])->toBe('2026.11.0')
        ->and($versions['Brand'])->toBe('1.0.0')
        ->and($versions['Core'])->toBe('2026.03.0')
        ->and($activator->hasStatus('Content', true))->toBeTrue()
        ->and($activator->hasStatus('Brand', false))->toBeTrue()
        ->and($activator->hasStatus('Core', true))->toBeTrue();
});
