<?php

declare(strict_types=1);

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Jobs\Modules\InstallModuleJob;
use Cms\Core\Jobs\UpdateBatch\AdvanceUpdateBatchJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\UpdateBatch\UpdateBatches;
use Cms\Core\Services\UpdateBatch\UpdateBatchRunner;
use Cms\Core\Tests\Feature\UpdateBatch\UpdateBatchFixtures;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

// Upgrade-all spec 2026-11-26 decisions 1 and 4; plan Task 5: module items run one at a time, each with its own
// preflight at its turn; a repeat-class failure stops the run.

beforeEach(function () {
    Queue::fake();
    $version = static fn (string $number, array $requires = []): array => [
        'version' => $number, 'revoked' => false, 'requires' => $requires, 'irreversible' => false, 'custom_code' => false, 'php_min' => '8.1.0',
    ];
    $this->tmp = UpdateBatchFixtures::site($this, [
        ['name' => 'Account', 'latest_version' => '2026.11.05', 'versions' => [$version('2026.11.04'), $version('2026.11.05')]],
        ['name' => 'SocialAuth', 'latest_version' => '2026.11.06', 'versions' => [$version('2026.11.01'), $version('2026.11.06', ['Account' => '>=2026.11.05'])]],
        ['name' => 'Webmail', 'latest_version' => '2026.11.03', 'versions' => [$version('2026.11.02'), $version('2026.11.03')]],
    ]);
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 3000]);
    $this->user = UpdateBatchFixtures::webmaster();
});

afterEach(function () {
    UpdateBatchFixtures::tearDown($this->tmp);
});

function urmItem(string $kind, string $key, ?string $from = null, ?string $to = null): array
{
    return ['kind' => $kind, 'key' => $key, 'label' => $key, 'from' => $from, 'to' => $to, 'state' => 'pending', 'reason_code' => null,
        'install_uuid' => null, 'widget_id' => null, 'pages' => null, 'project_id' => null, 'setup_url' => null];
}

function urmBatch(object $test, array $items, bool $backup = true): BackgroundTask
{
    return app(UpdateBatches::class)->start($test->user, ['items' => $items, 'left_out' => []], ['module', 'widget', 'workflow'], $backup);
}

function urmItems(BackgroundTask $batch): array
{
    return $batch->fresh()->result['items'];
}

function urmFinishRow(string $status, ?string $code = null): ModuleInstall
{
    $row = ModuleInstall::query()->orderByDesc('id')->firstOrFail();
    $row->forceFill(['status' => $status, 'error_code' => $code, 'finished_at' => now()])->save();

    return $row;
}

it('turns the first module item into an install row', function (): void {
    $batch = urmBatch($this, [urmItem('module', 'Account', '2026.11.04', '2026.11.05'), urmItem('module', 'Webmail', '2026.11.02', '2026.11.03')], false);

    app(UpdateBatchRunner::class)->advance();

    $row = ModuleInstall::query()->sole();
    expect($row->name)->toBe('Account')
        ->and($row->kind)->toBe(ModuleInstall::KIND_UPGRADE)
        ->and((bool) $row->backup_skipped)->toBeTrue()
        ->and(urmItems($batch)[0])->toMatchArray(['state' => 'running', 'install_uuid' => $row->uuid])
        ->and(urmItems($batch)[1]['state'])->toBe('pending');
    Queue::assertPushed(InstallModuleJob::class);
});

it('waits while the install row is unfinished', function (): void {
    urmBatch($this, [urmItem('module', 'Account', '2026.11.04', '2026.11.05'), urmItem('module', 'Webmail', '2026.11.02', '2026.11.03')]);

    app(UpdateBatchRunner::class)->advance();
    app(UpdateBatchRunner::class)->advance();

    expect(ModuleInstall::query()->count())->toBe(1);
});

it('records one row when two advances meet', function (): void {
    urmBatch($this, [urmItem('module', 'Account', '2026.11.04', '2026.11.05'), urmItem('module', 'Webmail', '2026.11.02', '2026.11.03')]);
    $held = Cache::lock(UpdateBatchRunner::LOCK_NAME, 60);
    $held->get();

    app(UpdateBatchRunner::class)->advance();
    expect(ModuleInstall::query()->count())->toBe(0);

    $held->release();
    app(UpdateBatchRunner::class)->advance();
    app(UpdateBatchRunner::class)->advance();
    expect(ModuleInstall::query()->count())->toBe(1);
});

it('moves to the next module with a fresh preflight once the row succeeded', function (): void {
    $batch = urmBatch($this, [urmItem('module', 'Account', '2026.11.04', '2026.11.05'), urmItem('module', 'Webmail', '2026.11.02', '2026.11.03')]);
    app(UpdateBatchRunner::class)->advance();
    urmFinishRow(ModuleInstall::STATUS_SUCCEEDED);
    UpdateBatchFixtures::module($this->tmp . '/runtime/Account', '2026.11.05');

    app(UpdateBatchRunner::class)->advance();

    $items = urmItems($batch);
    expect($items[0]['state'])->toBe('done')
        ->and($items[1]['state'])->toBe('running')
        ->and(ModuleInstall::query()->where('name', 'Webmail')->exists())->toBeTrue();
});

it('skips a module its preflight blocks and goes on with the next one', function (): void {
    $batch = urmBatch($this, [urmItem('module', 'SocialAuth', '2026.11.01', '2026.11.06'), urmItem('module', 'Webmail', '2026.11.02', '2026.11.03')]);

    app(UpdateBatchRunner::class)->advance();

    $items = urmItems($batch);
    expect($items[0])->toMatchArray(['state' => 'skipped', 'reason_code' => 'MODULE_REQUIRES_UNMET'])
        ->and($items[1]['state'])->toBe('running')
        ->and(ModuleInstall::query()->sole()->name)->toBe('Webmail');
});

it('stops the run when a module run died', function (): void {
    $batch = urmBatch($this, [urmItem('module', 'Account', '2026.11.04', '2026.11.05'), urmItem('module', 'Webmail', '2026.11.02', '2026.11.03')]);
    app(UpdateBatchRunner::class)->advance();
    urmFinishRow(ModuleInstall::STATUS_FAILED, 'MODULE_INSTALL_JOB_FAILED');

    app(UpdateBatchRunner::class)->advance();

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe(BackgroundTask::STATUS_FAILED)
        ->and($fresh->result['stopped_reason'])->toBe('MODULE_INSTALL_JOB_FAILED')
        ->and(array_column($fresh->result['items'], 'state'))->toBe(['failed', 'not_started'])
        ->and(app(UpdateBatches::class)->open())->toBeNull();
});

it('finishes the run after the last item', function (): void {
    Event::fake([AuditLogEvent::class]);
    $batch = urmBatch($this, [urmItem('module', 'Account', '2026.11.04', '2026.11.05')]);
    app(UpdateBatchRunner::class)->advance();
    urmFinishRow(ModuleInstall::STATUS_SUCCEEDED);

    app(UpdateBatchRunner::class)->advance();

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)
        ->and($fresh->result['stopped_reason'])->toBeNull()
        ->and($fresh->result['items'][0]['state'])->toBe('done');
    Event::assertDispatched(AuditLogEvent::class, static fn (AuditLogEvent $e): bool => $e->action === 'update.batch_finished');
});

it('kicks the run when an install job ends on a queue site', function (): void {
    urmBatch($this, [urmItem('module', 'Account', '2026.11.04', '2026.11.05')]);
    app(UpdateBatchRunner::class)->advance();
    $row = urmFinishRow(ModuleInstall::STATUS_SUCCEEDED);

    (new InstallModuleJob($row->uuid))->handle(app(ModuleInstaller::class));

    Queue::assertPushed(AdvanceUpdateBatchJob::class);
});

it('leaves widget items to a process of their own', function (): void {
    $batch = urmBatch($this, [urmItem('widget', 'ispa.news', '2026.11.01', '2026.11.02')]);

    app(UpdateBatchRunner::class)->advance(itemsAllowed: false);

    expect(urmItems($batch)[0]['state'])->toBe('pending')
        ->and(app(UpdateBatches::class)->open())->not->toBeNull();
});

it('pushes itself again when it could not finish its work on a queue site without a scheduler', function (): void {
    urmBatch($this, [urmItem('module', 'Account', '2026.11.04', '2026.11.05')]);
    $held = Cache::lock(UpdateBatchRunner::LOCK_NAME, 60);
    $held->get();

    (new AdvanceUpdateBatchJob())->handle(app(UpdateBatchRunner::class), app(\Cms\Core\Services\Scheduler\SchedulerHeartbeat::class));

    $held->release();
    Queue::assertPushed(AdvanceUpdateBatchJob::class, static fn (AdvanceUpdateBatchJob $job): bool => $job->delay !== null);
});

it('leaves the retry to the scheduler when one runs', function (): void {
    app(\Cms\Core\Services\Scheduler\SchedulerHeartbeat::class)->beat();
    urmBatch($this, [urmItem('widget', 'ispa.news', '2026.11.01', '2026.11.02')]);

    (new AdvanceUpdateBatchJob())->handle(app(UpdateBatchRunner::class), app(\Cms\Core\Services\Scheduler\SchedulerHeartbeat::class));

    Queue::assertNotPushed(AdvanceUpdateBatchJob::class);
});
