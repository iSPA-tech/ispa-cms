<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Scheduler\SchedulerHeartbeat;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    $GLOBALS['preflight_faked'] = false;

    $this->tmp = sys_get_temp_dir().'/preflight-'.bin2hex(random_bytes(6));
    $this->releaseDir = $this->tmp.'/release';
    $this->runtimeDir = $this->tmp.'/runtime';
    mkdir($this->releaseDir, 0755, true);
    mkdir($this->runtimeDir, 0755, true);
    RuntimeModules::setPath($this->runtimeDir);

    $this->secret = 'test-secret-'.bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
        'core.module_packages.install_enabled' => true,
        'core.module_packages.trusted_keys' => ['0123456789abcdef' => base64_encode(str_repeat('k', 32))],
        'core.module_packages.revoked_key_ids' => [],
        'core.module_packages.job_timeout' => 2700,
        'modules.activator' => 'database',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 3000,
    ]);

    $this->version = ['version' => '2026.1.0', 'revoked' => false, 'requires' => [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.1.0'];
});

afterEach(function () {
    RuntimeModules::resetPath();
    preflightRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function preflightRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            preflightRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Fake a correctly signed hub catalog holding one module "Probe" with the given version entry.
 */
function preflightFakeHub(string $secret, array $versionEntry, string $name = 'Probe'): void
{
    // Http::fake() stubs accumulate and the first match wins, so register once and swap the payload.
    $GLOBALS['preflight_payload'] = ['modules' => [['name' => $name, 'latest_version' => $versionEntry['version'], 'versions' => [$versionEntry]]]];
    if (empty($GLOBALS['preflight_faked'])) {
        $GLOBALS['preflight_faked'] = true;
        Http::fake(function (ClientRequest $sent) use ($secret) {
            $body = json_encode($GLOBALS['preflight_payload']);
            $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

            return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, 200, $nonce, $body)]);
        });
    }
}

/**
 * Build a preflight whose environment is healthy unless overridden.
 */
function preflightMake(object $t, array $overrides = [], ?string $releaseDir = null): InstallPreflight
{
    return new InstallPreflight(
        new ModuleCatalog(null, $releaseDir ?? $t->releaseDir),
        $overrides['php'] ?? '8.3.0',
        $overrides['ext'] ?? fn (string $e): bool => true,
        $overrides['migrationPaths'] ?? fn (): array => [],
        $overrides['ran'] ?? fn (): array => [],
        $overrides['skipped'] ?? fn (): array => [],
        null,
        $overrides['hasTable'] ?? null,
    );
}

test('a healthy site gets no blocking code and a change list with a resource-only warning', function () {
    preflightFakeHub($this->secret, $this->version);

    $result = preflightMake($this)->check('Probe', '2026.1.0');

    expect($result->blockingCodes())->toBe([])
        ->and($result->canInstall())->toBeTrue()
        ->and($result->warningCodes())->toBe([InstallPreflight::WARN_RESOURCE_ONLY])
        ->and($result->changes)->not->toBeEmpty();
});

test('custom code and irreversible migrations raise warnings', function () {
    preflightFakeHub($this->secret, ['custom_code' => true, 'irreversible' => ['2026_01_01_000001_drop_x']] + $this->version);

    $result = preflightMake($this)->check('Probe', '2026.1.0');

    expect($result->warningCodes())->toBe([InstallPreflight::WARN_IRREVERSIBLE, InstallPreflight::WARN_CUSTOM_CODE])
        ->and(implode(' ', $result->changes))->toContain('Rebuild the admin');
});

test('site level blocking codes', function (string $code, Closure $break) {
    preflightFakeHub($this->secret, $this->version);
    $break($this);

    expect(preflightMake($this)->check('Probe', '2026.1.0')->blockingCodes())->toContain($code);
})->with([
    'install locked by the server' => [InstallPreflight::FORBIDDEN_BY_SERVER, fn () => config(['core.module_packages.install_enabled' => false])],
    'install switched off in the admin' => [InstallPreflight::INSTALL_DISABLED, fn () => config(['core.module_packages.install_enabled' => null])],
    'activator is file' => [InstallPreflight::NEEDS_DB_STATUS, fn () => config(['modules.activator' => 'file'])],
    'no runtime folder' => [InstallPreflight::NO_RUNTIME_FOLDER, fn ($t) => RuntimeModules::setPath($t->tmp.'/missing')],
    'no trusted key' => [InstallPreflight::NO_TRUSTED_KEY, fn () => config(['core.module_packages.trusted_keys' => []])],
    'only trusted key is revoked' => [InstallPreflight::NO_TRUSTED_KEY, fn () => config(['core.module_packages.revoked_key_ids' => ['0123456789abcdef']])],
    'queue is sync and the scheduler never ran' => [InstallPreflight::NO_RUNNER, fn ($t) => config(['queue.default' => 'sync', 'core.scheduler.heartbeat_path' => $t->tmp.'/heartbeat'])],
    'queue is sync and the scheduler heartbeat is old' => [InstallPreflight::NO_RUNNER, function ($t) {
        config(['queue.default' => 'sync', 'core.scheduler.heartbeat_path' => $t->tmp.'/heartbeat']);
        file_put_contents($t->tmp.'/heartbeat', (string) (time() - 600));
    }],
    // A short retry_after means the scheduler runs installs (the queue would redeliver a long install), so the
    // scheduler heartbeat decides, not the queue timeout (fix 2026-11-25).
    'retry_after equals the job timeout and the scheduler never ran' => [InstallPreflight::NO_RUNNER, fn ($t) => config(['queue.connections.redis.retry_after' => 2700, 'core.scheduler.heartbeat_path' => $t->tmp.'/heartbeat'])],
    'redis default retry_after 900 and the scheduler heartbeat is old' => [InstallPreflight::NO_RUNNER, function ($t) {
        config(['queue.connections.redis.retry_after' => 900, 'core.scheduler.heartbeat_path' => $t->tmp.'/heartbeat']);
        file_put_contents($t->tmp.'/heartbeat', (string) (time() - 600));
    }],
]);

test('a queue whose retry_after is not longer than the job timeout is not blocked when the scheduler runs', function (int $retryAfter) {
    preflightFakeHub($this->secret, $this->version);
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => $retryAfter, 'core.scheduler.heartbeat_path' => $this->tmp.'/heartbeat']);
    app(SchedulerHeartbeat::class)->beat();

    $codes = preflightMake($this)->check('Probe', '2026.1.0')->blockingCodes();

    expect($codes)->not->toContain(InstallPreflight::QUEUE_TIMEOUT)
        ->and($codes)->not->toContain(InstallPreflight::NO_RUNNER);
})->with(['redis default 900' => [900], 'equal to the job timeout' => [2700], 'database default 90' => [90]]);

test('a sync queue with a fresh scheduler heartbeat is not blocked: installs run through the scheduler', function () {
    preflightFakeHub($this->secret, $this->version);
    config(['queue.default' => 'sync', 'core.scheduler.heartbeat_path' => $this->tmp.'/heartbeat']);
    app(SchedulerHeartbeat::class)->beat();

    $codes = preflightMake($this)->check('Probe', '2026.1.0')->blockingCodes();

    expect($codes)->not->toContain(InstallPreflight::NO_RUNNER)
        ->and($codes)->not->toContain(InstallPreflight::QUEUE_SYNC)
        ->and($codes)->not->toContain(InstallPreflight::QUEUE_TIMEOUT);
});

test('extension and php version blocks', function () {
    preflightFakeHub($this->secret, ['php_min' => '99.0.0'] + $this->version);

    $result = preflightMake($this, ['ext' => fn (string $e): bool => $e !== 'sodium'])->check('Probe', '2026.1.0');

    expect($result->blockingCodes())->toContain(InstallPreflight::PHP_TOO_OLD, InstallPreflight::EXTENSION_MISSING);
    $ext = collect($result->blocking)->firstWhere('code', InstallPreflight::EXTENSION_MISSING);
    expect($ext['details']['extensions'])->toBe(['sodium']);
});

test('a revoked version and an unpublished version are blocked', function () {
    preflightFakeHub($this->secret, ['revoked' => true, 'revoked_reason' => 'bad build'] + $this->version);
    $preflight = preflightMake($this);

    $revoked = $preflight->check('Probe', '2026.1.0');
    expect($revoked->blockingCodes())->toContain(InstallPreflight::REVOKED);
    expect(collect($revoked->blocking)->firstWhere('code', InstallPreflight::REVOKED)['details']['reason'])->toBe('bad build');

    expect($preflight->check('Probe', '2099.1.0')->blockingCodes())->toContain(InstallPreflight::NOT_IN_CATALOG);
});

test('requires accepts the list form and the object form', function () {
    // Real release folder: Core is installed and enabled there.
    $make = fn () => preflightMake($this, [], base_path('cms'));

    preflightFakeHub($this->secret, ['requires' => ['Core']] + $this->version);
    expect($make()->check('Probe', '2026.1.0')->blockingCodes())->not->toContain(InstallPreflight::REQUIRES_UNMET);

    (new ModuleCatalog())->forget();
    preflightFakeHub($this->secret, ['requires' => ['core' => '>=2026.03.0', 'Webshopzz' => '*']] + $this->version);
    $unmet = collect($make()->check('Probe', '2026.1.0')->blocking)->firstWhere('code', InstallPreflight::REQUIRES_UNMET);
    expect($unmet['details']['unmet'])->toHaveCount(1)
        ->and($unmet['details']['unmet'][0])->toMatchArray(['module' => 'Webshopzz', 'reason' => 'missing']);

    (new ModuleCatalog())->forget();
    preflightFakeHub($this->secret, ['requires' => ['Core' => '>=2999.01.0']] + $this->version);
    $unmet = collect($make()->check('Probe', '2026.1.0')->blocking)->firstWhere('code', InstallPreflight::REQUIRES_UNMET);
    expect($unmet['details']['unmet'][0])->toMatchArray(['module' => 'Core', 'constraint' => '>=2999.01.0', 'reason' => 'version']);
});

test('a lower-case requires entry finds the installed module whose name has inner capitals', function () {
    // Temp release folder with a module named like QuestionBank; the hub entry requires it as `probebank`.
    mkdir($this->releaseDir.'/ProbeBank');
    file_put_contents($this->releaseDir.'/ProbeBank/module.json', json_encode(['name' => 'ProbeBank', 'version' => '2026.1.0']));
    preflightFakeHub($this->secret, ['requires' => ['probebank' => '>=2027.1.0']] + $this->version);

    $unmet = collect(preflightMake($this)->check('Probe', '2026.1.0')->blocking)->firstWhere('code', InstallPreflight::REQUIRES_UNMET);

    // Found under its real name: not "missing". The temp module is not booted, so it reads as disabled.
    expect($unmet['details']['unmet'])->toHaveCount(1)
        ->and($unmet['details']['unmet'][0])->toMatchArray(['module' => 'ProbeBank', 'installed_version' => '2026.1.0'])
        ->and($unmet['details']['unmet'][0]['reason'])->not->toBe('missing');
});

test('version constraints', function (string $version, string $constraint, bool $expected) {
    expect(ModuleVersionConstraint::satisfies($version, $constraint))->toBe($expected);
})->with([
    ['2026.03.0', '*', true],
    ['2026.03.0', '>=2026.03.0', true],
    ['2026.03.0', '>2026.03.0', false],
    ['2026.03.0', '<=2026.03.0', true],
    ['2026.03.0', '<2026.03.0', false],
    ['2026.03.0', '=2026.03.0', true],
    ['2026.03.0', '2026.03.0', true],
    ['2026.03.1', '2026.03.0', false],
    ['2026.05.0', '>=2026.03.0,<2026.06.0', true],
    ['2026.07.0', '>=2026.03.0,<2026.06.0', false],
    ['2026.03.0', '^2026', false],
    ['2026.03.0', 'garbage', false],
]);

test('requires list and object forms parse to name => constraint', function () {
    expect(ModuleVersionConstraint::parseRequires(['content', 'Product']))->toBe(['Content' => '*', 'Product' => '*'])
        ->and(ModuleVersionConstraint::parseRequires(['content' => '>=1.0.0', 'Product' => '']))->toBe(['Content' => '>=1.0.0', 'Product' => '*']);
});

test('a module already in the release folder or the runtime folder is already present', function () {
    mkdir($this->releaseDir.'/Probe');
    preflightFakeHub($this->secret, $this->version);

    $codes = preflightMake($this)->check('Probe', '2026.1.0')->blockingCodes();
    expect($codes)->toContain(InstallPreflight::ALREADY_PRESENT)->and($codes)->toContain(InstallPreflight::NAME_CONFLICT);

    rmdir($this->releaseDir.'/Probe');
    mkdir($this->runtimeDir.'/Probe');
    $codes = preflightMake($this)->check('Probe', '2026.1.0')->blockingCodes();
    expect($codes)->toContain(InstallPreflight::ALREADY_PRESENT)->and($codes)->not->toContain(InstallPreflight::NAME_CONFLICT);
});

test('a name that differs only in case from a release module conflicts without being a duplicate', function () {
    mkdir($this->releaseDir.'/PROBE');
    preflightFakeHub($this->secret, $this->version);

    $codes = preflightMake($this)->check('Probe', '2026.1.0')->blockingCodes();

    expect($codes)->toContain(InstallPreflight::NAME_CONFLICT)->and($codes)->not->toContain(InstallPreflight::ALREADY_PRESENT);
});

test('a runtime module skipped as invalid still occupies the name', function () {
    mkdir($this->runtimeDir.'/Probe');
    preflightFakeHub($this->secret, $this->version);

    $codes = preflightMake($this, ['skipped' => fn (): array => ['Probe' => ['module.json name mismatch']]])->check('Probe', '2026.1.0')->blockingCodes();

    expect($codes)->toContain(InstallPreflight::NAME_CONFLICT)->and($codes)->not->toContain(InstallPreflight::ALREADY_PRESENT);
});

test('pending migrations of enabled modules are found against the ran list', function () {
    $migrations = $this->tmp.'/migrations';
    mkdir($migrations);
    touch($migrations.'/2026_01_01_000001_make_a.php');
    touch($migrations.'/2026_01_01_000002_make_b.php');
    preflightFakeHub($this->secret, $this->version);

    $pending = preflightMake($this, [
        'migrationPaths' => fn (): array => [$migrations],
        'ran' => fn (): array => ['2026_01_01_000001_make_a'],
    ])->check('Probe', '2026.1.0');

    $block = collect($pending->blocking)->firstWhere('code', InstallPreflight::PENDING_MIGRATIONS);
    expect($block['details']['migrations'])->toBe(['2026_01_01_000002_make_b']);

    $clean = preflightMake($this, [
        'migrationPaths' => fn (): array => [$migrations],
        'ran' => fn (): array => ['2026_01_01_000001_make_a', '2026_01_01_000002_make_b'],
    ])->check('Probe', '2026.1.0');
    expect($clean->blockingCodes())->not->toContain(InstallPreflight::PENDING_MIGRATIONS);
});

test('an unsigned hub answer is an error, not an empty catalog or a clean preflight', function () {
    Http::fake(['*' => Http::response(json_encode(['modules' => []]), 200)]);

    expect(fn () => preflightMake($this)->check('Probe', '2026.1.0'))->toThrow(PlatformHubException::class);
});

test('reinstall over tables kept by an earlier uninstall is allowed and defaults the backup on, naming the tables', function () {
    $create = '2026_01_01_000000_create_probe_items_table';
    preflightFakeHub($this->secret, $this->version + ['migrations' => [$create]]);

    $result = preflightMake($this, [
        'ran' => fn (): array => [$create],
        'hasTable' => fn (string $t): bool => $t === 'probe_items',
    ])->check('Probe', '2026.1.0');

    expect($result->blockingCodes())->toBe([])
        ->and($result->kind)->toBe('install')
        ->and($result->backup)->toMatchArray([
            'recommended' => true,
            'reason' => 'earlier_data',
            'new_migrations' => 0,
            'earlier_tables' => ['probe_items'],
            'conflicting_tables' => [],
        ]);
});

test('a first install whose create-table migration would hit an existing table is blocked with the table names', function () {
    $create = '2026_01_01_000000_create_probe_items_table';
    preflightFakeHub($this->secret, $this->version + ['migrations' => [$create, '2026_01_02_000000_create_probe_tags_table']]);

    $result = preflightMake($this, [
        'hasTable' => fn (string $t): bool => $t === 'probe_items',
    ])->check('Probe', '2026.1.0');

    $block = collect($result->blocking)->firstWhere('code', InstallPreflight::TABLE_EXISTS);
    expect($result->canInstall())->toBeFalse()
        ->and($block['details'])->toBe(['tables' => ['probe_items']])
        ->and($block['message'])->toContain('probe_items')
        ->and($result->backup['reason'])->toBe('alters_existing');

    // A fresh database: same package, nothing blocks and only new tables are added.
    $fresh = preflightMake($this, ['hasTable' => fn (string $t): bool => false])->check('Probe', '2026.1.0');
    expect($fresh->blockingCodes())->toBe([])
        ->and($fresh->backup)->toMatchArray(['reason' => 'own_tables_only', 'recommended' => false, 'earlier_tables' => []]);
});
