<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Agents\Attributes\AgentTool;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Agentic\AgentApprovalService;
use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;

require_once __DIR__ . '/WorkflowAgentActionNoProposalTest.php';

/**
 * An ai_action proposal carries review meta (`diff_data.workflow_node`, kind `proposal`), and a requested rewrite runs
 * the same step again, calling the tool with the revision.
 */
class WorkflowAgentActionRevisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new NoProposalTestSchema())->migrateTables();
        (require base_path('cms/Core/database/migrations/2026_11_05_120100_add_delegated_by_to_agentic_runs.php'))->up();
        \Illuminate\Support\Facades\Schema::table('users', static function ($table): void {
            $table->uuid('uuid')->nullable();
            $table->softDeletes();
        });

        config()->set('core.agentic.discovery.allowlisted_namespaces', array_merge(
            config('core.agentic.discovery.allowlisted_namespaces', []),
            ['Cms\\Core\\Tests\\Feature\\Workflow\\']
        ));
        RevisableToolService::$revisions = [];
    }

    public function test_it_stores_review_meta_on_an_ai_action_proposal(): void
    {
        [$instance] = $this->runGraph();
        $approval = AgentApproval::query()->sole();
        $meta = $approval->diff_data['workflow_node'];

        $this->assertSame('awaiting_approval', $instance->status);
        $this->assertSame('proposal', $meta['kind']);
        $this->assertSame('propose', $meta['step_code']);
        $this->assertSame($instance->id, $meta['instance_id']);
        // A plain name under proposed_change is normalised like the approval node does.
        $this->assertSame(['proposed_change.body', 'proposed_change.title'], $meta['editable_fields']);
        $this->assertTrue($meta['revisable']);
        $this->assertSame('length', $meta['revision_options'][0]['key']);
        $this->assertSame(1, $meta['round']);
        $this->assertSame(3, $meta['max_rounds']);
        $this->assertNull($meta['previous_approval_id']);
        $this->assertNull($meta['decided_by']);
        $this->assertSame([null], RevisableToolService::$revisions);
    }

    public function test_it_reruns_the_tool_with_the_revision_after_changes_are_requested(): void
    {
        [$instance, $action, $next] = $this->runGraph();
        $first = AgentApproval::query()->sole();
        $reviewer = \App\Models\User::withoutEvents(static fn () => \App\Models\User::query()->forceCreate(['name' => 'Reviewer', 'email' => 'reviewer-rev@example.com', 'password' => 'x']));

        $result = app(AgentApprovalService::class)->requestChanges($first, $reviewer, 'Shorter please', 'Founded 1901', ['length' => 'shorter']);
        $instance->refresh();
        $first->refresh();
        $second = AgentApproval::query()->latest('id')->first();

        $this->assertSame($instance->id, $result['resume_workflow_instance_id']);
        $this->assertSame('changes_requested', $first->status);
        $this->assertSame('awaiting_approval', $instance->status);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('pending', $second->status);
        $this->assertSame(2, $second->diff_data['workflow_node']['round']);
        $this->assertSame($first->id, $second->diff_data['workflow_node']['previous_approval_id']);
        $this->assertSame($second->id, $first->diff_data['workflow_node']['next_approval_id']);
        $this->assertSame('Shorter: Old', $second->artifact->payload['proposed_change']['body']);
        // The next step has not run: the rewrite waits for a person again.
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $next->id)->count());

        $revision = RevisableToolService::$revisions[1];
        $this->assertSame(1, $revision['round']);
        $this->assertSame($first->id, $revision['previous_approval_id']);
        $this->assertSame('Shorter please', $revision['feedback']);
        $this->assertSame('Founded 1901', $revision['added_info']);
        $this->assertSame(['length' => 'shorter'], $revision['options']);
        $this->assertSame('Old', $revision['previous_payload']['proposed_change']['body']);

        // The reviewer may edit the declared fields of the rewrite before approving it.
        app(AgentApprovalService::class)->approveWithEdits($second, $reviewer, null, ['proposed_change.body' => 'Edited']);
        $this->assertSame('approved', $second->fresh()->status);
        $this->assertSame('Edited', $second->fresh()->artifact->payload['proposed_change']['body']);
    }

    public function test_ai_action_proposal_gets_assignees_and_sla_on_every_round(): void
    {
        (require base_path('cms/Core/database/migrations/2026_11_24_000070_add_assignment_and_sla_to_agent_approvals.php'))->up();
        $assignee = \App\Models\User::withoutEvents(static fn () => \App\Models\User::query()->forceCreate(['name' => 'Assignee', 'email' => 'assignee-sla@example.com', 'password' => 'x', 'uuid' => (string) \Illuminate\Support\Str::uuid()]));
        $this->grantReviewer($assignee);

        $this->runGraph(['assignees' => [$assignee->uuid], 'sla' => '2h']);
        $first = AgentApproval::query()->sole();

        $this->assertSame([$assignee->id], $first->assignees()->pluck('users.id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame(7200, $first->sla_seconds);
        $this->assertNotNull($first->due_at);

        $this->travel(30)->minutes();
        app(AgentApprovalService::class)->requestChanges($first, $assignee, 'Shorter please', null, []);
        $first->refresh();
        $second = AgentApproval::query()->latest('id')->first();

        $this->assertSame($assignee->id, $first->decided_by_user_id);
        $this->assertTrue($first->sla_met);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame([$assignee->id], $second->assignees()->pluck('users.id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame(7200, $second->sla_seconds);
        $this->assertNull($second->decided_at);
    }

    public function test_a_step_can_turn_the_rewrite_off(): void
    {
        $this->runGraph(['allow_revision' => false]);

        $this->assertFalse(AgentApproval::query()->sole()->diff_data['workflow_node']['revisable']);
    }

    public function test_ai_action_routes_rejected_when_the_edge_exists(): void
    {
        [$instance, $action, $next] = $this->runGraph([], rejectedEdge: true);
        $approval = AgentApproval::query()->sole();
        $reviewer = \App\Models\User::withoutEvents(static fn () => \App\Models\User::query()->forceCreate(['name' => 'Reviewer', 'email' => 'reviewer-rej@example.com', 'password' => 'x']));
        $this->assertSame(['wrong_info', 'skip_it'], array_column($approval->diff_data['workflow_node']['reject_reasons'], 'key'));
        $this->assertSame('Skip it', $approval->diff_data['workflow_node']['reject_reasons'][1]['label']);

        $result = app(AgentApprovalService::class)->reject($approval, $reviewer, 'Facts are off', 'wrong_info');
        $instance->refresh();
        $onRejected = WorkflowStep::query()->where('project_id', $instance->project_id)->where('step_code', 'on_rejected')->sole();

        $this->assertSame('rejected', $result['approval']->status);
        $this->assertSame('completed', $instance->status);
        $this->assertSame(
            ['decision' => 'rejected', 'reason' => 'wrong_info', 'note' => 'Facts are off', 'approval_id' => $approval->id],
            data_get($instance->context_snapshot, 'vars.proposal'),
        );
        // The tool was not called again, the `rejected` path ran, the main path did not.
        $this->assertSame([null], RevisableToolService::$revisions);
        $this->assertSame(1, AgentApproval::query()->count());
        $this->assertSame(1, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $onRejected->id)->count());
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $next->id)->count());
    }

    public function test_ai_action_without_a_rejected_edge_ends_as_before(): void
    {
        [$instance, $action, $next] = $this->runGraph();
        $approval = AgentApproval::query()->sole();

        $result = app(AgentApprovalService::class)->reject($approval, null, 'No');
        $instance->refresh();

        // Same statuses as before reject routing existed: the instance ends, nothing resumes.
        $this->assertSame('approval_rejected', $instance->status);
        $this->assertNotNull($instance->finished_at);
        $this->assertSame('rejected', $result['run']->status);
        $this->assertSame('approval_rejected', $result['run']->post_approval_outcome);
        $this->assertSame([null], RevisableToolService::$revisions);
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $next->id)->count());
    }

    /**
     * Minimal role tables of this hand-built schema, and a role holding `agentic.approve` for the user.
     */
    protected function grantReviewer(\App\Models\User $user): void
    {
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        $schema->create('roles', static function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->json('custom')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('role_user', static function ($table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
        });
        $schema->create('permissions', static function ($table): void {
            $table->id();
            $table->string('group')->nullable();
            $table->string('name')->nullable();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('permission_role', static function ($table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });

        $role = \Cms\Core\Models\Role::query()->create(['name' => 'Reviewer', 'custom' => ['level' => 50]]);
        $role->permissions()->attach(\Cms\Core\Models\Permission::query()->create(['group' => 'agentic', 'name' => 'approve', 'title' => 'agentic.approve']));
        $user->roles()->attach($role);
    }

    /**
     * @return array{0: WorkflowInstance, 1: WorkflowStep, 2: WorkflowStep}
     */
    protected function runGraph(array $extraConfig = [], bool $rejectedEdge = false): array
    {
        $project = WorkflowProject::create(['name' => 'Revisable proposal']);
        $action = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'ai_action',
            'step_code' => 'propose',
            'position_x' => 1,
            'step_config' => [
                'agentProfileSlug' => 'revisable-proposer',
                'toolClass' => RevisableToolService::class,
                'toolName' => 'core.test_propose_revisable',
                'moduleScope' => 'core',
                'storeAs' => 'vars.proposal',
            ] + $extraConfig,
        ]);
        $next = WorkflowStep::create([
            'project_id' => $project->id, 'step_type' => 'log_message', 'step_code' => 'after',
            'position_x' => 2, 'step_config' => ['message' => 'after approval'],
        ]);
        WorkflowConnection::create(['project_id' => $project->id, 'from_step_id' => $action->id, 'to_step_id' => $next->id, 'condition' => null]);
        if ($rejectedEdge) {
            $onRejected = WorkflowStep::create([
                'project_id' => $project->id, 'step_type' => 'log_message', 'step_code' => 'on_rejected',
                'position_x' => 3, 'step_config' => ['message' => 'rejected'],
            ]);
            WorkflowConnection::create([
                'project_id' => $project->id, 'from_step_id' => $action->id, 'to_step_id' => $onRejected->id,
                'condition' => ['source_handle' => 'rejected', 'condition_type' => 'equals', 'condition_value' => 'rejected'],
            ]);
        }

        $profile = AgentProfile::create([
            'name' => 'Revisable Proposer',
            'slug' => 'revisable-proposer',
            'capability_class' => 'class_2_staged_mutation',
            'status' => 'active',
            'budget_config' => ['max_steps' => 5],
        ]);
        app(AgentToolRegistry::class)->syncDiscoveredService(RevisableToolService::class, 'core', 'testing');
        $definition = AgentToolDefinition::query()->where('tool_name', 'core.test_propose_revisable')->firstOrFail();
        $profile->toolDefinitions()->syncWithoutDetaching([$definition->id => ['enabled' => true, 'safety_override' => null]]);

        $target = NoProposalTarget::query()->create(['name' => 'Target']);
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'target_type' => NoProposalTarget::class,
            'target_id' => $target->id,
            'status' => 'pending',
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id);

        return [$instance->fresh(), $action, $next];
    }
}

class RevisableToolService
{
    /** @var list<array<string, mixed>|null> */
    public static array $revisions = [];

    #[AgentTool(
        name: 'core.test_propose_revisable',
        family: 'core',
        moduleScope: 'core',
        contractSteward: 'core',
        sideEffectClass: 'staged_write',
        approvalRequired: true,
        capabilityClass: 'class_2_staged_mutation',
        description: 'Test tool whose proposal may be rewritten.'
    )]
    public function proposeRevisableTool(?array $revision = null): array
    {
        self::$revisions[] = $revision;
        $body = $revision === null ? 'Old' : 'Shorter: ' . $revision['previous_payload']['proposed_change']['body'];

        return [
            'artifact_type' => 'test_revisable_patch',
            'payload' => ['summary' => 'Proposal', 'proposed_change' => ['title' => 'T', 'body' => $body]],
            'editable_fields' => ['body', 'title'],
            'revisable' => true,
            'revision_options' => [['key' => 'length', 'label_key' => 'text.test.length', 'choices' => [['value' => 'shorter', 'label_key' => 'text.test.shorter']]]],
            'reject_reasons' => [['key' => 'wrong_info', 'label_key' => 'text.test.wrong'], ['key' => 'skip_it', 'label' => 'Skip it']],
        ];
    }
}
