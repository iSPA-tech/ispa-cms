<?php

use App\Models\User;
use Carbon\Carbon;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Exceptions\WorkflowAlreadyRunning;
use Cms\Core\Services\Workflow\Triggers\ErrorWorkflowLauncher;
use Cms\Core\Services\Workflow\Triggers\StartTriggeredRunJob;
use Cms\Core\Services\Workflow\Triggers\SubWorkflowService;
use Cms\Core\Services\Workflow\Triggers\TriggerChain;
use Cms\Core\Services\Workflow\Triggers\WorkflowTriggers;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Cms\Core\Tests\Support\WorkflowTestVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Review lane D: runs that cannot start now are not lost (findings 17, 18, 21).
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware();
    if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
        \Illuminate\Support\Facades\DB::statement('PRAGMA defer_foreign_keys = ON');
    }
    TriggerChain::reset();
    ldUser('placeholder'); // user id 1 is always a webmaster
});

function ldUser(string $name = 'editor'): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 1,
        'level' => 0,
        'status' => null,
    ]);
    $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
    foreach (['workflow_project.viewAny', 'workflow_project.update', 'workflow_project.run'] as $key) {
        [$group, $ability] = explode('.', $key, 2);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability], ['title' => $key]));
    }
    $user->roles()->attach($role);

    return $user->fresh();
}

/**
 * @param list<array{0: string, 1: array<string, mixed>}> $steps Chained in order; the first is the start node.
 */
function ldProject(array $steps, ?User $scheduler = null, array $attributes = []): WorkflowProject
{
    $project = WorkflowProject::query()->forceCreate(array_merge([
        'name' => 'LD ' . Str::random(4),
        'slug' => 'ld-' . Str::lower(Str::random(6)),
        'trigger_event' => 'manual',
        'target_entity_type' => 'none',
        'is_active' => true,
    ], $attributes));

    $previous = null;
    foreach (array_values($steps) as $i => [$type, $config]) {
        $step = WorkflowStep::query()->create([
            'project_id' => $project->id, 'step_code' => 's' . $i, 'name' => 's' . $i, 'step_type' => $type,
            'step_config' => $config, 'position_x' => $i === 0 ? 1000 : $i, 'position_y' => 0, 'is_start' => $i === 0,
        ]);
        if ($previous) {
            WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $previous->id, 'to_step_id' => $step->id]);
        }
        $previous = $step;
    }
    if ($scheduler) {
        $project->forceFill(['scheduled_by_user_id' => $scheduler->id])->save();
    }
    WorkflowTestVersions::publish($project);

    return $project->fresh();
}

test('finding 18: delayed, queued, applied and child-waiting runs count as in progress', function (string $status) {
    $editor = ldUser();
    $project = ldProject([['trigger_manual', []]]);
    WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => $status]);

    expect(fn () => app(WorkflowProjectRunService::class)->start($project, $editor))->toThrow(WorkflowAlreadyRunning::class);
})->with(['waiting', 'queued', 'applied', 'awaiting_child']);

test('finding 17: a triggered start that finds a run in progress is queued again, not dropped', function () {
    Queue::fake();
    $scheduler = ldUser('scheduler');
    $project = ldProject([['trigger_event', ['event' => 'ld.contact.sent']]], $scheduler);
    WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'running']);
    $stepId = WorkflowTriggers::publishedStartStep($project)->getKey();

    (new StartTriggeredRunJob($project->id, $stepId, 'event', ['n' => 7]))->handle(app(WorkflowProjectRunService::class));

    Queue::assertPushed(StartTriggeredRunJob::class, fn (StartTriggeredRunJob $job) => $job->projectId === $project->id
        && $job->meta === ['n' => 7] && $job->busyAttempt === 1 && $job->delay !== null);
    expect(WorkflowInstance::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('finding 17: a re-queued start gives up after the configured attempts', function () {
    Queue::fake();
    config(['core.workflow_schedule.busy_retry_attempts' => 3]);
    $scheduler = ldUser('scheduler');
    $project = ldProject([['trigger_event', ['event' => 'ld.contact.sent']]], $scheduler);
    WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'running']);
    $stepId = WorkflowTriggers::publishedStartStep($project)->getKey();

    (new StartTriggeredRunJob($project->id, $stepId, 'event', [], [], null, null, 3))->handle(app(WorkflowProjectRunService::class));

    Queue::assertNothingPushed();
});

test('finding 17: an error workflow that is busy gets its alert queued, not dropped', function () {
    Queue::fake();
    $editor = ldUser();
    $errorFlow = ldProject([['trigger_error', ['storeAs' => 'vars.e']]], $editor);
    $main = ldProject([['trigger_manual', []]], null, ['error_workflow_project_id' => $errorFlow->id]);
    WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $errorFlow->id, 'status' => 'running']);
    $failed = WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $main->id, 'status' => 'failed']);

    expect(app(ErrorWorkflowLauncher::class)->instanceFailed($failed->id))->toBeNull();

    Queue::assertPushed(StartTriggeredRunJob::class, fn (StartTriggeredRunJob $job) => $job->projectId === $errorFlow->id
        && $job->source === 'error' && data_get($job->meta, 'failed_instance.id') === $failed->id);
});

test('finding 21: a due minute missed by a late pass is caught up once, within the window', function () {
    Queue::fake();
    config(['core.workflow_schedule.enabled' => true]);
    $editor = ldUser();
    $this->actingAs($editor);
    $project = ldProject([['trigger_schedule', ['cron' => '0 * * * *', 'timezone' => 'UTC']]]);
    $project->forceFill(['last_scheduled_run_at' => Carbon::parse('2026-11-24 07:00:00', 'UTC')->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s')])->save();

    // The 08:00 pass was skipped (overlap); the next pass runs at 08:01.
    $result = app(WorkflowProjectRunService::class)->runDue(Carbon::parse('2026-11-24 08:01:10', 'UTC'));
    expect($result['started'])->toBe([$project->id]);

    // The caught-up minute is recorded, so the 08:02 pass starts nothing more.
    WorkflowInstance::query()->where('project_id', $project->id)->update(['status' => 'completed']);
    $again = app(WorkflowProjectRunService::class)->runDue(Carbon::parse('2026-11-24 08:02:10', 'UTC'));
    expect($again['started'])->toBe([])
        ->and(WorkflowInstance::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('finding 21: a due minute older than the catch-up window is not started late', function () {
    Queue::fake();
    config(['core.workflow_schedule.enabled' => true, 'core.workflow_schedule.catch_up_minutes' => 10]);
    $editor = ldUser();
    $this->actingAs($editor);
    $project = ldProject([['trigger_schedule', ['cron' => '0 * * * *', 'timezone' => 'UTC']]]);
    $project->forceFill(['last_scheduled_run_at' => Carbon::parse('2026-11-24 07:00:00', 'UTC')->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s')])->save();

    $result = app(WorkflowProjectRunService::class)->runDue(Carbon::parse('2026-11-24 08:30:00', 'UTC'));

    expect($result['started'])->toBe([])
        ->and(WorkflowInstance::query()->where('project_id', $project->id)->count())->toBe(0);
});

test('finding 26: the breaker is evaluated under the project lock and its pause survives the refused start', function () {
    config(['core.workflow_guard.breaker.runs_per_hour' => 1]);
    $editor = ldUser();
    $project = ldProject([['trigger_manual', []]]);
    WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'completed']);

    $levels = [];
    app()->instance(\Cms\Core\Services\Workflow\Guard\WorkflowCircuitBreaker::class, new class(app(\Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter::class), $levels) extends \Cms\Core\Services\Workflow\Guard\WorkflowCircuitBreaker {
        public function __construct(\Cms\Core\Services\Workflow\Guard\WorkflowChangeAlerter $alerter, private array &$levels)
        {
            parent::__construct($alerter);
        }

        public function evaluate(\Cms\Core\Models\Workflows\WorkflowProject $project): ?array
        {
            $this->levels[] = \Illuminate\Support\Facades\DB::transactionLevel();

            return parent::evaluate($project);
        }
    });

    expect(fn () => app(WorkflowProjectRunService::class)->start($project, $editor))
        ->toThrow(\Cms\Core\Services\Workflow\Exceptions\WorkflowBreakerTripped::class);

    // RefreshDatabase wraps the test in one transaction: inside the run's own transaction the level is at least 2.
    expect($levels)->not->toBeEmpty()
        ->and(min($levels))->toBeGreaterThan(1)
        ->and($project->fresh()->is_active)->toBeFalse()
        ->and($project->fresh()->paused_reason)->toBe('breaker:runs_per_hour')
        ->and(WorkflowInstance::query()->where('project_id', $project->id)->count())->toBe(1);
});

/**
 * A parent left in `awaiting_child` (ResumeParentWorkflowJob gave up) and its finished child.
 *
 * @return array{0: WorkflowInstance, 1: WorkflowInstance}
 */
function ldOrphanedParent(string $childStatus, ?int $resumeStepId = null): array
{
    $project = ldProject([['trigger_manual', []]]);
    $parent = WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => 'awaiting_child', 'resume_step_id' => $resumeStepId]);
    $child = WorkflowTestVersions::instance(['target_type' => WorkflowProject::class, 'project_id' => ldProject([['trigger_sub_workflow', []]])->id,
        'status' => $childStatus, 'finished_at' => now()->subHour(),
        'context_snapshot' => ['trigger' => ['meta' => ['mode' => 'wait', 'parent_instance_id' => $parent->id]], 'vars' => ['out' => 1]]]);
    $parent->forceFill(['context_snapshot' => ['vars' => [SubWorkflowService::AWAITING_VAR => ['instance_id' => $child->id, 'store_path' => 'child']]]])->save();
    WorkflowInstance::query()->whereKey($parent->id)->update(['updated_at' => now()->subHour()]);

    return [$parent->fresh(), $child];
}

test('finding 36: the stale sweep finishes a parent whose child ended while nobody resumed it', function () {
    Queue::fake();
    [$parent] = ldOrphanedParent('completed');
    [$failedParent] = ldOrphanedParent('failed');

    app(WorkflowProjectRunService::class)->failStaleRunning();

    expect($parent->fresh()->status)->toBe('completed')
        ->and(data_get($parent->fresh()->context_snapshot, 'vars.child.vars.out'))->toBe(1)
        ->and($failedParent->fresh()->status)->toBe('failed');
});

test('finding 36: a parent still waiting for a running child is left alone', function () {
    Queue::fake();
    [$parent, $child] = ldOrphanedParent('completed');
    $child->forceFill(['status' => 'awaiting_approval', 'finished_at' => null])->save();

    app(WorkflowProjectRunService::class)->failStaleRunning();

    expect($parent->fresh()->status)->toBe('awaiting_child');
});

test('finding 36: the debounce token lives in the shared trigger cache store, not the local default', function () {
    Queue::fake();
    config(['cache.stores.wf_shared' => ['driver' => 'array'], 'core.workflow.trigger_cache_store' => 'wf_shared']);
    $scheduler = ldUser('scheduler');
    $project = ldProject([['trigger_event', ['event' => 'ld.contact.sent']]], $scheduler);
    $stepId = WorkflowTriggers::publishedStartStep($project)->getKey();

    // The web host wrote the token to the shared store; the worker's own default store has never seen it.
    \Illuminate\Support\Facades\Cache::store('wf_shared')->put('core:workflow:debounce:ld', 'tok', 120);
    (new StartTriggeredRunJob($project->id, $stepId, 'event', [], [], 'core:workflow:debounce:ld', 'tok'))->handle(app(WorkflowProjectRunService::class));

    expect(WorkflowInstance::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and(\Cms\Core\Services\Workflow\Triggers\TriggerIndex::cache()->get('core:workflow:debounce:ld'))->toBe('tok');
});
