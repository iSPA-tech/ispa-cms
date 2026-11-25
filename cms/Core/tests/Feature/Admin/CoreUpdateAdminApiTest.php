<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\CoreUpgrade\CoreManifest;
use Cms\Core\Services\CoreUpgrade\CoreRunnerTick;
use Cms\Core\Services\CoreUpgrade\CoreUpdateChecker;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeException;
use Cms\Core\Services\CoreUpgrade\CoreUpgrader;
use Cms\Core\Services\CoreUpgrade\CoreUpgradePreflight;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Cms\Core\Services\Modules\Install\PreflightResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

/** Preflight that answers a fixed result; only the interrupted check reads the temp base (state file, runner lock). */
final class CuFakePreflight extends CoreUpgradePreflight
{
    /** @var list<array{code: string, message: string, details: array<string, mixed>}> */
    public array $blocking = [];

    public array $calls = [];

    public function __construct(string $base)
    {
        parent::__construct($base);
    }

    public function check(CoreManifest $installed, ?string $targetVersion, bool $forAdmin, bool $overwriteModified = false, ?int $packageBytes = null, ?CoreManifest $new = null, array $extraPhpVersions = []): PreflightResult
    {
        $this->calls[] = [$targetVersion, $forAdmin];

        return new PreflightResult(CoreManifest::FILE, $targetVersion ?? '', $this->blocking, [], [], 'upgrade', $installed->version);
    }
}

/** Upgrader that records run() and never swaps files. */
final class CuFakeUpgrader extends CoreUpgrader
{
    public ?CoreManifest $manifest;

    public array $runs = [];

    public ?CoreUpgradeException $throw = null;

    public function __construct(?CoreManifest $manifest)
    {
        $this->manifest = $manifest;
    }

    public function installed(): CoreManifest
    {
        return $this->manifest ?? throw new CoreUpgradeException('CORE_NO_MANIFEST', 'no manifest');
    }

    public function run(string $targetVersion, bool $overwriteModified, ?BackgroundTask $task = null, ?int $userId = null, array $extraPhpVersions = []): void
    {
        $this->runs[] = [$targetVersion, $overwriteModified, $task, $userId];
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}

function cuUser(string $email, bool $webmaster): User
{
    $user = User::query()->create(['name' => 'Core Admin', 'email' => $email, 'password' => Hash::make('password')]);
    $user->forceFill(['type' => UserType::ADMIN])->save();
    if ($webmaster) {
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Webmaster '.uniqid(), 'custom' => json_encode(['level' => 0])]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }

    return $user->fresh();
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::flush();
    URL::forceScheme('https');
    $this->base = sys_get_temp_dir().'/cu-api-'.bin2hex(random_bytes(6));
    mkdir($this->base, 0775, true);
    $this->app->instance(CoreUpgradeRequests::class, new CoreUpgradeRequests(app(BackgroundTaskRecorder::class), $this->base));
    $this->preflight = new CuFakePreflight($this->base);
    $this->upgrader = new CuFakeUpgrader(new CoreManifest('2026.11.0', '8.1', [], [], ''));
    $this->app->instance(CoreUpgradePreflight::class, $this->preflight);
    $this->app->instance(CoreUpgrader::class, $this->upgrader);
    Cache::put(CoreUpdateChecker::CACHE_KEY, ['version' => '2026.12.0', 'notes_url' => 'https://example.test/notes'], 3600);
    $this->url = '/api/v2/admin/core-update';
    $this->requestFile = $this->base.'/'.CoreRunnerTick::REQUEST_FILE;
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

function cuInterrupt(string $base): void
{
    $path = $base.'/'.CoreUpgradePreflight::STATE_FILE;
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, '{}');
}

test('GET reports installed, latest, preflight and can_update', function () {
    Sanctum::actingAs(cuUser('cu-web@test.local', true));

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.installed', '2026.11.0')
        ->assertJsonPath('data.latest', '2026.12.0')
        ->assertJsonPath('data.notes_url', 'https://example.test/notes')
        ->assertJsonPath('data.can_update', true)
        ->assertJsonPath('data.preflight.ok', true)
        ->assertJsonPath('data.preflight.issues', [])
        ->assertJsonPath('data.running', null)
        ->assertJsonPath('data.command', './ispa upgrade')
        ->assertJsonPath('data.managed', false);

    expect($this->preflight->calls[0])->toBe(['2026.12.0', true]);
});

test('a site on a legacy pre-CalVer core number is offered the CalVer core upgrade', function () {
    Sanctum::actingAs(cuUser('cu-web-legacy@test.local', true));
    // 2026.16.2 predates CalVer (month above 12): it is older than every CalVer release, never newer.
    $this->upgrader->manifest = new CoreManifest('2026.16.2', '8.1', [], [], '');
    Cache::put(CoreUpdateChecker::CACHE_KEY, ['version' => '2026.11.07', 'notes_url' => null], 3600);

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.installed', '2026.16.2')
        ->assertJsonPath('data.latest', '2026.11.07')
        ->assertJsonPath('data.can_update', true);
});

test('GET cannot update when preflight fails, nothing newer, or an upgrade runs', function () {
    Sanctum::actingAs(cuUser('cu-web2@test.local', true));

    $this->preflight->blocking = [['code' => 'CORE_MODIFIED', 'message' => 'Changed.', 'details' => ['files' => ['a.php']]]];
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.preflight.ok', false)
        ->assertJsonPath('data.preflight.issues.0.code', 'CORE_MODIFIED')
        ->assertJsonPath('data.preflight.issues.0.files', ['a.php']);

    $this->preflight->blocking = [];
    Cache::put(CoreUpdateChecker::CACHE_KEY, ['version' => '2026.11.0', 'notes_url' => null], 3600);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.can_update', false);

    Cache::put(CoreUpdateChecker::CACHE_KEY, ['version' => '2026.12.0', 'notes_url' => null], 3600);
    $task = app(BackgroundTaskRecorder::class)->start('core.upgrade', 'Core', 1);
    app(BackgroundTaskRecorder::class)->progress($task, 40, 'Working.');
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.running.uuid', $task->uuid)
        ->assertJsonPath('data.running.progress', 40)
        ->assertJsonPath('data.running.status', 'running');
});

test('GET without a manifest reports CORE_NO_MANIFEST and the configured version', function () {
    $this->upgrader->manifest = null;
    config(['ispa-cms.app_version' => '2026.10.5']);
    Sanctum::actingAs(cuUser('cu-web3@test.local', true));

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.installed', '2026.10.5')
        ->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.preflight.ok', false)
        ->assertJsonPath('data.preflight.issues.0.code', 'CORE_NO_MANIFEST');
});

test('GET reports managed without a manifest', function () {
    $this->upgrader->manifest = null;
    Sanctum::actingAs(cuUser('cu-web-managed@test.local', true));

    // A site deployed by iSPA (no core-manifest.json): the card shows only the managed sentence.
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.managed', true)
        ->assertJsonPath('data.can_update', false);
});

test('POST writes the request file and a queued task, answering 202 with the task uuid only', function () {
    $user = cuUser('cu-web4@test.local', true);
    Sanctum::actingAs($user);

    $response = $this->postJson($this->url, ['version' => '2026.12.0', 'confirm_backup' => true])
        ->assertStatus(202)
        ->assertJsonStructure(['data' => ['background_task_uuid']]);

    $task = BackgroundTask::query()->where('uuid', $response->json('data.background_task_uuid'))->firstOrFail();
    expect($task->type)->toBe('core.upgrade')->and($task->status)->toBe('queued')->and((int) $task->started_by)->toBe((int) $user->id);
    $request = json_decode((string) file_get_contents($this->requestFile), true);
    expect($request['action'])->toBe('upgrade')
        ->and($request['task_uuid'])->toBe($task->uuid)
        ->and($request['version'])->toBe('2026.12.0')
        ->and($request['web_php'])->toBe(PHP_VERSION);
});

test('POST is 422 without the backup confirmation or with a bad version', function () {
    Sanctum::actingAs(cuUser('cu-web5@test.local', true));

    $this->postJson($this->url, ['version' => '2026.12.0'])->assertStatus(422);
    $this->postJson($this->url, ['version' => '2026.12.0', 'confirm_backup' => false])->assertStatus(422);
    $this->postJson($this->url, ['version' => '../x', 'confirm_backup' => true])->assertStatus(422);
    $this->postJson($this->url, ['confirm_backup' => true])->assertStatus(422);

    expect(BackgroundTask::query()->count())->toBe(0);
    expect(is_file($this->requestFile))->toBeFalse();
});

test('POST is 409 with the issues when preflight fails', function () {
    $this->preflight->blocking = [['code' => 'CORE_CUSTOM_DEPS', 'message' => 'Own deps.', 'details' => ['cli_only' => true]]];
    Sanctum::actingAs(cuUser('cu-web6@test.local', true));

    $this->postJson($this->url, ['version' => '2026.12.0', 'confirm_backup' => true])
        ->assertStatus(409)
        ->assertJsonPath('data.preflight.ok', false)
        ->assertJsonPath('data.preflight.issues.0.code', 'CORE_CUSTOM_DEPS');

    expect(BackgroundTask::query()->count())->toBe(0);
    expect(is_file($this->requestFile))->toBeFalse();
});

test('POST is 409 while another core upgrade is unfinished', function () {
    Sanctum::actingAs(cuUser('cu-web7@test.local', true));
    app(BackgroundTaskRecorder::class)->start('core.upgrade', 'Core', 1);

    $this->postJson($this->url, ['version' => '2026.12.0', 'confirm_backup' => true])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'CORE_UPGRADE_RUNNING');

    expect(BackgroundTask::query()->count())->toBe(1);
    expect(is_file($this->requestFile))->toBeFalse();
});

test('a non-Webmaster admin gets 403 on both routes', function () {
    cuUser('cu-filler@test.local', false); // user id 1 is always a Webmaster
    Sanctum::actingAs(cuUser('cu-editor@test.local', false));

    $this->getJson($this->url)->assertForbidden();
    $this->postJson($this->url, ['version' => '2026.12.0', 'confirm_backup' => true])->assertForbidden();

    expect(BackgroundTask::query()->count())->toBe(0);
    expect(is_file($this->requestFile))->toBeFalse();
});

test('starting an upgrade writes an audit entry', function () {
    Event::fake([AuditLogEvent::class]);
    Sanctum::actingAs(cuUser('cu-web9@test.local', true));

    $this->postJson($this->url, ['version' => '2026.12.0', 'confirm_backup' => true])->assertStatus(202);

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e) => $e->action === 'core.upgrade_started');
});

test('GET reports the runner and the setup line', function () {
    Sanctum::actingAs(cuUser('cu-web10@test.local', true));

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.runner', null)
        ->assertJsonPath('data.runner_setup.line', '* * * * * cd '.escapeshellarg($this->base).' && php ispa upgrade --run-requested >/dev/null 2>&1')
        ->assertJsonPath('data.can_restore', false);

    (new CoreRunnerTick($this->base))->writeHeartbeat();
    $response = $this->getJson($this->url)->assertOk();
    expect($response->json('data.runner.user'))->not->toBeEmpty()
        ->and($response->json('data.runner.seen_at'))->toBeString()
        ->and($response->json('data.runner_setup.user'))->not->toBeEmpty();
});

test('a queued request older than 15 minutes times out', function () {
    Sanctum::actingAs(cuUser('cu-web11@test.local', true));
    $uuid = $this->postJson($this->url, ['version' => '2026.12.0', 'confirm_backup' => true])->assertStatus(202)->json('data.background_task_uuid');
    expect(is_file($this->requestFile))->toBeTrue();

    $this->travel(899)->seconds();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.running.status', 'queued');

    $this->travel(2)->seconds();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.running', null);

    $task = BackgroundTask::query()->where('uuid', $uuid)->firstOrFail();
    expect($task->status)->toBe('failed')
        ->and($task->message_params['code'] ?? null)->toBe('CORE_RUNNER_TIMEOUT')
        ->and($task->message)->toBe('text.modules.core_update.result.CORE_RUNNER_TIMEOUT')
        ->and(is_file($this->requestFile))->toBeFalse();
});

test('a task the runner claims at the 901 s mark is not failed by the timeout', function () {
    Sanctum::actingAs(cuUser('cu-web11b@test.local', true));
    $uuid = $this->postJson($this->url, ['version' => '2026.12.0', 'confirm_backup' => true])->assertStatus(202)->json('data.background_task_uuid');
    $this->travel(901)->seconds();

    // The runner claims the task right after the timeout check has read it as queued.
    $claimed = false;
    BackgroundTask::retrieved(function (BackgroundTask $task) use ($uuid, &$claimed): void {
        if ($claimed || $task->uuid !== $uuid) {
            return;
        }
        $claimed = true;
        BackgroundTask::query()->where('uuid', $uuid)->update(['status' => 'running', 'started_at' => now()]);
    });

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.running.status', 'running');

    expect($claimed)->toBeTrue()
        ->and(BackgroundTask::query()->where('uuid', $uuid)->value('status'))->toBe('running')
        ->and(is_file($this->requestFile))->toBeTrue();
});

test('a running task with the lock free and 2 quiet minutes is closed as CORE_RUNNER_DIED', function () {
    Sanctum::actingAs(cuUser('cu-web12@test.local', true));
    $recorder = app(BackgroundTaskRecorder::class);
    $task = $recorder->running($recorder->start('core.upgrade', 'Core', 1));

    $this->travel(119)->seconds();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.running.status', 'running');

    $this->travel(2)->seconds();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.running', null);

    $task->refresh();
    expect($task->status)->toBe('failed')->and($task->message_params['code'] ?? null)->toBe('CORE_RUNNER_DIED')
        ->and($task->message)->toBe('text.modules.core_update.result.CORE_RUNNER_DIED');
});

test('a task that succeeds right after the dead-runner check read it is never failed', function () {
    Sanctum::actingAs(cuUser('cu-web12b@test.local', true));
    $recorder = app(BackgroundTaskRecorder::class);
    $task = $recorder->running($recorder->start('core.upgrade', 'Core', 1));
    $this->travel(121)->seconds();

    // The runner finishes the task right after closeAbandoned() has read it as quiet.
    $finished = false;
    BackgroundTask::retrieved(function (BackgroundTask $read) use ($task, &$finished): void {
        if ($finished || $read->uuid !== $task->uuid) {
            return;
        }
        $finished = true;
        BackgroundTask::query()->where('uuid', $task->uuid)->update(['status' => 'succeeded', 'finished_at' => now(), 'updated_at' => now()->addSecond()]);
    });

    $this->getJson($this->url)->assertOk();

    expect($finished)->toBeTrue()
        ->and($task->refresh()->status)->toBe('succeeded')
        ->and($task->message)->toBeNull();
});

test('the same task survives while the lock is held', function () {
    Sanctum::actingAs(cuUser('cu-web13@test.local', true));
    $recorder = app(BackgroundTaskRecorder::class);
    $task = $recorder->running($recorder->start('core.upgrade', 'Core', 1));
    $lock = CoreRunnerTick::acquireLock($this->base);
    expect($lock)->not->toBeNull();

    try {
        $this->travel(600)->seconds();
        $this->getJson($this->url)->assertOk()
            ->assertJsonPath('data.running.uuid', $task->uuid)
            ->assertJsonPath('data.running.status', 'running');
    } finally {
        CoreRunnerTick::releaseLock($lock);
    }

    expect($task->refresh()->status)->toBe('running');
});

test('restore is 202 only when interrupted with a runner', function () {
    $user = cuUser('cu-web14@test.local', true);
    Sanctum::actingAs($user);
    $restore = $this->url.'/restore';

    // No state file.
    (new CoreRunnerTick($this->base))->writeHeartbeat();
    $this->postJson($restore, ['confirm' => true])->assertStatus(409)->assertJsonPath('error_code', 'CORE_NOT_INTERRUPTED');

    // No heartbeat.
    cuInterrupt($this->base);
    @unlink($this->base.'/'.CoreRunnerTick::HEARTBEAT_FILE);
    $this->postJson($restore, ['confirm' => true])->assertStatus(409)->assertJsonPath('error_code', 'CORE_NO_RUNNER');

    // No confirmation.
    (new CoreRunnerTick($this->base))->writeHeartbeat();
    $this->postJson($restore, [])->assertStatus(422)->assertJsonPath('error_code', 'CORE_CONFIRMATION_REQUIRED');
    $this->postJson($restore, ['confirm' => 'yes'])->assertStatus(422)->assertJsonPath('error_code', 'CORE_CONFIRMATION_REQUIRED');
    expect(BackgroundTask::query()->count())->toBe(0)->and(is_file($this->requestFile))->toBeFalse();

    // Interrupted, runner fresh, lock free.
    $uuid = $this->postJson($restore, ['confirm' => true])->assertStatus(202)->json('data.background_task_uuid');
    $task = BackgroundTask::query()->where('uuid', $uuid)->firstOrFail();
    $request = json_decode((string) file_get_contents($this->requestFile), true);
    expect($task->status)->toBe('queued')
        ->and((int) $task->started_by)->toBe((int) $user->id)
        ->and($request['action'])->toBe('restore')
        ->and($request['task_uuid'])->toBe($uuid)
        ->and($request['version'])->toBeNull();

    // A second request while the first waits.
    $this->postJson($restore, ['confirm' => true])->assertStatus(409)->assertJsonPath('error_code', 'CORE_UPGRADE_RUNNING');
});

test('restore is 403 for an editor', function () {
    cuUser('cu-filler2@test.local', false); // user id 1 is always a Webmaster
    Sanctum::actingAs(cuUser('cu-editor2@test.local', false));
    cuInterrupt($this->base);
    (new CoreRunnerTick($this->base))->writeHeartbeat();

    $this->postJson($this->url.'/restore', ['confirm' => true])->assertForbidden();

    expect(BackgroundTask::query()->count())->toBe(0)->and(is_file($this->requestFile))->toBeFalse();
});

test('starting a restore writes an audit entry', function () {
    Event::fake([AuditLogEvent::class]);
    Sanctum::actingAs(cuUser('cu-web15@test.local', true));
    cuInterrupt($this->base);
    (new CoreRunnerTick($this->base))->writeHeartbeat();

    $uuid = $this->postJson($this->url.'/restore', ['confirm' => true])->assertStatus(202)->json('data.background_task_uuid');

    Event::assertDispatched(AuditLogEvent::class, fn (AuditLogEvent $e) => $e->action === 'core.restore_started' && ($e->changed['task_uuid'] ?? null) === $uuid);
});

test('GET can_restore is true only when interrupted with a runner', function () {
    Sanctum::actingAs(cuUser('cu-web16@test.local', true));

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.can_restore', false);

    cuInterrupt($this->base);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.can_restore', false);

    (new CoreRunnerTick($this->base))->writeHeartbeat();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.can_restore', true);

    $lock = CoreRunnerTick::acquireLock($this->base);
    try {
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.can_restore', false);
    } finally {
        CoreRunnerTick::releaseLock($lock);
    }

    app(BackgroundTaskRecorder::class)->start('core.upgrade', 'Core', 1);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.can_restore', false);

    $this->upgrader->manifest = null;
    BackgroundTask::query()->delete();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.can_restore', false);
});

test('GET reports runner_setup.base', function () {
    Sanctum::actingAs(cuUser('cu-web-base@test.local', true));

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.runner_setup.base', $this->base)
        ->assertJsonPath('data.runner_setup.root_owner', false);
});

test('GET reports the web server account for the one-line owner fix', function () {
    Sanctum::actingAs(cuUser('cu-web-user@test.local', true));

    $expected = posix_geteuid() === 0 ? null : posix_getpwuid(posix_geteuid())['name'];
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.web_user', $expected);
});

test('a code owner of root is marked in the runner setup', function () {
    expect(CoreUpgradeRequests::setupFor('/srv/site', 0))->toMatchArray(['user' => 'root', 'base' => '/srv/site', 'root_owner' => true])
        ->and(CoreUpgradeRequests::setupFor('/srv/site', posix_geteuid())['root_owner'])->toBeFalse();
});

test('POST starts the safety backup at once, so an upgrade needs no cron, and tightens a group-writable code folder', function () {
    if (posix_geteuid() === 0) {
        $this->markTestSkipped('root never runs the upgrade');
    }
    file_put_contents($this->base.'/artisan', '<?php');
    $spawned = [];
    $this->app->instance(\Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher::class, new \Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher($this->base, function (array $command) use (&$spawned): void {
        $spawned[] = $command;
    }));
    Sanctum::actingAs(cuUser('cu-web-start@test.local', true));

    $this->postJson($this->url, ['version' => '2026.12.0', 'confirm_backup' => true])->assertStatus(202);

    $backup = array_values(array_filter($spawned, static fn (array $c): bool => in_array(\Cms\Core\Console\Commands\CoreUpgradeBackupCommand::NAME, $c, true)));
    clearstatcache();
    expect($backup)->toHaveCount(1)
        ->and(fileperms($this->base) & 0777)->toBe(0755);
});
