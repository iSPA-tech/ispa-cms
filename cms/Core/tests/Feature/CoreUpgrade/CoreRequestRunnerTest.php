<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\CoreUpgrade\CoreRequestRunner;
use Cms\Core\Services\CoreUpgrade\CoreRunnerTick;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeException;
use Cms\Core\Services\CoreUpgrade\CoreUpgradePreflight;
use Cms\Core\Services\CoreUpgrade\CoreUpgrader;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Illuminate\Support\Facades\File;

/** Upgrader on a temp base that records run() and restore() and never swaps files. */
final class CrrFakeUpgrader extends CoreUpgrader
{
    /** @var list<array<int, mixed>> */
    public array $runs = [];

    public int $restores = 0;

    public ?Throwable $throw = null;

    /** @var list<?string> Every usePreparedBackup() call. */
    public array $prepared = [];

    public function usePreparedBackup(?string $uuid): void
    {
        $this->prepared[] = $uuid;
    }

    public function run(string $targetVersion, bool $overwriteModified, ?BackgroundTask $task = null, ?int $userId = null, array $extraPhpVersions = []): void
    {
        $this->runs[] = [$targetVersion, $overwriteModified, $task, $userId, $extraPhpVersions];
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }

    public function restore(): void
    {
        $this->restores++;
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}

/** A flat site installed from a core package, with a matching manifest. */
function crrBase(bool $manifest = true): string
{
    $base = realpath(sys_get_temp_dir()).'/crr-'.bin2hex(random_bytes(6));
    mkdir($base.'/app', 0775, true);
    file_put_contents($base.'/app/X.php', '<?php');
    if ($manifest) {
        file_put_contents($base.'/core-manifest.json', json_encode([
            'format' => 1, 'version' => '2026.11.0', 'php_min' => '8.1', 'built_at' => '2026-11-22T00:00:00+00:00',
            'composer_lock_sha256' => '', 'checked' => ['app/X.php' => hash_file('sha256', $base.'/app/X.php')], 'replaced' => [],
        ]));
    }

    return $base;
}

function crrRunner(string $base, CoreUpgrader $upgrader): CoreRequestRunner
{
    $preflight = new CoreUpgradePreflight(
        $base,
        null,
        static fn (string $path) => 10 ** 12,
        static fn (): array => ['at' => time(), 'user' => 'deploy', 'uid' => posix_geteuid(), 'php_version' => PHP_VERSION],
        static fn (): bool => false,
    );

    return new CoreRequestRunner($upgrader, $preflight, app(BackgroundTaskRecorder::class));
}

beforeEach(function () {
    // The wait for the web side's safety backup has its own tests; the others run without it.
    config(['core.upgrade.backup_wait_seconds' => 0]);
    $this->base = crrBase();
    $this->upgrader = new CrrFakeUpgrader($this->base);
    $this->runner = crrRunner($this->base, $this->upgrader);
    $this->requests = new CoreUpgradeRequests(app(BackgroundTaskRecorder::class), $this->base);
    $this->requestFile = $this->base.'/'.CoreRunnerTick::REQUEST_FILE;
    $this->heartbeatFile = $this->base.'/'.CoreRunnerTick::HEARTBEAT_FILE;
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

test('cannot_run leaves no heartbeat and claims nothing', function () {
    $base = crrBase(false);
    try {
        $requests = new CoreUpgradeRequests(app(BackgroundTaskRecorder::class), $base);
        $task = $requests->create('upgrade', '2026.12.0', 7);
        $upgrader = new CrrFakeUpgrader($base);

        expect(crrRunner($base, $upgrader)->tick())->toBe('cannot_run')
            ->and(is_file($base.'/'.CoreRunnerTick::HEARTBEAT_FILE))->toBeFalse()
            ->and(is_file($base.'/'.CoreRunnerTick::REQUEST_FILE))->toBeTrue()
            ->and($task->refresh()->status)->toBe('queued')
            ->and($upgrader->runs)->toBe([]);
    } finally {
        File::deleteDirectory($base);
    }
});

test('idle writes a heartbeat', function () {
    expect($this->runner->tick())->toBe('idle')
        ->and(CoreRunnerTick::freshHeartbeat($this->base))->not->toBeNull();
});

test('an invalid request is removed without a claim', function () {
    $task = $this->requests->create('upgrade', '2026.12.0', 7);
    file_put_contents($this->requestFile, json_encode(['format' => 1, 'action' => 'upgrade', 'task_uuid' => $task->uuid, 'version' => '../x', 'web_php' => PHP_VERSION, 'requested_at' => 'now']));

    expect($this->runner->tick())->toBe('invalid')
        ->and(is_file($this->requestFile))->toBeFalse()
        ->and($task->refresh()->status)->toBe('queued')
        ->and($this->upgrader->runs)->toBe([]);
});

test('an upgrade request is claimed once', function () {
    $task = $this->requests->create('upgrade', '2026.12.0', 7);

    expect($this->runner->tick())->toBe('succeeded');
    expect($this->upgrader->runs)->toHaveCount(1);
    [$version, $overwrite, $runTask, $userId, $extra] = $this->upgrader->runs[0];
    expect($version)->toBe('2026.12.0')
        ->and($overwrite)->toBeFalse()
        ->and($runTask?->uuid)->toBe($task->uuid)
        ->and($userId)->toBe(7)
        ->and($extra)->toBe([PHP_VERSION])
        ->and(is_file($this->requestFile))->toBeFalse()
        ->and($task->refresh()->status)->toBe('running')
        ->and($task->started_at)->not->toBeNull();

    expect($this->runner->tick())->toBe('idle')
        ->and($this->upgrader->runs)->toHaveCount(1);
});

test('a busy lock answers busy and keeps the request', function () {
    $task = $this->requests->create('upgrade', '2026.12.0', 7);
    $lock = CoreRunnerTick::acquireLock($this->base);
    expect($lock)->not->toBeNull();

    try {
        expect($this->runner->tick())->toBe('busy')
            ->and(is_file($this->requestFile))->toBeTrue()
            ->and($task->refresh()->status)->toBe('queued')
            ->and($this->upgrader->runs)->toBe([]);
    } finally {
        CoreRunnerTick::releaseLock($lock);
    }
});

test('a request for a finished task answers not_claimed and is removed', function () {
    $task = $this->requests->create('upgrade', '2026.12.0', 7);
    app(BackgroundTaskRecorder::class)->succeed($task);

    expect($this->runner->tick())->toBe('not_claimed')
        ->and(is_file($this->requestFile))->toBeFalse()
        ->and($task->refresh()->status)->toBe('succeeded')
        ->and($this->upgrader->runs)->toBe([]);
});

test('a failing upgrade closes the task and leaves no request', function () {
    $task = $this->requests->create('upgrade', '2026.12.0', 7);
    $this->upgrader->throw = new CoreUpgradeException('CORE_TICKET', 'The hub refused the download.');

    expect($this->runner->tick())->toBe('failed');
    $task->refresh();
    expect($task->status)->toBe('failed')
        ->and($task->message_params['code'] ?? null)->toBe('CORE_TICKET')
        ->and($task->message)->toBe('text.modules.core_update.result.failed')
        ->and(is_file($this->requestFile))->toBeFalse();

    // The next minute does not retry.
    expect($this->runner->tick())->toBe('idle')
        ->and($this->upgrader->runs)->toHaveCount(1);
});

test('an unexpected error closes the task with a translation key, never the raw error', function () {
    $task = $this->requests->create('upgrade', '2026.12.0', 7);
    $this->upgrader->throw = new RuntimeException('Disk went away at /var/www/site.');

    expect($this->runner->tick())->toBe('failed');
    $task->refresh();
    expect($task->status)->toBe('failed')
        ->and($task->message_params['code'] ?? null)->toBe('CORE_UPGRADE_FAILED')
        ->and($task->message)->toBe('text.modules.core_update.result.failed')
        ->and($task->message)->not->toContain('Disk went away')
        ->and(json_encode($task->message_params))->not->toContain('Disk went away')
        ->and(is_file($this->requestFile))->toBeFalse();
});

test('a failed restore stores the restore_failed key', function () {
    $task = $this->requests->create('restore', null, 7);
    $this->upgrader->throw = new CoreUpgradeException('CORE_NOTHING_TO_RESTORE', 'There is no interrupted core upgrade to restore.');

    expect($this->runner->tick())->toBe('failed');
    $task->refresh();
    expect($task->status)->toBe('failed')
        ->and($task->message)->toBe('text.modules.core_update.result.restore_failed')
        ->and($task->message_params['code'] ?? null)->toBe('CORE_NOTHING_TO_RESTORE');
});

test('a modified-files block stores the problem message and the files', function () {
    file_put_contents($this->base.'/app/X.php', '<?php // changed');
    $task = $this->requests->create('upgrade', '2026.12.0', 7);

    expect($this->runner->tick())->toBe('failed');
    $task->refresh();
    expect($task->message_params['code'] ?? null)->toBe('CORE_MODIFIED')
        ->and($task->message)->toBe('text.modules.core_update.result.failed')
        ->and($task->message_params['files'] ?? null)->toBe('app/X.php')
        ->and($this->upgrader->runs)->toBe([]);
});

test('admin rules block in the runner', function () {
    mkdir($this->base.'/cms/Foo', 0775, true);
    file_put_contents($this->base.'/cms/Foo/composer.json', '{}');
    $task = $this->requests->create('upgrade', '2026.12.0', 7);

    expect($this->runner->tick())->toBe('failed');
    $task->refresh();
    expect($task->status)->toBe('failed')
        ->and($task->message_params['code'] ?? null)->toBe('CORE_CUSTOM_DEPS')
        ->and($task->message)->toBe('text.modules.core_update.result.failed')
        ->and($this->upgrader->runs)->toBe([])
        ->and(is_file($this->requestFile))->toBeFalse();
});

test('a restore request runs restore and succeeds', function () {
    $task = $this->requests->create('restore', null, 7);

    expect($this->runner->tick())->toBe('succeeded');
    $task->refresh();
    expect($this->upgrader->restores)->toBe(1)
        ->and($this->upgrader->runs)->toBe([])
        ->and($task->status)->toBe('succeeded')
        ->and($task->result)->toBe(['restored' => true])
        ->and(is_file($this->requestFile))->toBeFalse();
});

test('an unfinished module install answers busy and keeps the request', function () {
    $task = $this->requests->create('upgrade', '2026.12.0', 7);
    $install = \Cms\Core\Models\ModuleInstall::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Probe', 'version' => '2026.1.0',
        'source' => \Cms\Core\Models\ModuleInstall::SOURCE_HUB, 'kind' => \Cms\Core\Models\ModuleInstall::KIND_INSTALL,
        'status' => \Cms\Core\Models\ModuleInstall::STATUS_QUEUED, 'requested_by' => 1, 'warnings' => [],
    ]);

    expect($this->runner->tick())->toBe('busy')
        ->and(is_file($this->requestFile))->toBeTrue()
        ->and($task->refresh()->status)->toBe('queued')
        ->and($this->upgrader->runs)->toBe([]);

    $install->forceFill(['status' => \Cms\Core\Models\ModuleInstall::STATUS_SUCCEEDED, 'finished_at' => now()])->save();
    expect($this->runner->tick())->toBe('succeeded')
        ->and($this->upgrader->runs)->toHaveCount(1);
});

test('a queued core upgrade and a queued install never wait on each other: the install runs first, then the upgrade', function () {
    $task = $this->requests->create('upgrade', '2026.12.0', 7);
    $install = \Cms\Core\Models\ModuleInstall::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Probe', 'version' => '2026.1.0',
        'source' => \Cms\Core\Models\ModuleInstall::SOURCE_HUB, 'kind' => \Cms\Core\Models\ModuleInstall::KIND_INSTALL,
        'status' => \Cms\Core\Models\ModuleInstall::STATUS_QUEUED, 'requested_by' => 1, 'warnings' => [],
        'step_message' => \Cms\Core\Services\Modules\Install\InstallRunDispatcher::STEP_WAITING_FOR_SCHEDULER,
    ]);
    $dispatcher = new \Cms\Core\Services\Modules\Install\InstallRunDispatcher(app(\Cms\Core\Services\Scheduler\SchedulerHeartbeat::class), $this->base);

    // The core request is only queued: the install is claimed.
    expect($dispatcher->claimNext()?->uuid)->toBe($install->uuid);

    // The runner waits for the claimed (unfinished) install and keeps the request.
    expect($this->runner->tick())->toBe('busy')
        ->and(is_file($this->requestFile))->toBeTrue()
        ->and($task->refresh()->status)->toBe('queued')
        ->and(is_file($this->base.'/'.CoreRunnerTick::LOCK_FILE) ? CoreRunnerTick::lockHeld($this->base) : false)->toBeFalse();

    $install->forceFill(['status' => \Cms\Core\Models\ModuleInstall::STATUS_SUCCEEDED, 'finished_at' => now()])->save();
    expect($this->runner->tick())->toBe('succeeded')
        ->and($this->upgrader->runs)->toHaveCount(1);
});

test('the runner checks for an unfinished install only while it holds its lock, and releases the lock after', function () {
    $this->requests->create('upgrade', '2026.12.0', 7);
    \Cms\Core\Models\ModuleInstall::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Probe', 'version' => '2026.1.0',
        'source' => \Cms\Core\Models\ModuleInstall::SOURCE_HUB, 'kind' => \Cms\Core\Models\ModuleInstall::KIND_INSTALL,
        'status' => \Cms\Core\Models\ModuleInstall::STATUS_QUEUED, 'requested_by' => 1, 'warnings' => [],
    ]);
    $lockDuringCheck = [];
    \Illuminate\Support\Facades\DB::listen(function ($query) use (&$lockDuringCheck): void {
        if (str_contains($query->sql, \Cms\Core\Models\ModuleInstall::query()->getModel()->getTable())) {
            $lockDuringCheck[] = CoreRunnerTick::lockHeld($this->base);
        }
    });

    expect($this->runner->tick())->toBe('busy')
        ->and($lockDuringCheck)->not->toBe([])
        ->and(array_unique($lockDuringCheck))->toBe([true])
        ->and(CoreRunnerTick::lockHeld($this->base))->toBeFalse();
});

/** Backup gate whose ensure() returns a stored backup row, or throws when told to. */
function crrGate(bool $fail = false): BackupGate
{
    return new class($fail) extends BackupGate {
        public int $calls = 0;

        public function __construct(private bool $fail)
        {
        }

        public function ensure(string $reason, ?int $userId, ?BackgroundTask $task = null): Backup
        {
            $this->calls++;
            if ($this->fail) {
                throw new BackupRequiredException('BACKUP_DUMP_FAILED');
            }

            return Backup::query()->create(['reason' => $reason, 'status' => Backup::STATUS_SUCCEEDED, 'db_driver' => 'sqlite', 'db_name' => 'x', 'format' => 'sqlite.gz', 'started_by' => $userId]);
        }
    };
}

test('an upgrade waits for the safety backup the web side takes', function () {
    config(['core.upgrade.backup_wait_seconds' => 600]);
    $task = $this->requests->create('upgrade', '2026.12.0', 7);

    expect($this->runner->tick())->toBe('waiting_backup')
        ->and(is_file($this->requestFile))->toBeTrue()
        ->and($task->refresh()->status)->toBe('queued')
        ->and($this->upgrader->runs)->toBe([]);
});

test('the web side takes the backup, the task stays queued, and the runner reuses it', function () {
    config(['core.upgrade.backup_wait_seconds' => 600]);
    $task = $this->requests->create('upgrade', '2026.12.0', 7);
    $gate = crrGate();

    expect($this->requests->prepareBackup($gate))->toBe('prepared')
        ->and($this->requests->prepareBackup($gate))->toBe('idle')
        ->and($gate->calls)->toBe(1);
    $uuid = $task->refresh()->result[CoreUpgradeRequests::PREPARED_BACKUP_KEY];
    expect($task->status)->toBe('queued')->and($uuid)->toBeString();

    expect($this->runner->tick())->toBe('succeeded')
        ->and($this->upgrader->prepared)->toBe([$uuid])
        ->and($this->upgrader->runs)->toHaveCount(1);
});

test('a failed web-side backup fails the task and removes the request', function () {
    $task = $this->requests->create('upgrade', '2026.12.0', 7);

    expect($this->requests->prepareBackup(crrGate(true)))->toBe('failed')
        ->and($task->refresh()->status)->toBe('failed')
        ->and($task->message_params['code'] ?? null)->toBe('CORE_BACKUP_FAILED')
        ->and(is_file($this->requestFile))->toBeFalse();
    expect($this->runner->tick())->toBe('idle')->and($this->upgrader->runs)->toBe([]);
});

test('after the wait the runner takes its own backup', function () {
    config(['core.upgrade.backup_wait_seconds' => 600]);
    $task = $this->requests->create('upgrade', '2026.12.0', 7);

    expect($this->runner->tick(time() + 601))->toBe('succeeded')
        ->and($this->upgrader->prepared)->toBe([null])
        ->and($this->upgrader->runs)->toHaveCount(1);
});

test('a restore request never waits for a backup and takes none on the web side', function () {
    config(['core.upgrade.backup_wait_seconds' => 600]);
    $this->requests->create('restore', null, 7);
    $gate = crrGate();

    expect($this->requests->prepareBackup($gate))->toBe('idle')
        ->and($gate->calls)->toBe(0)
        ->and($this->runner->tick())->toBe('succeeded')
        ->and($this->upgrader->restores)->toBe(1);
});

test('the scheduler starts the web-side backup only while an upgrade request waits', function () {
    file_put_contents($this->base.'/artisan', '<?php');
    $spawned = [];
    $launcher = new \Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher($this->base, function (array $command) use (&$spawned): void {
        $spawned[] = $command;
    });
    $backupCommands = static fn (array $all): array => array_values(array_filter($all, static fn (array $c): bool => in_array(\Cms\Core\Console\Commands\CoreUpgradeBackupCommand::NAME, $c, true)));

    $launcher->tick();
    expect($backupCommands($spawned))->toBe([]);

    $this->requests->create('upgrade', '2026.12.0', 7);
    $launcher->tick();
    expect($backupCommands($spawned))->toHaveCount(1)
        ->and($backupCommands($spawned)[0][1])->toBe($this->base.'/artisan');
});

test('simple hosting: a group-writable code folder of the web user is tightened, then it can be the runner', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('root never runs the upgrade');
    }
    chmod($this->base, 0775);

    expect(CoreRunnerTick::selfRunner($this->base))->toBeFalse()
        ->and(CoreRunnerTick::tightenBase($this->base))->toBeTrue();
    clearstatcache();
    expect(fileperms($this->base) & 0777)->toBe(0755)
        ->and(CoreRunnerTick::tightenBase($this->base))->toBeFalse()
        ->and(CoreRunnerTick::selfRunner($this->base))->toBeTrue();
});

test('the code folder of another user is never touched and cannot be self-run', function () {
    chmod($this->base, 0775);
    $other = posix_geteuid() + 4242;

    expect(CoreRunnerTick::tightenBase($this->base, $other))->toBeFalse()
        ->and(CoreRunnerTick::selfRunner($this->base, $other))->toBeFalse();
    clearstatcache();
    expect(fileperms($this->base) & 0777)->toBe(0775);
});

test('the web-side backup hands over to the runner at once', function () {
    file_put_contents($this->base.'/artisan', '<?php');
    file_put_contents($this->base.'/ispa', '<?php');
    $spawned = [];
    app()->instance(\Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher::class, new \Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher($this->base, function (array $command) use (&$spawned): void {
        $spawned[] = $command;
    }));
    app()->instance(CoreUpgradeRequests::class, $this->requests);
    app()->instance(BackupGate::class, crrGate());
    $this->requests->create('upgrade', '2026.12.0', 7);

    $this->artisan(\Cms\Core\Console\Commands\CoreUpgradeBackupCommand::NAME)->assertExitCode(0);

    $runner = array_values(array_filter($spawned, static fn (array $c): bool => in_array('--run-requested', $c, true)));
    expect($runner)->toHaveCount(1)->and($runner[0][1])->toBe($this->base.'/ispa');
});
