<?php

declare(strict_types=1);

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Models\Widget;
use Cms\Core\Services\UpdateBatch\UpdateBatches;
use Cms\Core\Services\UpdateBatch\UpdateBatchRunner;
use Cms\Core\Services\Widget\Hub\WidgetHubException;
use Cms\Core\Services\Widget\Hub\WidgetHubInstaller;
use Cms\Core\Services\Widget\Hub\WidgetHubUnreachable;
use Cms\Core\Services\Workflow\Hub\WorkflowHubException;
use Cms\Core\Services\Workflow\Hub\WorkflowHubInstaller;
use Cms\Core\Tests\Feature\UpdateBatch\UpdateBatchFixtures;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

// Upgrade-all spec 2026-11-26 decisions 1 and 4; plan Task 6: widget and workflow items after the modules, skip and
// continue, stop on a repeat-class failure, and "Dừng".

beforeEach(function () {
    Queue::fake();
    $this->tmp = UpdateBatchFixtures::site($this);
    config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 3000]);
    $this->user = UpdateBatchFixtures::webmaster();
    $this->widgets = Mockery::mock(WidgetHubInstaller::class);
    $this->workflows = Mockery::mock(WorkflowHubInstaller::class);
    app()->instance(WidgetHubInstaller::class, $this->widgets);
    app()->instance(WorkflowHubInstaller::class, $this->workflows);
});

afterEach(function () {
    UpdateBatchFixtures::tearDown($this->tmp);
});

function uriItem(string $kind, string $key, array $extra = []): array
{
    return $extra + ['kind' => $kind, 'key' => $key, 'label' => $key, 'from' => '1', 'to' => '2', 'state' => 'pending', 'reason_code' => null,
        'install_uuid' => null, 'widget_id' => null, 'pages' => null, 'project_id' => null, 'setup_url' => null];
}

function uriBatch(object $test, array $items): BackgroundTask
{
    return app(UpdateBatches::class)->start($test->user, ['items' => $items, 'left_out' => []], ['module', 'widget', 'workflow'], true);
}

function uriWidget(int $id, int $version = 20261102): Widget
{
    return (new Widget())->forceFill(['id' => $id, 'widget_key' => 'ispa.w' . $id, 'version' => $version]);
}

it('runs the widget and workflow items once the modules are done', function (): void {
    $batch = uriBatch($this, [
        uriItem('module', 'Account', ['state' => 'done']),
        uriItem('widget', 'ispa.news', ['widget_id' => 11]),
        uriItem('workflow', 'ispa.church-story'),
    ]);
    $this->widgets->shouldReceive('update')->once()->with(11, $this->user->id)->andReturn(uriWidget(11));
    $this->workflows->shouldReceive('updateInPlaceOnly')->once()->with('ispa.church-story', Mockery::on(fn ($u): bool => $u->id === $this->user->id))
        ->andReturn(['project_id' => 7, 'version_label' => 'v3', 'setup_url' => '/workflows/7/setup', 'updated_in_place' => true]);

    app(UpdateBatchRunner::class)->advance();

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)
        ->and(array_column($fresh->result['items'], 'state'))->toBe(['done', 'done', 'done'])
        ->and($fresh->result['items'][1]['to'])->toBe('2026.11.02')
        ->and($fresh->result['items'][2])->toMatchArray(['project_id' => 7, 'setup_url' => '/workflows/7/setup', 'to' => 'v3']);
});

it('skips a widget whose update now drops a design in use and goes on', function (): void {
    $batch = uriBatch($this, [uriItem('widget', 'ispa.menu', ['widget_id' => 12]), uriItem('widget', 'ispa.news', ['widget_id' => 11])]);
    $this->widgets->shouldReceive('update')->with(12, Mockery::any())->andThrow(WidgetHubException::designInUse());
    $this->widgets->shouldReceive('update')->with(11, Mockery::any())->andReturn(uriWidget(11));

    app(UpdateBatchRunner::class)->advance();

    $items = $batch->fresh()->result['items'];
    expect($items[0])->toMatchArray(['state' => 'skipped', 'reason_code' => WidgetHubException::DESIGN_IN_USE])
        ->and($items[1]['state'])->toBe('done');
});

it('stops the run when the widget hub cannot be reached', function (): void {
    $batch = uriBatch($this, [uriItem('widget', 'ispa.menu', ['widget_id' => 12]), uriItem('workflow', 'ispa.church-story')]);
    $this->widgets->shouldReceive('update')->andThrow(new WidgetHubUnreachable());
    $this->workflows->shouldReceive('updateInPlaceOnly')->never();

    app(UpdateBatchRunner::class)->advance();

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe(BackgroundTask::STATUS_FAILED)
        ->and($fresh->result['stopped_reason'])->toBe(WidgetHubUnreachable::ERROR_CODE)
        ->and(array_column($fresh->result['items'], 'state'))->toBe(['failed', 'not_started']);
});

it('skips a workflow that changed on the site after planning', function (): void {
    $batch = uriBatch($this, [uriItem('workflow', 'ispa.church-story')]);
    $this->workflows->shouldReceive('updateInPlaceOnly')->andThrow(new WorkflowHubException(WorkflowHubException::CHANGED, 'changed', 409));

    app(UpdateBatchRunner::class)->advance();

    expect($batch->fresh()->result['items'][0])->toMatchArray(['state' => 'skipped', 'reason_code' => WorkflowHubException::CHANGED]);
});

it('lets the running module finish and starts nothing more after "Dừng"', function (): void {
    $batch = uriBatch($this, [uriItem('module', 'Account', ['from' => '2026.11.04', 'to' => '2026.11.05']), uriItem('widget', 'ispa.news', ['widget_id' => 11])]);
    app(UpdateBatchRunner::class)->advance();
    $batches = app(UpdateBatches::class);
    $batches->change($batch, static fn (array $result): array => ['stop_requested' => true] + $result);
    ModuleInstall::query()->sole()->forceFill(['status' => ModuleInstall::STATUS_SUCCEEDED, 'finished_at' => now()])->save();
    $this->widgets->shouldReceive('update')->never();

    app(UpdateBatchRunner::class)->advance();

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe(BackgroundTask::STATUS_SUCCEEDED)
        ->and($fresh->result['stopped_reason'])->toBe('owner')
        ->and(array_column($fresh->result['items'], 'state'))->toBe(['done', 'not_started']);
});

it('ends the run when the person who started it is gone', function (): void {
    $batch = uriBatch($this, [uriItem('widget', 'ispa.news', ['widget_id' => 11])]);
    $batch->forceFill(['started_by' => 999999])->save();
    $this->widgets->shouldReceive('update')->never();

    app(UpdateBatchRunner::class)->advance();

    expect($batch->fresh()->result['stopped_reason'])->toBe(UpdateBatchRunner::ERROR_STARTER_MISSING);
});

it('marks an unexpected item error failed and goes on', function (): void {
    $batch = uriBatch($this, [uriItem('widget', 'ispa.menu', ['widget_id' => 12]), uriItem('widget', 'ispa.news', ['widget_id' => 11])]);
    $this->widgets->shouldReceive('update')->with(12, Mockery::any())->andThrow(new RuntimeException('boom ' . Str::random(4)));
    $this->widgets->shouldReceive('update')->with(11, Mockery::any())->andReturn(uriWidget(11));

    app(UpdateBatchRunner::class)->advance();

    expect(array_column($batch->fresh()->result['items'], 'state'))->toBe(['failed', 'done'])
        ->and($batch->fresh()->result['items'][0]['reason_code'])->toBe('UPDATE_BATCH_ITEM_FAILED');
});

it('skips a module the hub flagged irreversible after planning', function (): void {
    // The default fixture catalog lists Webmail 2026.11.03 with irreversible migrations.
    $batch = uriBatch($this, [uriItem('module', 'Webmail', ['from' => '2026.11.02', 'to' => '2026.11.03'])]);

    app(UpdateBatchRunner::class)->advance();

    expect($batch->fresh()->result['items'][0])->toMatchArray(['state' => 'skipped', 'reason_code' => 'IRREVERSIBLE'])
        ->and(ModuleInstall::query()->count())->toBe(0);
});
