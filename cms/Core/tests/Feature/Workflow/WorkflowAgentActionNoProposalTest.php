<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Agents\Attributes\AgentTool;
use Cms\Core\Agents\Tools\AgentToolRegistry;
use Cms\Core\Models\Agents\AgentApproval;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentRun;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;

/**
 * An approval-required ai_action whose tool has nothing to propose completes with `no_proposal`.
 */
class WorkflowAgentActionNoProposalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new NoProposalTestSchema())->migrateTables();
        (require base_path('cms/Core/database/migrations/2026_11_05_120100_add_delegated_by_to_agentic_runs.php'))->up();

        config()->set('core.agentic.discovery.allowlisted_namespaces', array_merge(
            config('core.agentic.discovery.allowlisted_namespaces', []),
            ['Cms\\Core\\Tests\\Feature\\Workflow\\']
        ));
        NoProposalToolService::$output = null;
    }

    public function test_plain_result_completes_the_step_with_the_no_proposal_output(): void
    {
        NoProposalToolService::$output = ['summary' => 'Nothing needs a change.'];
        [$instance, $action, $onNothing, $main] = $this->runGraph();

        $this->assertSame('completed', $instance->status);
        $this->assertSame('success', WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $action->id)->value('status'));
        $this->assertSame(1, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $onNothing->id)->count());
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $main->id)->count());
        $this->assertSame(0, AgentApproval::query()->count());

        $run = AgentRun::query()->latest('id')->first();
        $this->assertSame('completed', $run->status);
        $this->assertSame('no_proposal', $run->terminal_reason);
        $this->assertTrue((bool) data_get($instance->context_snapshot, 'vars.proposal.no_proposal'));
    }

    public function test_the_cost_a_tool_reports_becomes_the_step_usage(): void
    {
        NoProposalToolService::$output = ['summary' => 'Nothing needs a change.', 'cost_usd' => 0.042];
        [$instance, $action] = $this->runGraph();

        $step = WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $action->id)->first();
        $this->assertEqualsWithDelta(0.042, (float) data_get($step->result_data, 'usage.cost_usd'), 0.00001);
    }

    public function test_null_result_also_counts_as_nothing_to_propose(): void
    {
        NoProposalToolService::$output = null;
        [$instance] = $this->runGraph();

        $this->assertSame('completed', $instance->status);
        $this->assertSame(0, AgentApproval::query()->count());
    }

    public function test_malformed_envelope_still_fails_the_step(): void
    {
        NoProposalToolService::$output = ['artifact_type' => 'content_post_description_patch'];
        [$instance, $action] = $this->runGraph();

        $this->assertSame('failed', $instance->status);
        $this->assertSame('failed', WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $action->id)->value('status'));
        $this->assertSame('invalid_approval_artifact_envelope', AgentRun::query()->latest('id')->value('terminal_reason'));
    }

    /**
     * @return array{0: WorkflowInstance, 1: WorkflowStep, 2: WorkflowStep, 3: WorkflowStep}
     */
    protected function runGraph(): array
    {
        $project = WorkflowProject::create(['name' => 'Nothing to propose']);
        $action = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'ai_action',
            'step_code' => 'propose',
            'position_x' => 1,
            'step_config' => [
                'agentProfileSlug' => 'no-proposal-proposer',
                'toolClass' => NoProposalToolService::class,
                'toolName' => 'core.test_propose_nothing',
                'moduleScope' => 'core',
                'storeAs' => 'vars.proposal',
            ],
        ]);
        $onNothing = WorkflowStep::create([
            'project_id' => $project->id, 'step_type' => 'log_message', 'step_code' => 'nothing',
            'position_x' => 2, 'step_config' => ['message' => 'nothing to do'],
        ]);
        $main = WorkflowStep::create([
            'project_id' => $project->id, 'step_type' => 'log_message', 'step_code' => 'main',
            'position_x' => 3, 'step_config' => ['message' => 'proposal path'],
        ]);
        foreach ([[$onNothing, 'no_proposal'], [$main, null]] as [$to, $handle]) {
            WorkflowConnection::create([
                'project_id' => $project->id,
                'from_step_id' => $action->id,
                'to_step_id' => $to->id,
                'condition' => $handle ? ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle] : null,
            ]);
        }

        $profile = AgentProfile::create([
            'name' => 'No Proposal Proposer',
            'slug' => 'no-proposal-proposer',
            'capability_class' => 'class_2_staged_mutation',
            'status' => 'active',
            'budget_config' => ['max_steps' => 5],
        ]);
        app(AgentToolRegistry::class)->syncDiscoveredService(NoProposalToolService::class, 'core', 'testing');
        $definition = AgentToolDefinition::query()->where('tool_name', 'core.test_propose_nothing')->firstOrFail();
        $profile->toolDefinitions()->syncWithoutDetaching([
            $definition->id => ['enabled' => true, 'safety_override' => null],
        ]);

        $target = NoProposalTarget::query()->create(['name' => 'Target']);
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'target_type' => NoProposalTarget::class,
            'target_id' => $target->id,
            'status' => 'pending',
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id);

        return [$instance->fresh(), $action, $onNothing, $main];
    }
}

/**
 * Exposes the shared agent-action workflow schema (no module tables) to this test.
 */
class NoProposalTestSchema
{
    use \Cms\Core\Tests\Support\AgentActionWorkflowSchema {
        migrateAgentActionWorkflowTables as public migrateTables;
    }
}

class NoProposalToolService
{
    public static mixed $output = null;

    #[AgentTool(
        name: 'core.test_propose_nothing',
        family: 'core',
        moduleScope: 'core',
        contractSteward: 'core',
        sideEffectClass: 'staged_write',
        approvalRequired: true,
        capabilityClass: 'class_2_staged_mutation',
        description: 'Test tool that may have nothing to propose.'
    )]
    public function proposeNothingTool(): mixed
    {
        return self::$output;
    }
}

class NoProposalTarget extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'agent_action_dummy_targets';
    protected $guarded = [];
}
