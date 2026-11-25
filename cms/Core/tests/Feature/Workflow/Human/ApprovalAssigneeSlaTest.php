<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Services\Ai\Jev\JevJudge;
use Cms\Core\Services\Ai\Jev\JevRequest;
use Cms\Core\Services\Ai\Jev\JevVerdict;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Approval assignee and SLA (2026-11-24): an approval node names who must review and a target time to decide; every
 * approval records its assignees, due time, the person's decision time and whether the SLA was met.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/** Judge returning a fixed confident verdict. */
final class AasConfidentJudge implements JevJudge
{
    public function judge(JevRequest $request): JevVerdict
    {
        return new JevVerdict('assessed', JevVerdict::PASS, 0.99, ['clear'], $request->mode);
    }
}

function aasUser(string $name, bool $reviewer = true): User
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

    if ($reviewer) {
        $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => 'agentic', 'name' => 'approve'], ['title' => 'agentic.approve']));
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

/**
 * draft (set_variable) → ask (approval); ask: approved → ok, rejected → no, expired → late, changes_requested → draft.
 */
function aasRun(array $askConfig, User $user): WorkflowInstance
{
    $project = WorkflowProject::query()->create(['name' => 'Assignee SLA', 'slug' => 'assignee-sla-' . uniqid(), 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => true]);
    $steps = [
        'draft' => ['set_variable', ['assignments' => [['key' => 'vars.drafted', 'valueExpr' => "'yes'"]]]],
        'ask' => ['approval', $askConfig + ['title' => 'Check', 'payload' => "{'headline': 'Old'}"]],
        'ok' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'ok'"]]]],
        'no' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'no'"]]]],
        'late' => ['set_variable', ['assignments' => [['key' => 'vars.went', 'valueExpr' => "'late'"]]]],
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
    foreach ([['draft', 'ask', null], ['ask', 'ok', 'approved'], ['ask', 'no', 'rejected'], ['ask', 'late', 'expired'], ['ask', 'draft', 'changes_requested']] as [$from, $to, $handle]) {
        WorkflowConnection::query()->create([
            'project_id' => $project->id, 'from_step_id' => $made[$from]->id, 'to_step_id' => $made[$to]->id,
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle],
        ]);
    }

    $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'target_type' => User::class, 'target_id' => $user->id, 'status' => 'pending', 'delegated_by_user_id' => $user->id]);
    app(WorkflowGraphExecutor::class)->execute($instance->id);

    return $instance->fresh();
}

/** Notification types a user received. */
function aasNotices(User $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->pluck('data')
        ->map(static fn ($data): ?string => json_decode((string) $data, true)['type'] ?? null)->values()->all();
}

function aasAssigneeIds(AgentApproval $approval): array
{
    return DB::table(CoreTableConstant::AGENT_APPROVAL_ASSIGNEE)->where('approval_id', $approval->id)->orderBy('user_id')->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
}

beforeEach(function (): void {
    // Same SQLite fix as WorkflowHumanNodesTest: the instance-step table must not keep its stale foreign key.
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
    // User id 1 is always a webmaster; burn it so the users below are ordinary staff.
    aasUser('placeholder', false);
});

it('an assigned approval records due_at and notifies only the assignees', function (): void {
    $assignee = aasUser('assignee');
    $holder = aasUser('holder');
    aasRun(['assignees' => [$assignee->uuid], 'sla' => '4h'], $holder);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();

    expect(aasAssigneeIds($approval))->toBe([$assignee->id])
        ->and($approval->sla_seconds)->toBe(4 * 3600)
        ->and($approval->due_at?->getTimestamp())->toBe($approval->created_at->copy()->addHours(4)->getTimestamp())
        ->and($approval->decided_at)->toBeNull()
        ->and(aasNotices($assignee))->toBe(['workflow.approval.requested'])
        ->and(aasNotices($holder))->toBe([]);
});

it('a decision records response time and sla_met', function (): void {
    $assignee = aasUser('assignee');
    $other = aasUser('other');
    aasRun(['assignees' => [$assignee->uuid], 'sla' => '2h'], $other);
    $quick = AgentApproval::query()->latest('id')->firstOrFail();

    $this->travel(30)->minutes();
    // Anyone holding the permission may decide; the decider is recorded.
    app(AgentApprovalService::class)->approveAndApply($quick, $other, 'fine');
    $quick->refresh();

    expect($quick->decided_at)->not->toBeNull()
        ->and($quick->decided_by_user_id)->toBe($other->id)
        ->and($quick->response_seconds)->toBeGreaterThanOrEqual(1800)->toBeLessThan(1810)
        ->and($quick->sla_met)->toBeTrue();

    aasRun(['assignees' => [$assignee->uuid], 'sla' => '1h'], $other);
    $slow = AgentApproval::query()->latest('id')->firstOrFail();
    $this->travel(3)->hours();
    app(AgentApprovalService::class)->reject($slow, $assignee, 'No');
    $slow->refresh();

    expect($slow->decided_by_user_id)->toBe($assignee->id)
        ->and($slow->response_seconds)->toBeGreaterThanOrEqual(3 * 3600)
        ->and($slow->sla_met)->toBeFalse();
});

it('request changes stops the clock and the next round gets a new clock', function (): void {
    $assignee = aasUser('assignee');
    $instance = aasRun(['assignees' => [$assignee->uuid], 'sla' => '1h'], $assignee);
    $first = AgentApproval::query()->latest('id')->firstOrFail();

    $this->travel(20)->minutes();
    app(AgentApprovalService::class)->requestChanges($first, $assignee, 'Shorter', null, []);
    $first->refresh();
    $second = AgentApproval::query()->latest('id')->firstOrFail();

    expect($instance->fresh()->status)->toBe('awaiting_approval')
        ->and($second->id)->not->toBe($first->id)
        ->and($first->decided_at)->not->toBeNull()
        ->and($first->response_seconds)->toBeGreaterThanOrEqual(1200)->toBeLessThan(1210)
        ->and($first->sla_met)->toBeTrue()
        ->and(aasAssigneeIds($second))->toBe([$assignee->id])
        ->and($second->sla_seconds)->toBe(3600)
        ->and($second->decided_at)->toBeNull()
        ->and($second->due_at?->getTimestamp())->toBe($second->created_at->copy()->addHour()->getTimestamp());
});

it('apply after approve does not move decided_at', function (): void {
    $approver = aasUser('approver');
    $applier = aasUser('applier');
    aasRun(['assignees' => [$approver->uuid], 'sla' => '1h'], $approver);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();
    $service = app(AgentApprovalService::class);

    $this->travel(10)->minutes();
    $service->approve($approval, $approver);
    $decided = $approval->fresh();

    $this->travel(5)->hours();
    $service->apply($approval, $applier);
    $applied = $approval->fresh();

    expect($applied->apply_outcome)->toBe('applied')
        ->and($applied->applied_by_user_id)->toBe($applier->id)
        ->and($applied->decided_at?->getTimestamp())->toBe($decided->decided_at?->getTimestamp())
        ->and($applied->decided_by_user_id)->toBe($approver->id)
        ->and($applied->response_seconds)->toBe($decided->response_seconds)
        ->and($applied->response_seconds)->toBeLessThan(700)
        ->and($applied->sla_met)->toBeTrue();
});

it('expiry counts as missed', function (): void {
    $assignee = aasUser('assignee');
    $instance = aasRun(['assignees' => [$assignee->uuid], 'sla' => '30m', 'expires_after' => '1h'], $assignee);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();

    $this->travel(2)->hours();
    expect(app(AgentApprovalService::class)->expireDueWorkflowApprovals())->toBe(1);
    $approval->refresh();

    expect($approval->status)->toBe('expired')
        ->and($approval->decided_at)->toBeNull()
        ->and($approval->decided_by_user_id)->toBeNull()
        ->and($approval->sla_met)->toBeFalse()
        ->and(data_get($instance->fresh()->context_snapshot, 'vars.went'))->toBe('late');
});

it('an overdue approval is flagged and notified once', function (): void {
    config(['core.workflow_schedule.enabled' => true]);
    $assignee = aasUser('assignee');
    $holder = aasUser('holder');
    aasRun(['assignees' => [$assignee->uuid], 'sla' => '30m'], $holder);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();

    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();
    expect($approval->fresh()->sla_breached_at)->toBeNull();

    $this->travel(1)->hours();
    $this->artisan('cms:workflow-run-scheduled')->expectsOutputToContain('Flagged 1 overdue approval(s)')->assertSuccessful();
    $this->artisan('cms:workflow-run-scheduled')->assertSuccessful();

    expect($approval->fresh()->sla_breached_at)->not->toBeNull()
        ->and($approval->fresh()->status)->toBe('pending')
        ->and(app(AgentApprovalService::class)->flagOverdueApprovals())->toBe(0)
        ->and(aasNotices($assignee))->toBe(['workflow.approval.requested', 'workflow.approval.overdue'])
        ->and(aasNotices($holder))->toBe([]);
});

it('an assignee without the permission falls back to permission holders', function (): void {
    $outsider = aasUser('outsider', false);
    $holder = aasUser('holder');
    aasRun(['assignees' => [$outsider->uuid], 'sla' => '1h'], $holder);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();

    expect(aasAssigneeIds($approval))->toBe([])
        ->and(data_get($approval->diff_data, 'workflow_node.assignment_fallback'))->toBeTrue()
        ->and($approval->sla_seconds)->toBe(3600)
        ->and($approval->due_at)->not->toBeNull()
        ->and(aasNotices($holder))->toBe(['workflow.approval.requested'])
        ->and(aasNotices($outsider))->toBe([]);

    // Overdue: the permission holders are told, never the outsider.
    $this->travel(2)->hours();
    expect(app(AgentApprovalService::class)->flagOverdueApprovals())->toBe(1)
        ->and(aasNotices($holder))->toBe(['workflow.approval.requested', 'workflow.approval.overdue'])
        ->and(aasNotices($outsider))->toBe([]);
});

it('jev and auto approvals have no sla', function (): void {
    $assignee = aasUser('assignee');
    aasRun(['mode' => 'auto', 'assignees' => [$assignee->uuid], 'sla' => '1h'], $assignee);
    $auto = AgentApproval::query()->latest('id')->firstOrFail();

    app()->bind(JevJudge::class, AasConfidentJudge::class);
    aasRun(['mode' => 'jev', 'assignees' => [$assignee->uuid], 'sla' => '1h', 'jev' => ['criteria' => 'Fine?']], $assignee);
    $jev = AgentApproval::query()->latest('id')->firstOrFail();

    foreach ([$auto, $jev] as $approval) {
        expect($approval->status)->toBe('approved')
            ->and(aasAssigneeIds($approval))->toBe([])
            ->and($approval->sla_seconds)->toBeNull()
            ->and($approval->due_at)->toBeNull()
            ->and($approval->decided_at)->toBeNull()
            ->and($approval->sla_met)->toBeNull();
    }
    expect(aasNotices($assignee))->toBe([]);
});

it('approvals without assignment are unchanged', function (): void {
    $holder = aasUser('holder');
    $instance = aasRun([], $holder);
    $approval = AgentApproval::query()->latest('id')->firstOrFail();

    expect(aasAssigneeIds($approval))->toBe([])
        ->and($approval->sla_seconds)->toBeNull()
        ->and($approval->due_at)->toBeNull()
        ->and(data_get($approval->diff_data, 'workflow_node.assignment_fallback'))->toBeNull()
        ->and(aasNotices($holder))->toBe(['workflow.approval.requested']);

    app(AgentApprovalService::class)->approveAndApply($approval, $holder);
    $approval->refresh();

    // Response time is measured for every human decision; with no SLA nothing is judged.
    expect(data_get($instance->fresh()->context_snapshot, 'vars.went'))->toBe('ok')
        ->and($approval->decided_by_user_id)->toBe($holder->id)
        ->and($approval->response_seconds)->not->toBeNull()
        ->and($approval->sla_met)->toBeNull()
        ->and(app(AgentApprovalService::class)->flagOverdueApprovals())->toBe(0);
});
