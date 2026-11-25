<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Console\Commands\ModuleInstallRunPendingCommand;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\InstallRunDispatcher;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Scheduler\SchedulerHeartbeat;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Spec 2026-11-22 decision 5: without a queue worker (queue.default = sync) installs run through the scheduler.
 */

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();
    URL::forceScheme('https');

    $this->tmp = sys_get_temp_dir().'/scheduled-install-'.bin2hex(random_bytes(6));
    mkdir($this->tmp.'/release', 0755, true);
    mkdir($this->tmp.'/runtime', 0755, true);
    RuntimeModules::setPath($this->tmp.'/runtime');

    $this->secret = 'test-secret-'.bin2hex(random_bytes(8));
    config([
        'ispa-cms.cms_domain' => 'site.example.test',
        'ispa-cms.cms_domain_secret' => $this->secret,
        'ispa-cms.platform_hub_url' => 'https://hub.test',
        'core.module_packages.install_enabled' => true,
        'core.module_packages.trusted_keys' => ['0123456789abcdef' => base64_encode(str_repeat('k', 32))],
        'core.module_packages.revoked_key_ids' => [],
        'core.module_packages.job_timeout' => 2700,
        'core.scheduler.heartbeat_path' => $this->tmp.'/heartbeat',
        'modules.activator' => 'database',
        'queue.default' => 'sync',
    ]);

    $this->app->instance(InstallPreflight::class, new InstallPreflight(
        new ModuleCatalog(null, $this->tmp.'/release'),
        '8.3.0',
        fn (string $e): bool => true,
        fn (): array => [],
        fn (): array => [],
        fn (): array => [],
    ));

    $payload = ['modules' => [['name' => 'Probe', 'latest_version' => '2026.1.0', 'versions' => [
        ['version' => '2026.1.0', 'revoked' => false, 'requires' => [], 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.1.0'],
    ]]]];
    $secret = $this->secret;
    Http::fake(function (ClientRequest $sent) use ($secret, $payload) {
        $body = json_encode($payload);
        $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

        return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, 200, $nonce, $body)]);
    });
});

afterEach(function () {
    RuntimeModules::resetPath();
    sirRemoveTree($this->tmp);
});

function sirRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (@scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            sirRemoveTree($path.'/'.$entry);
        }
    }
    @rmdir($path);
}

function sirWebmaster(): User
{
    $user = User::query()->create(['name' => 'Install Admin', 'email' => 'sir-'.uniqid().'@test.local', 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster '.uniqid(), 'custom' => json_encode(['level' => 0])]);
    DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);

    return $user->fresh();
}

function sirPendingRow(): ModuleInstall
{
    return ModuleInstall::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Probe',
        'version' => '2026.1.0',
        'source' => ModuleInstall::SOURCE_HUB,
        'kind' => ModuleInstall::KIND_INSTALL,
        'status' => ModuleInstall::STATUS_QUEUED,
        'step_message' => InstallRunDispatcher::STEP_WAITING_FOR_SCHEDULER,
        'requested_by' => 1,
        'warnings' => [],
    ]);
}

test('without a scheduler heartbeat a sync site is refused before anything is recorded or downloaded', function () {
    Sanctum::actingAs(sirWebmaster());

    $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', InstallPreflight::NO_RUNNER);

    expect(ModuleInstall::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    // No download ticket and no package download was asked for.
    Http::assertNotSent(fn (ClientRequest $sent): bool => str_contains($sent->url(), 'download'));
});

test('with a fresh heartbeat the install is left pending and the scheduled command runs it once', function () {
    app(SchedulerHeartbeat::class)->beat();
    Sanctum::actingAs(sirWebmaster());

    $uuid = $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', ModuleInstall::STATUS_QUEUED)
        ->json('data.uuid');

    Queue::assertNothingPushed();
    $row = ModuleInstall::query()->where('uuid', $uuid)->firstOrFail();
    expect($row->step_message)->toBe(InstallRunDispatcher::STEP_WAITING_FOR_SCHEDULER)
        ->and($row->started_at)->toBeNull();

    $this->mock(ModuleInstaller::class, function ($mock) use ($uuid): void {
        $mock->shouldReceive('run')->once()->withArgs(fn (ModuleInstall $install): bool => $install->uuid === $uuid)
            ->andReturnUsing(function (ModuleInstall $install): void {
                $install->forceFill(['status' => ModuleInstall::STATUS_SUCCEEDED, 'finished_at' => now()])->save();
            });
    });

    $this->artisan(ModuleInstallRunPendingCommand::NAME)->assertExitCode(0);
    $this->artisan(ModuleInstallRunPendingCommand::NAME)->assertExitCode(0);

    $row->refresh();
    expect($row->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($row->started_at)->not->toBeNull();
});

test('two concurrent claims of the same pending run: only one gets it', function () {
    $row = sirPendingRow();
    $first = app(InstallRunDispatcher::class);
    $second = app(InstallRunDispatcher::class);

    // Both ticks read the same pending row before either claims it.
    $seenByFirst = ModuleInstall::query()->whereKey($row->getKey())->firstOrFail();
    $seenBySecond = ModuleInstall::query()->whereKey($row->getKey())->firstOrFail();

    $claimedByFirst = $first->claim($seenByFirst);
    $claimedBySecond = $second->claim($seenBySecond);

    expect($claimedByFirst)->not->toBeNull()
        ->and($claimedByFirst->uuid)->toBe($row->uuid)
        ->and($claimedBySecond)->toBeNull()
        ->and($second->claimNext())->toBeNull();
});

test('on a real queue the command only closes rows left for the scheduler; rows queued for a worker stay', function () {
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 3000]);
    $left = sirPendingRow();
    $forWorker = sirPendingRow();
    $forWorker->forceFill(['step_message' => 'Queued.'])->save();
    $this->mock(ModuleInstaller::class, function ($mock): void {
        $mock->shouldNotReceive('run');
        $mock->shouldReceive('markFailed')->once()->andReturnUsing(function (ModuleInstall $install, string $code): void {
            $install->forceFill(['status' => ModuleInstall::STATUS_FAILED, 'error_code' => $code, 'finished_at' => now()])->save();
        });
        $mock->shouldReceive('cleanStaging')->once();
    });

    $this->artisan(ModuleInstallRunPendingCommand::NAME)->assertExitCode(0);

    $left->refresh();
    expect($left->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($left->error_code)->toBe(InstallRunDispatcher::ERROR_NOT_PICKED_UP)
        ->and($left->step_message)->toBe(InstallRunDispatcher::STEP_NOT_PICKED_UP)
        ->and($forWorker->refresh()->status)->toBe(ModuleInstall::STATUS_QUEUED)
        ->and($forWorker->started_at)->toBeNull();
});

test('a row left for the scheduler and never claimed stops blocking installs after 15 minutes', function () {
    $fresh = sirPendingRow();
    $dispatcher = app(InstallRunDispatcher::class);

    expect($dispatcher->hasUnfinished())->toBeTrue()
        ->and($fresh->refresh()->status)->toBe(ModuleInstall::STATUS_QUEUED);

    ModuleInstall::query()->whereKey($fresh->getKey())->update(['updated_at' => now()->subSeconds(InstallRunDispatcher::UNCLAIMED_EXPIRE_SECONDS + 1)]);

    expect($dispatcher->hasUnfinished())->toBeFalse();
    $fresh->refresh();
    expect($fresh->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($fresh->error_code)->toBe(InstallRunDispatcher::ERROR_NOT_PICKED_UP)
        ->and($fresh->finished_at)->not->toBeNull();
});

test('a claim whose process died is closed as failed on the next tick', function () {
    $row = sirPendingRow();
    $row->forceFill(['started_at' => now()->subSeconds(InstallRunDispatcher::staleClaimSeconds() + 1)])->save();
    $this->mock(ModuleInstaller::class, function ($mock): void {
        $mock->shouldReceive('isUnfinished')->never();
        $mock->shouldReceive('markFailed')->once()->withArgs(fn (ModuleInstall $i, string $code): bool => $code === 'MODULE_INSTALL_JOB_FAILED')
            ->andReturnUsing(function (ModuleInstall $install): void {
                $install->forceFill(['status' => ModuleInstall::STATUS_FAILED, 'finished_at' => now()])->save();
            });
        $mock->shouldReceive('cleanStaging')->once();
        $mock->shouldNotReceive('run');
    });

    $this->artisan(ModuleInstallRunPendingCommand::NAME)->assertExitCode(0);

    expect($row->refresh()->status)->toBe(ModuleInstall::STATUS_FAILED);
});

test('the heartbeat is written by the schedule every minute and read back as fresh', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
    $heartbeat = $events->first(fn ($e) => $e->description === 'core:scheduler-heartbeat');
    $runner = $events->first(fn ($e) => $e->description === ModuleInstallRunPendingCommand::NAME);

    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->expression)->toBe('* * * * *')
        ->and($runner)->not->toBeNull()
        ->and($runner->expression)->toBe('* * * * *')
        ->and($runner->withoutOverlapping)->toBeTrue()
        ->and($runner->runInBackground)->toBeTrue();

    expect(app(SchedulerHeartbeat::class)->isFresh())->toBeFalse();
    $heartbeat->run($this->app);
    expect(app(SchedulerHeartbeat::class)->isFresh())->toBeTrue();
});

test('a claim younger than the overlap lock is never stale; the stale limit outlives the lock', function () {
    $runner = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => $e->description === ModuleInstallRunPendingCommand::NAME);
    $lockSeconds = $runner->expiresAt * 60;

    expect($runner->expiresAt)->toBe(InstallRunDispatcher::lockMinutes())
        ->and(InstallRunDispatcher::staleClaimSeconds())->toBeGreaterThan($lockSeconds);

    $row = sirPendingRow();
    $row->forceFill(['started_at' => now()->subSeconds($lockSeconds)])->save();
    expect(app(InstallRunDispatcher::class)->staleClaims())->toBe([]);
});

test('with a real queue only the in-process heartbeat is scheduled, not the runner process', function () {
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 3000]);
    $this->app->forgetInstance(\Illuminate\Console\Scheduling\Schedule::class);
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

    $heartbeat = $events->first(fn ($e) => $e->description === 'core:scheduler-heartbeat');
    expect($heartbeat)->toBeInstanceOf(\Illuminate\Console\Scheduling\CallbackEvent::class)
        ->and($events->first(fn ($e) => $e->description === ModuleInstallRunPendingCommand::NAME))->toBeNull();
});

test('a pending run is not claimed while the core runner holds its lock or a core upgrade runs', function () {
    $coreBase = $this->tmp.'/core';
    mkdir($coreBase, 0755, true);
    $runs = new InstallRunDispatcher(app(SchedulerHeartbeat::class), $coreBase);
    $row = sirPendingRow();

    $lock = \Cms\Core\Services\CoreUpgrade\CoreRunnerTick::acquireLock($coreBase);
    try {
        expect($runs->claimNext())->toBeNull();
    } finally {
        \Cms\Core\Services\CoreUpgrade\CoreRunnerTick::releaseLock($lock);
    }

    $task = app(\Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder::class)->start('core.upgrade', 'Core', 1);
    app(\Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder::class)->running($task);
    expect($runs->claimNext())->toBeNull()
        ->and($row->refresh()->started_at)->toBeNull();

    app(\Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder::class)->succeed($task);
    expect($runs->claimNext()?->uuid)->toBe($row->uuid);
});

/*
 * Fix 2026-11-25: a queue whose retry_after is not longer than the install job timeout would
 * redeliver a long install while it still runs, so installs run through the scheduler there too.
 */

test('one rule decides the runner: sync or a short retry_after uses the scheduler, a long retry_after the queue', function (string $default, ?int $retryAfter, bool $scheduler) {
    config(['queue.default' => $default, "queue.connections.{$default}.retry_after" => $retryAfter]);

    expect(app(InstallRunDispatcher::class)->usesScheduler())->toBe($scheduler);
})->with([
    'sync' => ['sync', null, true],
    'redis default retry_after 900' => ['redis', 900, true],
    'redis retry_after equal to the job timeout' => ['redis', 2700, true],
    'database default retry_after 90' => ['database', 90, true],
    'redis retry_after 3000' => ['redis', 3000, false],
    'redis without retry_after' => ['redis', null, false],
]);

test('redis with retry_after 900 registers the scheduled runner', function () {
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 900]);
    $this->app->forgetInstance(\Illuminate\Console\Scheduling\Schedule::class);
    $runner = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => $e->description === ModuleInstallRunPendingCommand::NAME);

    expect($runner)->not->toBeNull()
        ->and($runner->expression)->toBe('* * * * *')
        ->and($runner->withoutOverlapping)->toBeTrue()
        ->and($runner->runInBackground)->toBeTrue();
});

test('redis with retry_after 900: the install is left for the scheduler and the command claims and runs it', function () {
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 900]);
    app(SchedulerHeartbeat::class)->beat();
    Sanctum::actingAs(sirWebmaster());

    $uuid = $this->postJson('/api/v2/admin/utilities/modules/install', ['name' => 'Probe', 'version' => '2026.1.0'])
        ->assertStatus(202)
        ->json('data.uuid');

    Queue::assertNothingPushed();
    $row = ModuleInstall::query()->where('uuid', $uuid)->firstOrFail();
    expect($row->step_message)->toBe(InstallRunDispatcher::STEP_WAITING_FOR_SCHEDULER);

    $this->mock(ModuleInstaller::class, function ($mock) use ($uuid): void {
        $mock->shouldReceive('run')->once()->withArgs(fn (ModuleInstall $install): bool => $install->uuid === $uuid)
            ->andReturnUsing(function (ModuleInstall $install): void {
                $install->forceFill(['status' => ModuleInstall::STATUS_SUCCEEDED, 'finished_at' => now()])->save();
            });
    });

    $this->artisan(ModuleInstallRunPendingCommand::NAME)->assertExitCode(0);

    $row->refresh();
    expect($row->status)->toBe(ModuleInstall::STATUS_SUCCEEDED)
        ->and($row->started_at)->not->toBeNull();
});
