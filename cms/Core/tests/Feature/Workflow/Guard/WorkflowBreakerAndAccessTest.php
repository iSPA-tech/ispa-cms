<?php

declare(strict_types=1);

use Cms\Core\Database\Seeders\PermissionTableSeed;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Notifications\AdminNotification;
use Cms\Core\Services\Workflow\Guard\WorkflowAudit;
use Cms\Core\Services\Workflow\Guard\WorkflowCircuitBreaker;
use Cms\Core\Services\Workflow\Guard\WorkflowGlobalPause;
use Cms\Core\Workflows\Runtime\Runners\ChangeRunner;
use Cms\Core\Workflows\Runtime\Runners\CreateObjectRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * Workflow guardrails lane 2: circuit breakers and the global pause (B7), publish/run permissions (B8), traceability
 * of workflow writes (B9).
 */

require_once __DIR__ . '/guard-helpers.php';

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    config(['services.telegram.token' => '', 'services.telegram.chat_id' => '']);
    $this->webmaster = wgUser('webmaster'); // user id 1 is always a Webmaster
});

function wgRuns(WorkflowProject $project, int $count, string $status = 'completed', array $extra = []): void
{
    for ($i = 0; $i < $count; $i++) {
        WorkflowInstance::query()->create(['target_type' => WorkflowProject::class, 'project_id' => $project->id, 'status' => $status] + $extra);
    }
}

function wgPauseAlerts(): int
{
    return Notification::sent(\App\Models\User::query()->find(1), AdminNotification::class)
        ->filter(fn (AdminNotification $n): bool => $n->type === 'workflow.change.auto_paused')->count();
}

it('pauses at the runs-per-hour limit once and alerts once', function (): void {
    config(['core.workflow_guard.breaker.runs_per_hour' => 3]);
    $project = wgProject();
    $breaker = app(WorkflowCircuitBreaker::class);

    wgRuns($project, 2);
    expect($breaker->check($project))->toBeNull()->and($project->fresh()->is_active)->toBeTrue();

    wgRuns($project, 1);
    expect($breaker->check($project))->toBe(WorkflowCircuitBreaker::RUNS_PER_HOUR)
        ->and($breaker->check($project))->toBe(WorkflowCircuitBreaker::RUNS_PER_HOUR);

    $fresh = $project->fresh();
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->paused_reason)->toBe('breaker:runs_per_hour')
        ->and($fresh->paused_at)->not->toBeNull()
        ->and(wgPauseAlerts())->toBe(1)
        ->and(AuditLog::query()->where('action', 'action.' . WorkflowAudit::BREAKER_PAUSED)->count())->toBe(1);
});

it('ignores runs older than an hour', function (): void {
    config(['core.workflow_guard.breaker.runs_per_hour' => 2]);
    $project = wgProject();
    wgRuns($project, 2);
    WorkflowInstance::query()->update(['created_at' => now()->subHours(2)]);

    expect(app(WorkflowCircuitBreaker::class)->check($project))->toBeNull();
});

it('pauses at the publish/external-call actions limit', function (): void {
    config(['core.workflow_guard.breaker.actions_per_hour' => 2]);
    $project = wgProject();
    $send = WorkflowStep::query()->forceCreate(['project_id' => $project->id, 'step_code' => 'send', 'name' => 'Send', 'step_type' => 'telegram_send', 'step_config' => []]);
    $log = WorkflowStep::query()->forceCreate(['project_id' => $project->id, 'step_code' => 'log', 'name' => 'Log', 'step_type' => 'log_message', 'step_config' => []]);
    // SQLite keeps the original step_id -> workflow_instances key next to the fixed one: make both ids exist there too.
    wgRuns($project, 2);
    $instance = WorkflowInstance::query()->firstOrFail();
    $step = fn (WorkflowStep $s) => WorkflowInstanceStep::query()->create(['instance_id' => $instance->id, 'step_id' => $s->id, 'status' => 'success', 'started_at' => now()]);

    $step($send);
    $step($log);
    $step($log);
    expect(app(WorkflowCircuitBreaker::class)->check($project))->toBeNull();

    $step($send);
    expect(app(WorkflowCircuitBreaker::class)->check($project))->toBe(WorkflowCircuitBreaker::ACTIONS_PER_HOUR)
        ->and($project->fresh()->is_active)->toBeFalse();
});

it('pauses at the cost-per-hour limit', function (): void {
    config(['core.workflow_guard.breaker.cost_per_hour' => 1.0]);
    $project = wgProject();
    wgRuns($project, 2);
    WorkflowInstance::query()->update(['usage_cost_usd' => 0.6]);

    expect(app(WorkflowCircuitBreaker::class)->check($project))->toBe(WorkflowCircuitBreaker::COST_PER_HOUR);
});

it('pauses on a high error rate over a full window, and a person resumes it', function (): void {
    config(['core.workflow_guard.breaker.error_rate' => 0.5, 'core.workflow_guard.breaker.error_window' => 4]);
    $project = wgProject();
    $breaker = app(WorkflowCircuitBreaker::class);

    wgRuns($project, 3, 'failed');
    expect($breaker->afterRun(WorkflowInstance::query()->latest('id')->first()))->toBeNull(); // window not full

    wgRuns($project, 1, 'completed');
    expect($breaker->afterRun(WorkflowInstance::query()->latest('id')->first()))->toBe(WorkflowCircuitBreaker::ERROR_RATE)
        ->and($project->fresh()->paused_reason)->toBe('breaker:error_rate')
        ->and(wgPauseAlerts())->toBe(1);

    $editor = wgUser('editor', ['workflow_project.update']);
    $url = "https://localhost/api/v2/admin/workflow/projects/{$project->id}/resume";
    $this->actingAs($editor, 'sanctum')->postJson($url)->assertForbidden();

    if (array_key_exists('published_version_id', $project->fresh()->getAttributes())) {
        $this->actingAs($this->webmaster, 'sanctum')->postJson($url)->assertStatus(422)->assertJsonPath('error_code', 'not_published');
        // Resume itself, as lane 3/4 call it once a version is published.
        expect($breaker->resume($project->fresh(), $this->webmaster))->toBeTrue();
    } else {
        $this->actingAs($this->webmaster, 'sanctum')->postJson($url)->assertOk();
    }

    expect($project->fresh()->is_active)->toBeTrue()
        ->and($project->fresh()->paused_reason)->toBeNull()
        ->and(AuditLog::query()->where('action', 'action.' . WorkflowAudit::BREAKER_RESUMED)->exists())->toBeTrue();
});

it('switches the global pause from the admin (Webmaster only) and the command, audited and alerted', function (): void {
    $pause = app(WorkflowGlobalPause::class);
    $url = 'https://localhost/api/v2/admin/utilities/workflow/pause-all';
    expect($pause->active())->toBeFalse();

    $publisher = wgUser('publisher', ['workflow_project.publish']);
    expect($this->actingAs($publisher, 'sanctum')->putJson($url, ['paused' => true])->status())->toBeIn([403, 404]);

    $this->actingAs($this->webmaster, 'sanctum')->putJson($url, ['paused' => true])->assertOk()->assertJsonPath('data.paused', true);
    expect($pause->active())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'action.' . WorkflowAudit::GLOBAL_PAUSE_CHANGED)->count())->toBe(1);
    Notification::assertSentTo($publisher, AdminNotification::class, fn (AdminNotification $n): bool => $n->type === 'workflow.change.global_pause_changed');

    // The generic settings API cannot flip it.
    $this->putJson('https://localhost/api/v2/admin/settings', ['workflow.paused_all' => '0']);
    expect($pause->active())->toBeTrue();

    expect(Artisan::call('cms:workflow-pause-all', ['state' => 'off']))->toBe(0)
        ->and($pause->active())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'action.' . WorkflowAudit::GLOBAL_PAUSE_CHANGED)->count())->toBe(2);

    config(['core.workflow_guard.paused_all' => true]);
    expect($pause->active())->toBeTrue();
});

it('gives publish and run no owner shortcut', function (): void {
    $owner = wgUser('owner', ['workflow_project.update']);
    $project = wgProject();
    $project->setAttribute('users_id', $owner->id); // the policy's owner shortcut reads users_id
    $publisher = wgUser('publisher', ['workflow_project.publish', 'workflow_project.run']);

    expect($owner->can('update', $project))->toBeTrue()
        ->and($owner->can('publish', $project))->toBeFalse()
        ->and($owner->can('run', $project))->toBeFalse()
        ->and($publisher->can('publish', $project))->toBeTrue()
        ->and($publisher->can('run', $project))->toBeTrue()
        ->and($this->webmaster->can('publish', $project))->toBeTrue();
});

it('seeds publish and run idempotently: run goes once to the roles that could edit, publish to nobody but the Webmaster', function (): void {
    $editor = wgUser('editor', ['workflow_project.update']);
    $editorRole = $editor->roles()->first();
    Permission::query()->where('group', 'workflow_project')->whereIn('name', ['publish', 'run'])->delete();

    (new PermissionTableSeed())->run();
    $run = Permission::query()->where('group', 'workflow_project')->where('name', 'run')->firstOrFail();
    $publish = Permission::query()->where('group', 'workflow_project')->where('name', 'publish')->firstOrFail();
    expect($publish->title)->toContain('Publish')
        ->and($editorRole->permissions()->pluck('permissions.id')->all())->toContain($run->id)->not->toContain($publish->id);

    // A Webmaster takes run away; a later seed does not give it back and adds no duplicate.
    $editorRole->permissions()->detach($run->id);
    (new PermissionTableSeed())->run();
    expect($editorRole->fresh()->permissions()->pluck('permissions.id')->all())->not->toContain($run->id)
        ->and(Permission::query()->where('group', 'workflow_project')->where('name', 'run')->count())->toBe(1);
});

it('traces every record a workflow run writes to the run, field names only', function (): void {
    // Partial: the delegate check (modelStepRefusal) runs for real on top of the opened model rule.
    $guard = Mockery::mock(WorkflowCallableGuard::class)->makePartial();
    $guard->shouldReceive('allowsModel')->andReturnTrue();
    app()->instance(WorkflowCallableGuard::class, $guard);
    // The run's delegate (the Webmaster) may write the record (model steps check the delegate, review finding 3).
    $webmaster = $this->webmaster;
    \Illuminate\Support\Facades\Gate::before(fn ($user) => $user->is($webmaster) ? true : null);

    $project = wgProject();
    wgRuns($project, 1, 'running', ['delegated_by_user_id' => $this->webmaster->id]);
    $instance = WorkflowInstance::query()->firstOrFail();
    $context = new WorkflowContext($project);
    $context->setRuntime('instance_id', $instance->id);

    $change = new StepDefinition(id: 1, projectId: $project->id, stepType: 'change', stepCode: 'rename', stepKey: null, stepName: 'Rename',
        stepConfig: ['targetExpr' => 'root', 'changes' => [['field' => 'description', 'valueExpr' => '"Private note"']]]);
    expect((new ChangeRunner())->run($change, $context)->status)->toBe('success');

    $row = AuditLog::query()->where('action', 'action.' . WorkflowAudit::RECORD_WRITTEN)->firstOrFail();
    expect($row->target_type)->toBe(WorkflowInstance::class)
        ->and($row->target_id)->toBe($instance->id)
        ->and($row->user_id)->toBe($this->webmaster->id)
        ->and($row->changed)->toMatchArray(['instance_id' => $instance->id, 'step_code' => 'rename', 'operation' => 'change',
            'model' => WorkflowProject::class, 'key' => $project->id, 'fields' => ['description']])
        ->and(json_encode($row->changed))->not->toContain('Private note');

    $create = new StepDefinition(id: 2, projectId: $project->id, stepType: 'create_object', stepCode: 'make', stepKey: null, stepName: 'Make',
        stepConfig: ['model' => WorkflowProject::class, 'fields' => [
            ['field' => 'name', 'valueExpr' => '"Copy"'], ['field' => 'slug', 'valueExpr' => '"copy-1"'],
            ['field' => 'trigger_event', 'valueExpr' => '"manual"'], ['field' => 'target_entity_type', 'valueExpr' => '"none"'],
        ]]);
    expect((new CreateObjectRunner())->run($create, $context)->status)->toBe('success')
        ->and(AuditLog::query()->where('action', 'action.' . WorkflowAudit::RECORD_WRITTEN)->where('target_id', $instance->id)->count())->toBe(2);

    // Outside a run (no instance id) nothing is traced.
    (new ChangeRunner())->run($change, new WorkflowContext($project));
    expect(AuditLog::query()->where('action', 'action.' . WorkflowAudit::RECORD_WRITTEN)->count())->toBe(2);
});
