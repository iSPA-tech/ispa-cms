<?php

declare(strict_types=1);

/**
 * Module install switched on by the Webmaster (spec 2026-11-22, plan Task 1): server/admin precedence, the switch
 * preparing the runtime folder and the activator marker, and the DatabaseActivator import keeping every enabled
 * module enabled. The runtime folder, its storage target, the marker and the statuses file live in a temp folder;
 * no tracked file is written.
 */

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\ActivatorMarker;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstallPolicy;
use Cms\Core\Services\Modules\Install\ModuleInstallSettingException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const MIP_URL = 'https://localhost/api/v2/admin/utilities/modules/install-setting';

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();

    $this->tmp = sys_get_temp_dir() . '/install-policy-' . bin2hex(random_bytes(6));
    mkdir($this->tmp, 0755, true);
    // The expected runtime place is a missing path; switching on creates the storage fallback folder instead.
    $this->runtimeLink = $this->tmp . '/release/runtime-modules';
    mkdir(dirname($this->runtimeLink), 0755, true);
    $this->storageRuntime = $this->tmp . '/storage/app/runtime-modules';
    RuntimeModules::setPath($this->runtimeLink, $this->storageRuntime);
    ActivatorMarker::setPath($this->tmp . '/storage/app/modules/activator');
    $this->app->instance(ModuleInstallPolicy::class, new ModuleInstallPolicy());

    config([
        'core.module_packages.install_enabled' => null,
        'core.module_packages.trusted_keys' => ['0123456789abcdef' => base64_encode(str_repeat('k', 32))],
        'core.module_packages.revoked_key_ids' => [],
        'modules.activator' => 'file',
        'modules.activator_env' => null,
    ]);
});

afterEach(function (): void {
    RuntimeModules::resetPath();
    ActivatorMarker::setPath(null);
    mipPolicyRemoveTree($this->tmp);
});

/** Remove a temp tree (links are unlinked, not followed). */
function mipPolicyRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            mipPolicyRemoveTree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/** An admin account; level 0 role = Webmaster. */
function mipUser(?int $roleLevel): User
{
    $name = 'mip-' . Str::lower(Str::random(6));
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name,
        'email' => $name . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => UserType::ADMIN,
        'level' => 0,
        'status' => null,
    ]);
    if ($roleLevel !== null) {
        $user->roles()->attach(Role::query()->create(['name' => $name . ' role', 'custom' => ['level' => $roleLevel]]));
    }

    return $user->fresh();
}

/** Site-level blocking codes from the preflight (only checkSite matters here). */
function mipSiteCodes(): array
{
    $preflight = new InstallPreflight(new ModuleCatalog(null, sys_get_temp_dir()), '8.3.0', fn (): bool => true, fn (): array => [], fn (): array => [], fn (): array => []);
    $blocking = [];
    $add = static function (array &$list, string $code, string $message, array $details = []): void {
        $list[] = ['code' => $code, 'message' => $message, 'details' => $details];
    };
    (function () use ($add, &$blocking): void {
        $this->checkSite($add, $blocking);
    })->call($preflight);

    return array_column($blocking, 'code');
}

test('env false forbids even with the admin switch on', function (): void {
    app(ModuleInstallPolicy::class)->enable();
    config(['core.module_packages.install_enabled' => false]);

    $policy = app(ModuleInstallPolicy::class);
    expect($policy->settingEnabled())->toBeTrue()
        ->and($policy->state())->toBe(ModuleInstallPolicy::STATE_FORBIDDEN_BY_SERVER)
        ->and(mipSiteCodes())->toContain(InstallPreflight::FORBIDDEN_BY_SERVER)
        ->not->toContain(InstallPreflight::INSTALL_DISABLED);
});

test('env unset and switch off: blocked with the admin message only, not the server set-up codes', function (): void {
    $codes = mipSiteCodes();

    expect(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_DISABLED)
        ->and($codes)->toBe([InstallPreflight::INSTALL_DISABLED]);
});

test('env true keeps the old meaning: allowed without the switch', function (): void {
    config(['core.module_packages.install_enabled' => true]);

    expect(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_ALLOWED);
});

test('switching on creates the runtime folder and the marker; the activator becomes database', function (): void {
    expect(RuntimeModules::path())->toBeNull()
        ->and(ActivatorMarker::activator(null))->toBe('file');

    app(ModuleInstallPolicy::class)->enable();

    // Nothing is created in the code tree: the storage fallback is used where the deploy link would be.
    expect(file_exists($this->runtimeLink) || is_link($this->runtimeLink))->toBeFalse()
        ->and(RuntimeModules::location())->toBe($this->storageRuntime)
        ->and(RuntimeModules::path())->toBe(realpath($this->storageRuntime))
        ->and(is_dir($this->storageRuntime . '/.staging'))->toBeTrue()
        ->and(is_dir($this->storageRuntime . '/.versions'))->toBeTrue()
        ->and(fileperms($this->storageRuntime) & 07777)->toBe(02775)
        ->and(ActivatorMarker::read())->toBe('database')
        ->and(ActivatorMarker::activator(null))->toBe('database')
        ->and(ActivatorMarker::activator(''))->toBe('database')
        ->and(app(ModuleInstallPolicy::class)->state())->toBe(ModuleInstallPolicy::STATE_ALLOWED);

    // Repeating is harmless.
    app(ModuleInstallPolicy::class)->enable();
    expect(RuntimeModules::path())->toBe(realpath($this->storageRuntime));
});

test('a deploy link whose shared target is missing gets its target created', function (): void {
    $shared = $this->tmp . '/shared/runtime/modules';
    symlink($shared, $this->runtimeLink);

    app(ModuleInstallPolicy::class)->enable();

    expect(RuntimeModules::path())->toBe(realpath($shared))
        ->and(is_dir($shared . '/.staging'))->toBeTrue()
        ->and(is_dir($this->storageRuntime))->toBeFalse();
});

test('MODULES_ACTIVATOR=file keeps the file activator after switching on', function (): void {
    app(ModuleInstallPolicy::class)->enable();

    expect(ActivatorMarker::activator('file'))->toBe('file')
        ->and(ActivatorMarker::activator('database'))->toBe('database');

    config(['modules.activator_env' => 'file', 'modules.activator' => 'file']);
    expect(mipSiteCodes())->toContain(InstallPreflight::NEEDS_DB_STATUS)
        ->and(app(ModuleInstallPolicy::class)->resolution()['activator_source'])->toBe('env');
});

test('the database activator import keeps every module enabled in the statuses file enabled', function (): void {
    $statuses = ['Core' => true, 'Content' => true, 'Ecommerce' => true, 'Product' => false, 'Helpdesk' => true];
    $statusesFile = $this->tmp . '/modules_statuses.json';
    file_put_contents($statusesFile, json_encode($statuses));
    $dbFile = $this->tmp . '/statuses.sqlite';
    touch($dbFile);
    config([
        'database.connections.mip_status' => ['driver' => 'sqlite', 'database' => $dbFile, 'prefix' => '', 'foreign_key_constraints' => false],
        'modules.activators.database' => ['class' => DatabaseActivator::class, 'statuses-file' => $statusesFile, 'cache_store' => 'array', 'connection' => 'mip_status'],
    ]);
    DB::purge('mip_status');
    (require base_path('database/migrations/2026_11_06_100000_create_module_statuses_table.php'))->up();

    app(ModuleInstallPolicy::class)->enable();
    expect(ActivatorMarker::activator(null))->toBe('database');

    $activator = new DatabaseActivator($this->app);
    foreach ($statuses as $name => $enabled) {
        expect($activator->hasStatus($name, $enabled))->toBeTrue("{$name} should stay " . ($enabled ? 'enabled' : 'disabled'));
    }
    expect(DB::connection('mip_status')->table('module_statuses')->orderBy('name')->pluck('enabled', 'name')->map(fn ($v) => (bool) $v)->all())
        ->toBe(['Content' => true, 'Core' => true, 'Ecommerce' => true, 'Helpdesk' => true, 'Product' => false]);
    DB::purge('mip_status');
});

test('GET and PUT: the Webmaster switches it on and off, audited only on change', function (): void {
    Event::fake([AuditLogEvent::class]);
    Sanctum::actingAs(mipUser(0), ['*']);

    $this->getJson(MIP_URL)->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.state', 'disabled')
        ->assertJsonPath('data.locked', false)
        ->assertJsonPath('data.server', 'unset')
        ->assertJsonPath('data.runtime_folder', false);

    $this->putJson(MIP_URL, ['enabled' => 'maybe'])->assertStatus(422);
    $this->putJson(MIP_URL, ['enabled' => true])->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.setting', true)
        ->assertJsonPath('data.activator_source', 'marker')
        ->assertJsonPath('data.runtime_folder', true);
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e) => $e->action === 'modules.install_enabled'
        && $e->type === Setting::class && $e->id > 0);

    $this->putJson(MIP_URL, ['enabled' => true])->assertOk();
    Event::assertDispatchedTimes(AuditLogEvent::class, 1);

    $this->putJson(MIP_URL, ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e) => $e->action === 'modules.install_disabled');
    // Switching off keeps the marker: module status now lives in the database.
    expect(ActivatorMarker::read())->toBe('database');
});

test('PUT is refused while the server decides; other admins are refused', function (): void {
    Sanctum::actingAs(mipUser(0), ['*']);

    config(['core.module_packages.install_enabled' => false]);
    $this->getJson(MIP_URL)->assertOk()->assertJsonPath('data.locked', true)->assertJsonPath('data.state', 'forbidden_by_server');
    $this->putJson(MIP_URL, ['enabled' => true])->assertStatus(409)->assertJsonPath('error_code', InstallPreflight::FORBIDDEN_BY_SERVER);

    config(['core.module_packages.install_enabled' => true]);
    $this->putJson(MIP_URL, ['enabled' => false])->assertStatus(409)->assertJsonPath('error_code', 'MODULE_INSTALL_MANAGED_BY_SERVER');
    expect(Setting::query()->where('setting_key', ModuleInstallPolicy::SETTING_KEY)->exists())->toBeFalse()
        ->and(ActivatorMarker::read())->toBeNull();

    Sanctum::actingAs(mipUser(5), ['*']);
    $this->getJson(MIP_URL)->assertStatus(403);
});

test('switching on with a real queue tells the workers to restart; sync sites start no process', function (): void {
    $calls = [];
    $artisan = Mockery::mock(ArtisanSubprocess::class);
    $artisan->shouldReceive('run')->andReturnUsing(function (array $args) use (&$calls) {
        $calls[] = $args;

        return new FakeProcessResult(exitCode: 0);
    });
    $policy = new ModuleInstallPolicy($artisan);

    config(['queue.default' => 'sync']);
    $policy->enable();
    expect($calls)->toBe([]);

    config(['queue.default' => 'redis']);
    $policy->enable();
    expect($calls)->toBe([['queue:restart']]);

    $failing = Mockery::mock(ArtisanSubprocess::class);
    $failing->shouldReceive('run')->andReturn(new FakeProcessResult(exitCode: 1, errorOutput: 'no cache'));
    expect(fn () => (new ModuleInstallPolicy($failing))->enable())
        ->toThrow(ModuleInstallSettingException::class);
});

test('the generic settings endpoints never write the install switch', function (): void {
    Artisan::spy();
    // Every permission granted, so only the protected-key guard can refuse.
    Gate::before(fn () => true);
    Sanctum::actingAs(mipUser(0), ['*']);
    $row = Setting::query()->create([
        'setting_key' => ModuleInstallPolicy::SETTING_KEY, 'type' => 'text', 'group_name' => 'modules',
        'name' => 'install_enabled', 'value' => '0', 'input_setting' => ['isPublic' => false, 'isEnv' => false],
    ]);
    $other = Setting::query()->create([
        'setting_key' => 'basic.mip_title', 'type' => 'text', 'group_name' => 'basic',
        'name' => 'mip_title', 'value' => 'old', 'input_setting' => ['isPublic' => false, 'isEnv' => false],
    ]);
    $base = 'https://localhost/api/v2/admin/setting';

    $this->putJson("{$base}/{$row->id}/quick", ['value' => '1', 'type' => 'text'])->assertStatus(403);
    $this->postJson($base, ['group_name' => 'modules', 'name' => 'install_enabled', 'type' => 'text', 'value' => '1', 'input_setting' => ['language' => false]])
        ->assertStatus(403);
    $this->putJson("{$base}/{$other->id}", ['group_name' => 'modules', 'name' => 'install_enabled', 'type' => 'text', 'list' => [['id' => $other->id]]])
        ->assertStatus(403);
    // The batch save skips the protected key and saves the rest.
    $this->postJson("{$base}/save", ['modules' => [ModuleInstallPolicy::SETTING_KEY => '1'], 'basic' => ['basic.mip_title' => 'new']])->assertOk();

    expect(Setting::query()->where('setting_key', ModuleInstallPolicy::SETTING_KEY)->value('value'))->toBe('0')
        ->and(Setting::query()->where('setting_key', ModuleInstallPolicy::SETTING_KEY)->count())->toBe(1)
        ->and($other->refresh()->setting_key)->toBe('basic.mip_title')
        ->and($other->value)->toBe('new')
        ->and(app(ModuleInstallPolicy::class)->settingEnabled())->toBeFalse();
});
