<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Jobs\Modules\RebuildAdminJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use App\Support\Modules\ModuleStatusService;
use Cms\Core\Services\Modules\Install\AdminRebuilder;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/**
 * the admin rebuild step. The process runner is faked; every folder is a temp dir removed after the test.
 */
beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    URL::forceScheme('https');
    $this->tmp = realpath(tap(sys_get_temp_dir().'/admin-rebuild-'.bin2hex(random_bytes(6)), fn ($d) => mkdir($d, 0755, true)));
});

afterEach(function () {
    arRemoveTree($this->tmp);
    putenv('SECRET_X');
    putenv('DB_PASSWORD');
    unset($_SERVER['SECRET_X'], $_SERVER['DB_PASSWORD']);
});

/**
 * Remove a temp tree (links are unlinked, not followed).
 */
function arRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            arRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

/**
 * Create `<tmp>/site/releases/3` with the deploy script; returns the release path.
 */
function arRelease(string $tmp, bool $withScript = true): string
{
    $release = $tmp.'/site/releases/3';
    mkdir($release.'/.github/deployment', 0755, true);
    if ($withScript) {
        file_put_contents($release.'/.github/deployment/admin-rebuild.sh', "#!/bin/bash\n");
    }

    return $release;
}

/**
 * Artisan runner with a fixed PHP binary, so the test does not depend on the host.
 */
function arArtisan(): ArtisanSubprocess
{
    return new class extends ArtisanSubprocess {
        public function phpBinary(): string
        {
            return '/usr/bin/php-cli-test';
        }
    };
}

/**
 * Rebuilder double returning a fixed result, or throwing.
 */
function arFakeRebuilder(bool $ok, ?string $code = null, bool $throw = false): AdminRebuilder
{
    return new class($ok, $code, $throw) extends AdminRebuilder {
        public function __construct(private readonly bool $ok, private readonly ?string $code, private readonly bool $throw)
        {
        }

        public function rebuild(): array
        {
            if ($this->throw) {
                throw new RuntimeException('boom');
            }

            return $this->ok
                ? ['ok' => true, 'errorCode' => null, 'outputDir' => '/abs/runtime/admin-builds/3-x', 'message' => 'ok']
                : ['ok' => false, 'errorCode' => $this->code, 'outputDir' => null, 'message' => 'failed'];
        }
    };
}

/**
 * Create an admin user; Webmaster when a level-0 role is attached.
 */
function arAdmin(string $email, bool $webmaster): User
{
    $user = User::query()->create(['name' => 'Rebuild Admin', 'email' => $email, 'password' => Hash::make('password')]);
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

test('a base path outside releases/ is refused without running anything', function () {
    Process::fake();
    $dir = $this->tmp.'/plain/app';
    mkdir($dir, 0755, true);

    $result = (new AdminRebuilder(arArtisan(), $dir))->rebuild();

    expect($result['ok'])->toBeFalse()->and($result['errorCode'])->toBe(AdminRebuilder::ERROR_NOT_DEPLOYED);
    Process::assertNothingRan();
});

test('a release without the script is not deployed either', function () {
    Process::fake();
    $release = arRelease($this->tmp, false);

    $result = (new AdminRebuilder(arArtisan(), $release))->rebuild();

    expect($result['errorCode'])->toBe(AdminRebuilder::ERROR_NOT_DEPLOYED);
    Process::assertNothingRan();
});

test('success runs the script with an argv array, the site base dir and a 2400 second timeout', function () {
    $release = arRelease($this->tmp);
    Process::fake(['*' => Process::result(output: "log line\nADMIN_BUILD_OK /x/runtime/admin-builds/3-20261107\n", exitCode: 0)]);

    $result = (new AdminRebuilder(arArtisan(), $release))->rebuild();

    expect($result['ok'])->toBeTrue()
        ->and($result['errorCode'])->toBeNull()
        ->and($result['outputDir'])->toBe('/x/runtime/admin-builds/3-20261107');

    $script = $release.'/.github/deployment/admin-rebuild.sh';
    $base = realpath($this->tmp.'/site');
    Process::assertRan(fn ($process) => $process->command === ['bash', realpath($script), '/usr/bin/php-cli-test', $base]
        && $process->timeout === 2400);
});

test('the process environment drops every inherited variable except the kept ones', function () {
    $release = arRelease($this->tmp);
    putenv('SECRET_X=1');
    putenv('DB_PASSWORD=hunter2');
    Process::fake(['*' => Process::result(output: "ADMIN_BUILD_OK /x/y\n", exitCode: 0)]);

    (new AdminRebuilder(arArtisan(), $release))->rebuild();

    Process::assertRan(function ($process) {
        $env = $process->environment;

        return ($env['SECRET_X'] ?? null) === false
            && ($env['DB_PASSWORD'] ?? null) === false
            && ! array_key_exists('PATH', $env)
            && ! array_key_exists('HOME', $env);
    });
});

test('a non-zero exit, or a zero exit without the marker, is a failure', function () {
    $release = arRelease($this->tmp);

    Process::fake(['*' => Process::result(output: "ADMIN_BUILD_OK /x/y\n", errorOutput: 'npm failed', exitCode: 1)]);
    expect((new AdminRebuilder(arArtisan(), $release))->rebuild()['errorCode'])->toBe(AdminRebuilder::ERROR_FAILED);

    Process::fake(['*' => Process::result(output: "done\n", exitCode: 0)]);
    $result = (new AdminRebuilder(arArtisan(), $release))->rebuild();
    expect($result['ok'])->toBeFalse()->and($result['errorCode'])->toBe(AdminRebuilder::ERROR_FAILED);
});

/**
 * Run ModuleInstaller::enable() (steps 6-9) directly with fake collaborators and the given rebuilder.
 *
 * @return array{0: ModuleInstall, 1: BackgroundTask}
 */
function arRunEnable(AdminRebuilder $rebuilder, bool $customCode): array
{
    $manifest = ReleaseManifest::fromVerifiedBytes(json_encode([
        'format' => 1, 'name' => 'ProbeRebuild', 'version' => '2026.1.0', 'zip_sha256' => str_repeat('a', 64),
        'zip_size' => 10, 'unpacked_size' => 10, 'files' => ['ProbeRebuild/module.json' => str_repeat('b', 64)],
        'requires' => [], 'irreversible' => false, 'custom_code' => $customCode, 'php_min' => '8.3',
        'built_at' => '2026-11-07T10:00:00Z', 'key_id' => '0123456789abcdef',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $recorder = new BackgroundTaskRecorder();
    $task = $recorder->start(ModuleInstall::TASK_TYPE, 'Core', null);
    $install = ModuleInstall::query()->create([
        'name' => 'ProbeRebuild', 'version' => '2026.1.0', 'source' => ModuleInstall::SOURCE_HUB,
        'status' => ModuleInstall::STATUS_PLACING, 'background_task_uuid' => $task->uuid, 'warnings' => [],
    ]);

    $statuses = new class extends ModuleStatusService {
        public function change(string $name, bool $enabled, ?User $by, string $reason, bool $refreshCaches = true): void
        {
        }
    };
    $artisan = new class extends ArtisanSubprocess {
        public function run(array $args, int $timeout): ProcessResult
        {
            return new FakeProcessResult(command: implode(' ', $args), exitCode: 0);
        }
    };
    $installer = new class(Mockery::mock(BackupGate::class), $recorder, null, null, null, null, null, null, null, $statuses, $artisan, $rebuilder) extends ModuleInstaller {
        public function runEnable(ModuleInstall $install, ReleaseManifest $manifest, BackgroundTask $task): void
        {
            $this->enable($install, $manifest, $task, '/unused');
        }
    };
    $installer->runEnable($install, $manifest, $task);

    return [$install->refresh(), $task->refresh()];
}

test('a failed rebuild is a warning: the install still succeeds', function () {
    [$install, $task] = arRunEnable(arFakeRebuilder(false, AdminRebuilder::ERROR_FAILED), true);

    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($install->error_code)->toBeNull()
        ->and(collect($install->warnings)->pluck('code')->all())->toBe(['MODULE_ADMIN_BUILD_FAILED'])
        ->and($install->warnings[0]['message'])->toContain('retry')
        ->and($task->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)
        ->and($task->result['warnings'])->toBe(['MODULE_ADMIN_BUILD_FAILED']);
});

test('a not-deployed or throwing rebuilder is a warning too', function () {
    [$install] = arRunEnable(arFakeRebuilder(false, AdminRebuilder::ERROR_NOT_DEPLOYED), true);
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(collect($install->warnings)->pluck('code')->all())->toBe(['MODULE_ADMIN_BUILD_NOT_DEPLOYED']);

    [$install] = arRunEnable(arFakeRebuilder(false, null, true), true);
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and(collect($install->warnings)->pluck('code')->all())->toBe(['MODULE_ADMIN_BUILD_FAILED']);
});

test('a good rebuild adds no warning, and a module without custom code never calls the rebuilder', function () {
    [$install] = arRunEnable(arFakeRebuilder(true), true);
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)->and($install->warnings)->toBe([]);

    [$install] = arRunEnable(arFakeRebuilder(false, null, true), false);
    expect($install->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)->and($install->warnings)->toBe([]);
});

test('the retry endpoint queues a rebuild task and job for a Webmaster', function () {
    Queue::fake();
    Sanctum::actingAs(arAdmin('rebuild-webmaster@test.local', true));

    $response = $this->postJson('/api/v2/admin/utilities/modules/admin-rebuild')->assertStatus(202);

    $task = BackgroundTask::query()->where('uuid', $response->json('data.task_uuid'))->firstOrFail();
    expect($task->type)->toBe('core.admin_build')->and($task->module)->toBe('Core')
        ->and($task->status)->toBe(BackgroundTask::STATUS_QUEUED);
    Queue::assertPushed(RebuildAdminJob::class, fn (RebuildAdminJob $job) => $job->taskUuid === $task->uuid && $job->tries === 1);

    // Running the job with a fake rebuilder closes the task.
    (new RebuildAdminJob($task->uuid))->handle(arFakeRebuilder(true), new BackgroundTaskRecorder());
    $task->refresh();
    expect($task->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)
        ->and($task->result)->toBe(['output' => '3-x']);
});

test('a failing rebuild fails the task with the error code', function () {
    $task = (new BackgroundTaskRecorder())->start('core.admin_build', 'Core', null);

    (new RebuildAdminJob($task->uuid))->handle(arFakeRebuilder(false, AdminRebuilder::ERROR_FAILED), new BackgroundTaskRecorder());

    expect($task->refresh()->status)->toBe(BackgroundTask::STATUS_FAILED)
        ->and((string) $task->message)->toContain('MODULE_ADMIN_BUILD_FAILED');
});

test('the retry endpoint answers 409 while an install is unfinished', function () {
    Queue::fake();
    Sanctum::actingAs(arAdmin('rebuild-webmaster2@test.local', true));
    ModuleInstall::query()->create([
        'name' => 'Probe', 'version' => '2026.1.0', 'source' => ModuleInstall::SOURCE_HUB,
        'status' => ModuleInstall::STATUS_QUEUED, 'background_task_uuid' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    $this->postJson('/api/v2/admin/utilities/modules/admin-rebuild')
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'MODULE_INSTALL_RUNNING');
    Queue::assertNothingPushed();
});

test('a non-Webmaster admin gets 403 and nothing is queued', function () {
    Queue::fake();
    arAdmin('rebuild-filler@test.local', false); // user id 1 is always a Webmaster
    Sanctum::actingAs(arAdmin('rebuild-editor@test.local', false));

    $this->postJson('/api/v2/admin/utilities/modules/admin-rebuild')->assertForbidden();

    expect(BackgroundTask::query()->where('type', 'core.admin_build')->count())->toBe(0);
    Queue::assertNothingPushed();
});
