<?php

use Carbon\Carbon;
use Cms\Core\Console\Commands\WorkflowRunScheduledCommand;
use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * A run stuck in `running` (its worker died) is failed as `stale_running` so it stops blocking new runs; the job that
 * runs an instance is never retried by the queue.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['core.workflow_schedule.enabled' => true, 'core.workflow_schedule.stale_running_minutes' => 30]);

    $command = app(WorkflowRunScheduledCommand::class);
    if (! isset(Artisan::all()[$command->getName()])) {
        app(ConsoleKernel::class)->registerCommand($command);
    }
});

function staleProject(): WorkflowProject
{
    return WorkflowProject::query()->forceCreate([
        'name' => 'Church stories',
        'slug' => 'church-stories-' . Str::lower(Str::random(5)),
        'trigger_event' => 'manual',
        'target_entity_type' => 'none',
        'is_active' => true,
    ]);
}

function staleInstance(WorkflowProject $project, string $status, Carbon $lastActivity): WorkflowInstance
{
    $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
        'target_type' => WorkflowProject::class,
        'project_id' => $project->id,
        'status' => $status,
        'started_at' => $lastActivity,
    ]);
    WorkflowInstance::query()->whereKey($instance->id)->update(['created_at' => $lastActivity, 'updated_at' => $lastActivity]);

    return $instance->fresh();
}

test('a running instance with no activity for longer than the limit is failed as stale_running', function () {
    $this->travelTo(Carbon::parse('2026-11-24 08:00:00', 'UTC'));
    $project = staleProject();
    $stale = staleInstance($project, 'running', now()->subMinutes(31));
    $fresh = staleInstance(staleProject(), 'running', now()->subMinutes(10));
    $waiting = staleInstance(staleProject(), 'awaiting_approval', now()->subDays(2));

    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();

    expect($stale->fresh()->status)->toBe('failed')
        ->and($stale->fresh()->finished_at)->not->toBeNull()
        ->and(WorkflowInstanceStep::query()->where('instance_id', $stale->id)->value('error_message'))->toBe('stale_running')
        ->and($fresh->fresh()->status)->toBe('running')
        ->and($waiting->fresh()->status)->toBe('awaiting_approval');
});

test('a recent step counts as activity even when the instance row is old', function () {
    $this->travelTo(Carbon::parse('2026-11-24 08:00:00', 'UTC'));
    $instance = staleInstance(staleProject(), 'running', now()->subHours(2));
    $step = WorkflowInstanceStep::query()->create([
        'instance_id' => $instance->id,
        'status' => 'running',
        'started_at' => now()->subMinutes(5),
    ]);
    WorkflowInstanceStep::query()->whereKey($step->id)->update(['updated_at' => now()->subMinutes(5)]);

    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();

    expect($instance->fresh()->status)->toBe('running');
});

test('a stale run no longer blocks the next scheduled run', function () {
    $this->travelTo(Carbon::parse('2026-11-24 08:00:00', 'UTC'));
    $editor = \App\Models\User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'webmaster', 'username' => 'wm-' . Str::random(5),
        'email' => 'wm-' . Str::random(5) . '@example.com', 'password' => 'x', 'type' => 0, 'level' => 0, 'status' => null,
    ]);
    $project = staleProject();
    $project->forceFill(['schedule_cron' => '0 8 * * *', 'schedule_timezone' => 'UTC', 'scheduled_by_user_id' => $editor->id])->save();
    staleInstance($project, 'running', now()->subHours(3));

    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();

    expect(WorkflowInstance::query()->where('project_id', $project->id)->where('status', 'pending')->count())->toBe(1);
    Queue::assertPushed(RunWorkflowInstanceJob::class, 1);
});

test('the instance job is tried once and has a timeout', function () {
    $job = new RunWorkflowInstanceJob(1);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBeGreaterThan(0)
        ->and($job->timeout)->toBeLessThan(30 * 60);
});
