<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowVersion;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Review fix 7 (2026-11-25): who may decide a run approval. The step's reviewer permission, its assignees, and never
 * the run's own user or an author of the workflow version the run executes. Agent proposals keep their flow.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function adrUser(string $name, array $permissions = ['agentic.viewAny', 'agentic.approve']): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 0,
        'level' => 0,
        'status' => null,
    ]);
    if ($permissions !== []) {
        $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
        foreach ($permissions as $key) {
            [$group, $ability] = explode('.', $key, 2);
            $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability], ['title' => $key]));
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

/** draft → ask (approval); ask: approved → ok, rejected → no, changes_requested → draft. Run acting for $starter. */
function adrRun(array $askConfig, User $starter): AgentApproval
{
    $project = WorkflowProject::query()->create(['name' => 'Decider rules', 'slug' => 'decider-' . uniqid(), 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => true]);
    $steps = [
        'draft' => ['set_variable', ['assignments' => [['key' => 'vars.drafted', 'valueExpr' => "'yes'"]]]],
        'ask' => ['approval', $askConfig + ['title' => 'Check', 'payload' => "{'headline': 'Old'}", 'notify' => false]],
        'ok' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'ok'"]]]],
        'no' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'no'"]]]],
    ];
    $made = [];
    $x = 1;
    foreach ($steps as $code => [$type, $config]) {
        $made[$code] = WorkflowStep::query()->create([
            'project_id' => $project->id, 'step_type' => $type, 'step_code' => $code,
            'step_config' => $config, 'position_x' => $x, 'is_start' => $x === 1,
        ]);
        $x++;
    }
    foreach ([['draft', 'ask', null], ['ask', 'ok', 'approved'], ['ask', 'no', 'rejected'], ['ask', 'draft', 'changes_requested']] as [$from, $to, $handle]) {
        WorkflowConnection::query()->create([
            'project_id' => $project->id, 'from_step_id' => $made[$from]->id, 'to_step_id' => $made[$to]->id,
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle],
        ]);
    }

    $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'target_type' => User::class, 'target_id' => $starter->id, 'status' => 'pending', 'delegated_by_user_id' => $starter->id]);
    app(WorkflowGraphExecutor::class)->execute($instance->id);

    return AgentApproval::query()->latest('id')->firstOrFail();
}

function adrVersionOf(AgentApproval $approval): WorkflowVersion
{
    $instance = WorkflowInstance::query()->findOrFail((int) data_get($approval->diff_data, 'workflow_node.instance_id'));

    return WorkflowVersion::query()->findOrFail($instance->version_id);
}

beforeEach(function (): void {
    if (DB::getDriverName() === 'sqlite') {
        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', 'workflow_instance_steps')->value('sql');
        $fixed = preg_replace('/,\s*foreign key\("step_id"\) references "workflow_instances"\("id"\)[^,)]*/i', '', $sql);
        if ($fixed !== null && $fixed !== $sql) {
            DB::statement((string) preg_replace('/^CREATE TABLE "?workflow_instance_steps"?/i', 'CREATE TABLE "workflow_instance_steps_fixed"', $fixed));
            DB::statement('INSERT INTO workflow_instance_steps_fixed SELECT * FROM workflow_instance_steps');
            DB::statement('DROP TABLE workflow_instance_steps');
            DB::statement('ALTER TABLE workflow_instance_steps_fixed RENAME TO workflow_instance_steps');
        }
    }
    $this->withoutMiddleware();
    // User id 1 is always a webmaster.
    $this->webmaster = adrUser('webmaster', []);
    $this->starter = adrUser('starter');
});

it('needs the step reviewer permission to approve, reject, request changes or approve-apply', function (string $action, array $body): void {
    $approval = adrRun(['reviewer_permission' => 'finance.approve'], $this->starter);
    $plain = adrUser('plain');

    $this->actingAs($plain, 'sanctum')->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/{$action}", $body)
        ->assertStatus(403)->assertJsonPath('error_code', 'reviewer_permission');
    expect($approval->fresh()->status)->toBe('pending');

    $finance = adrUser('finance', ['agentic.viewAny', 'agentic.approve', 'finance.approve']);
    $this->actingAs($finance, 'sanctum')->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/{$action}", $body)->assertOk();
    expect($approval->fresh()->status)->not->toBe('pending');
})->with([
    'approve' => ['approve', []],
    'reject' => ['reject', []],
    'request changes' => ['request-changes', ['feedback' => 'Please shorten it.']],
    'approve and apply' => ['approve-apply', []],
]);

it('lets only an assignee decide an assigned item', function (): void {
    $assignee = adrUser('assignee');
    $other = adrUser('other');
    $approval = adrRun(['assignees' => [$assignee->uuid]], $this->starter);

    $this->actingAs($other, 'sanctum')->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/approve")
        ->assertStatus(403)->assertJsonPath('error_code', 'not_assignee');
    $this->actingAs($assignee, 'sanctum')->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/approve")->assertOk();
});

it('refuses the user the run acts for', function (): void {
    $approval = adrRun([], $this->starter);

    $this->actingAs($this->starter, 'sanctum')->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/approve")
        ->assertStatus(403)->assertJsonPath('error_code', 'own_run');
    $this->actingAs($this->starter, 'sanctum')->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/reject")
        ->assertStatus(403)->assertJsonPath('error_code', 'own_run');
    expect($approval->fresh()->status)->toBe('pending');
});

it('refuses the publisher and the draft editors of the version the run executes', function (): void {
    $publisher = adrUser('publisher');
    $editor = adrUser('editor');
    $reviewer = adrUser('reviewer');
    $approval = adrRun([], $this->starter);
    WorkflowVersion::query()->whereKey(adrVersionOf($approval)->id)->update(['created_by' => $publisher->id, 'editor_ids' => json_encode([$editor->id])]);

    foreach ([$publisher, $editor] as $author) {
        $this->actingAs($author, 'sanctum')->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/approve")
            ->assertStatus(403)->assertJsonPath('error_code', 'workflow_author');
    }
    $this->actingAs($reviewer, 'sanctum')->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/approve")->assertOk();
});

it('lets a Webmaster decide any item', function (): void {
    $approval = adrRun(['reviewer_permission' => 'finance.approve'], $this->webmaster);

    $this->actingAs($this->webmaster, 'sanctum')->postJson("/api/v2/admin/agentic/approvals/{$approval->id}/approve")->assertOk();
});

it('resumes a paused node only with an approval of the same run and step (review fix 33)', function (): void {
    $own = adrRun([], $this->starter);
    $other = adrRun([], $this->starter);
    $instanceId = (int) data_get($own->diff_data, 'workflow_node.instance_id');
    $projectId = (int) WorkflowInstance::query()->whereKey($instanceId)->value('project_id');
    $ask = \Cms\Core\Workflows\Runtime\StepDefinition::fromModel(WorkflowStep::query()->where('project_id', $projectId)->where('step_code', 'ask')->firstOrFail());
    $draft = \Cms\Core\Workflows\Runtime\StepDefinition::fromModel(WorkflowStep::query()->where('project_id', $projectId)->where('step_code', 'draft')->firstOrFail());
    $gate = app(\Cms\Core\Workflows\Runtime\Runners\Human\WorkflowApprovalGate::class);
    $context = new \Cms\Core\Workflows\Runtime\WorkflowContext(new User());
    $context->setRuntime('instance_id', $instanceId);

    // Another run's approval: never resumes this run.
    $context->setStepResult('ask', ['approval_id' => $other->id], 'awaiting_approval');
    expect($gate->waitingApproval($ask, $context))->toBeNull();

    // This run's approval, but read by another step: refused.
    $context->setStepResult('draft', ['approval_id' => $own->id], 'awaiting_approval');
    expect($gate->waitingApproval($draft, $context))->toBeNull();

    $context->setStepResult('ask', ['payload' => ['approval_id' => $own->id]], 'awaiting_approval');
    expect($gate->waitingApproval($ask, $context)?->id)->toBe($own->id);
});
