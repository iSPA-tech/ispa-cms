<?php

declare(strict_types=1);

use Cms\Core\Jobs\UpdateBatch\AdvanceUpdateBatchJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Cms\Core\Services\Scheduler\SchedulerHeartbeat;
use Cms\Core\Services\UpdateBatch\UpdateBatches;
use Cms\Core\Services\Widget\Hub\WidgetHubCatalog;
use Cms\Core\Services\Workflow\Hub\WorkflowHubCatalog;
use Cms\Core\Tests\Feature\UpdateBatch\UpdateBatchFixtures;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

// Upgrade-all spec 2026-11-26 decisions 5 and 8; plan Task 4: the batch endpoints and the start refusals.

const UPA_BASE = '/api/v2/admin/utilities/updates/batch';

beforeEach(function () {
    Queue::fake();
    URL::forceScheme('https');
    $this->tmp = UpdateBatchFixtures::site($this);
    $widgets = Mockery::mock(WidgetHubCatalog::class);
    $widgets->shouldReceive('list')->andReturn([]);
    $workflows = Mockery::mock(WorkflowHubCatalog::class);
    $workflows->shouldReceive('list')->andReturn([]);
    app()->instance(WidgetHubCatalog::class, $widgets);
    app()->instance(WorkflowHubCatalog::class, $workflows);
    // A queue site whose worker may run long installs (retry_after above the install job timeout).
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 3000]);
    $this->user = UpdateBatchFixtures::webmaster();
    Sanctum::actingAs($this->user);
});

afterEach(function () {
    UpdateBatchFixtures::tearDown($this->tmp);
});

function upeConfirmed(string $socialAuthTo = '2026.11.06'): array
{
    return [
        'kinds' => ['module', 'widget', 'workflow'],
        'items' => [
            ['kind' => 'module', 'key' => 'Account', 'to' => '2026.11.05'],
            ['kind' => 'module', 'key' => 'SocialAuth', 'to' => $socialAuthTo],
        ],
        'backup' => true,
    ];
}

it('refuses every route to an admin who is not a Webmaster', function (): void {
    Sanctum::actingAs(UpdateBatchFixtures::webmaster([], 1));
    $uuid = (string) Str::uuid();

    $this->getJson(UPA_BASE . '/plan')->assertStatus(403);
    $this->postJson(UPA_BASE, upeConfirmed())->assertStatus(403);
    $this->getJson(UPA_BASE . '/current')->assertStatus(403);
    $this->postJson(UPA_BASE . '/' . $uuid . '/stop')->assertStatus(403);
    $this->postJson(UPA_BASE . '/' . $uuid . '/dismiss')->assertStatus(403);
});

it('answers the plan with the runner state', function (): void {
    $this->getJson(UPA_BASE . '/plan')
        ->assertOk()
        ->assertJsonPath('data.items.0.key', 'Account')
        ->assertJsonPath('data.items.1.key', 'SocialAuth')
        ->assertJsonPath('data.runner_ready', true);
});

it('records the batch and kicks the runner on a queue site', function (): void {
    $response = $this->postJson(UPA_BASE, upeConfirmed())->assertStatus(202);

    $task = BackgroundTask::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();
    expect($task->type)->toBe(UpdateBatches::TASK_TYPE)
        ->and(array_column($task->result['items'], 'state'))->toBe(['pending', 'pending'])
        ->and($task->result['backup'])->toBeTrue();
    Queue::assertPushed(AdvanceUpdateBatchJob::class);
});

it('does not push a job on a site whose scheduler runs installs', function (): void {
    config(['queue.default' => 'sync']);
    app(SchedulerHeartbeat::class)->beat();

    $this->postJson(UPA_BASE, upeConfirmed())->assertStatus(202);

    Queue::assertNothingPushed();
});

it('refuses a second start while a batch is open', function (): void {
    $this->postJson(UPA_BASE, upeConfirmed())->assertStatus(202);

    $this->postJson(UPA_BASE, upeConfirmed())->assertStatus(409)->assertJsonPath('error_code', 'UPDATE_BATCH_RUNNING');
});

it('refuses to start while a module install row is unfinished', function (): void {
    ModuleInstall::query()->create([
        'uuid' => (string) Str::uuid(), 'name' => 'Probe', 'version' => '2026.1.0', 'source' => ModuleInstall::SOURCE_HUB,
        'kind' => ModuleInstall::KIND_INSTALL, 'status' => ModuleInstall::STATUS_DOWNLOADING, 'requested_by' => 1, 'warnings' => [],
    ]);

    $this->postJson(UPA_BASE, upeConfirmed())->assertStatus(409)->assertJsonPath('error_code', 'MODULE_INSTALL_RUNNING');
});

it('refuses to start while a core upgrade is queued', function (): void {
    BackgroundTask::query()->create(['type' => CoreUpgradeRequests::TASK_TYPE, 'module' => 'Core', 'status' => BackgroundTask::STATUS_QUEUED, 'started_by' => 1]);

    $this->postJson(UPA_BASE, upeConfirmed())->assertStatus(409)->assertJsonPath('error_code', 'CORE_UPGRADE_RUNNING');
});

it('refuses to start without a runner', function (): void {
    config(['queue.default' => 'sync']);

    $this->postJson(UPA_BASE, upeConfirmed())->assertStatus(422)->assertJsonPath('error_code', 'MODULE_INSTALL_NO_RUNNER');
});

it('refuses a confirmation the plan no longer matches and records nothing', function (): void {
    $this->postJson(UPA_BASE, upeConfirmed('2026.11.05'))->assertStatus(409)->assertJsonPath('error_code', 'UPDATE_BATCH_PLAN_CHANGED');

    expect(BackgroundTask::query()->where('type', UpdateBatches::TASK_TYPE)->count())->toBe(0);
});

it('hides a finished run and refuses to stop it again', function (): void {
    $uuid = $this->postJson(UPA_BASE, upeConfirmed())->json('data.uuid');

    BackgroundTask::query()->where('uuid', $uuid)->update(['status' => BackgroundTask::STATUS_SUCCEEDED]);
    $this->getJson(UPA_BASE . '/current')->assertOk()->assertJsonPath('data.uuid', $uuid);
    $this->postJson(UPA_BASE . '/' . $uuid . '/dismiss')->assertOk();
    $this->getJson(UPA_BASE . '/current')->assertOk()->assertJsonPath('data', null);
    $this->postJson(UPA_BASE . '/' . $uuid . '/stop')->assertStatus(409)->assertJsonPath('error_code', 'UPDATE_BATCH_NOT_OPEN');
});

it('ends at once a run that never moved when "Dừng" is pressed', function (): void {
    $uuid = $this->postJson(UPA_BASE, upeConfirmed())->json('data.uuid');

    $this->postJson(UPA_BASE . '/' . $uuid . '/stop')->assertOk()->assertJsonPath('data.status', BackgroundTask::STATUS_SUCCEEDED);

    $task = BackgroundTask::query()->where('uuid', $uuid)->firstOrFail();
    expect($task->result['stopped_reason'])->toBe('owner')
        ->and(array_column($task->result['items'], 'state'))->toBe(['not_started', 'not_started']);
    $this->postJson('/api/v2/admin/widget/hub/ispa.news/download')->assertJsonMissing(['error_code' => UpdateBatches::ERROR_RUNNING]);
});

it('cancels an install nobody picked up when "Dừng" is pressed', function (): void {
    $uuid = $this->postJson(UPA_BASE, upeConfirmed())->json('data.uuid');
    app(\Cms\Core\Services\UpdateBatch\UpdateBatchRunner::class)->advance();
    $row = ModuleInstall::query()->sole();
    expect($row->status)->toBe(ModuleInstall::STATUS_QUEUED);

    $this->postJson(UPA_BASE . '/' . $uuid . '/stop')->assertOk()->assertJsonPath('data.stopped_reason', 'owner');

    expect($row->fresh()->status)->toBe(ModuleInstall::STATUS_FAILED)
        ->and($row->fresh()->error_code)->toBe(\Cms\Core\Services\UpdateBatch\UpdateBatchRunner::ERROR_STOPPED)
        ->and(array_column(BackgroundTask::query()->where('uuid', $uuid)->firstOrFail()->result['items'], 'state'))->toBe(['not_started', 'not_started']);
});

it('waits for an install that already started when "Dừng" is pressed', function (): void {
    $uuid = $this->postJson(UPA_BASE, upeConfirmed())->json('data.uuid');
    app(\Cms\Core\Services\UpdateBatch\UpdateBatchRunner::class)->advance();
    ModuleInstall::query()->sole()->forceFill(['status' => ModuleInstall::STATUS_DOWNLOADING, 'started_at' => now()])->save();

    $this->postJson(UPA_BASE . '/' . $uuid . '/stop')->assertOk()
        ->assertJsonPath('data.status', BackgroundTask::STATUS_RUNNING)
        ->assertJsonPath('data.stop_requested', true);
    $this->postJson(UPA_BASE . '/' . $uuid . '/dismiss')->assertStatus(409)->assertJsonPath('error_code', 'UPDATE_BATCH_OPEN');
});
