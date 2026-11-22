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
use Illuminate\Support\Facades\File;

/** Upgrader on a temp base that records run() and restore() and never swaps files. */
final class CrrFakeUpgrader extends CoreUpgrader
{
    /** @var list<array<int, mixed>> */
    public array $runs = [];

    public int $restores = 0;

    public ?Throwable $throw = null;

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
