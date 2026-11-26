<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Jobs\Modules\InstallModuleJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();
    URL::forceScheme('https');

    $this->tmp = sys_get_temp_dir().'/install-endpoint-'.bin2hex(random_bytes(6));
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

    // The preflight runs against the faked hub with a healthy local environment.
    $this->app->instance(InstallPreflight::class, new InstallPreflight(
        new ModuleCatalog(null, $this->releaseDir),
        '8.3.0',
        fn (string $e): bool => true,
        fn (): array => [],
        fn (): array => [],
        fn (): array => [],
    ));
});

afterEach(function () {
    RuntimeModules::resetPath();
    ieRemoveTree($this->tmp);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function ieRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            ieRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Fake a correctly signed hub catalog holding one module "Probe" with the given version entry.
 */
function ieFakeHub(string $secret, array $versionEntry): void
{
    $payload = ['modules' => [['name' => 'Probe', 'latest_version' => $versionEntry['version'], 'versions' => [$versionEntry]]]];
    Http::fake(function (ClientRequest $sent) use ($secret, $payload) {
        $body = json_encode($payload);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, 200, $nonce, $body)]);
    });
}

/**
 * Create an admin user; Webmaster when a level-0 role is attached.
 */
function ieAdmin(string $email, bool $webmaster): User
{
    $user = User::query()->create(['name' => 'Install Admin', 'email' => $email, 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId([
            'name' => 'Webmaster '.uniqid(),
            'custom' => json_encode(['level' => 0]),
        ]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }

    return $user->fresh();
}

test('a Webmaster starts an install: row, background task and job, uuid-first answer', function () {
    ieFakeHub($this->secret, $this->version);
    Sanctum::actingAs(ieAdmin('install-webmaster@test.local', true));

    $response = $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])
        ->assertStatus(202)
        ->assertJsonPath('data.name', 'Probe')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.requested_by');

    $install = ModuleInstall::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
    $task = BackgroundTask::query()->where('uuid', $install->background_task_uuid)->firstOrFail();
    expect($task->type)->toBe('core.module_install')
        ->and($task->status)->toBe(BackgroundTask::STATUS_QUEUED)
        ->and($install->source)->toBe('hub');

    Queue::assertPushed(InstallModuleJob::class, fn (InstallModuleJob $job) => $job->installUuid === $install->uuid
        && $job->queue === 'default' && $job->tries === 1 && $job->timeout === 2700);

    $this->getJson('/api/v2/admin/utilities/modules/installs')
        ->assertOk()
        ->assertJsonPath('data.0.uuid', $install->uuid)
        ->assertJsonMissingPath('data.0.id');

    expect(json_encode($response->json()))->not->toContain($this->secret);
});

test('an admin who is not a Webmaster gets 403', function () {
    ieFakeHub($this->secret, $this->version);
    ieAdmin('install-filler@test.local', false); // user id 1 is always a Webmaster
    Sanctum::actingAs(ieAdmin('install-editor@test.local', false));

    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])->assertForbidden();
    $this->getJson('/api/v2/admin/utilities/modules/installs')->assertForbidden();

    expect(ModuleInstall::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('irreversible migrations need both confirmations, else 422', function () {
    ieFakeHub($this->secret, ['irreversible' => ['2026_01_01_000001_drop_x']] + $this->version);
    Sanctum::actingAs(ieAdmin('install-webmaster2@test.local', true));

    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'MODULE_CONFIRMATION_REQUIRED');
    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0', 'confirm_irreversible' => true, 'confirm_name' => 'probe'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'MODULE_CONFIRMATION_REQUIRED');
    expect(ModuleInstall::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0', 'confirm_irreversible' => true, 'confirm_name' => 'Probe'])
        ->assertStatus(202)
        ->assertJsonPath('data.confirmed_irreversible', true);
});

test('a second install while one is unfinished gets 409', function () {
    ieFakeHub($this->secret, $this->version);
    Sanctum::actingAs(ieAdmin('install-webmaster3@test.local', true));

    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])->assertStatus(202);
    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'MODULE_INSTALL_RUNNING');

    expect(ModuleInstall::query()->count())->toBe(1);
    Queue::assertPushed(InstallModuleJob::class, 1);
});

test('a blocked preflight answers 422 with its own code', function () {
    ieFakeHub($this->secret, $this->version);
    config(['core.module_packages.install_enabled' => false]);
    Sanctum::actingAs(ieAdmin('install-webmaster4@test.local', true));

    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', InstallPreflight::FORBIDDEN_BY_SERVER);
    expect(ModuleInstall::query()->count())->toBe(0);
});

test('the backup choice is stored; it defaults to on when the request does not say', function () {
    ieFakeHub($this->secret, $this->version);
    Sanctum::actingAs(ieAdmin('install-webmaster5@test.local', true));

    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])
        ->assertStatus(202)
        ->assertJsonPath('data.backup_skipped', false);
    ModuleInstall::query()->update(['status' => ModuleInstall::STATUS_SUCCEEDED, 'finished_at' => now()]);

    $uuid = $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0', 'backup' => false])
        ->assertStatus(202)
        ->assertJsonPath('data.backup_skipped', true)
        ->json('data.uuid');
    expect(ModuleInstall::query()->where('uuid', $uuid)->value('backup_skipped'))->toBeTrue();

    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0', 'backup' => 'maybe'])
        ->assertStatus(422);
    ModuleInstall::query()->update(['status' => ModuleInstall::STATUS_SUCCEEDED, 'finished_at' => now()]);
    Cache::flush();

    // An explicit null never skips the backup.
    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0', 'backup' => null])
        ->assertStatus(202)
        ->assertJsonPath('data.backup_skipped', false);
});

test('the preflight carries the backup default and the last scheduled backup', function () {
    ieFakeHub($this->secret, ['migrations' => ['2026_01_01_000000_create_probe_items_table']] + $this->version);
    Sanctum::actingAs(ieAdmin('install-webmaster6@test.local', true));
    DB::table(CoreTableConstant::BACKUPS)->insert([
        'uuid' => (string) Illuminate\Support\Str::uuid(), 'reason' => 'scheduled', 'status' => 'succeeded', 'db_driver' => 'sqlite',
        'db_name' => 'x', 'format' => 'sqlite.gz', 'started_at' => '2026-11-13 01:00:00', 'finished_at' => '2026-11-13 01:05:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson('/api/v2/admin/utilities/modules/catalog/Probe/2026.1.0/preflight')
        ->assertOk()
        ->assertJsonPath('data.backup.recommended', false)
        ->assertJsonPath('data.backup.reason', 'own_tables_only')
        ->assertJsonPath('data.backup.last_backup.source', 'scheduled');
});

test('starting installs is limited to 10 per 10 minutes per Webmaster: the 4th start passes, the 11th gets 429 with Retry-After', function () {
    ieFakeHub($this->secret, $this->version);
    Sanctum::actingAs(ieAdmin('install-webmaster-throttle@test.local', true));

    // Only the first start creates a run; the others are refused as "already running" but still count as starts.
    foreach (range(1, 10) as $attempt) {
        $status = $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])->status();
        expect($status)->toBe($attempt === 1 ? 202 : 409, 'start '.$attempt);
    }

    $refused = $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(600);
});

test('start, upload and uninstall share the same abuse limit, and Retry-After is readable by a cross-origin admin', function () {
    $limits = [];
    foreach (app('router')->getRoutes() as $route) {
        $key = implode('|', $route->methods()).' '.$route->uri();
        foreach ([
            'POST api/v2/admin/utilities/modules/install' => 'install',
            'POST api/v2/admin/utilities/modules/upload' => 'upload',
            'DELETE api/v2/admin/utilities/modules/runtime/{name}' => 'uninstall',
        ] as $wanted => $label) {
            if ($key === $wanted) {
                $limits[$label] = array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle.route:')));
            }
        }
    }

    expect($limits)->toBe([
        'install' => ['throttle.route:10,10'],
        'upload' => ['throttle.route:10,10'],
        'uninstall' => ['throttle.route:10,10'],
    ]);
    expect(config('cors.exposed_headers'))->toContain('Retry-After');
});
