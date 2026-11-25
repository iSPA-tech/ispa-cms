<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Tests\Feature\Workflow\Concerns\BuildsWorkflowRuntimeTables;
use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;

/**
 * Usage is summed onto the run and budget caps stop a run before an ai_spend step would pass them.
 */
class WorkflowBudgetTest extends TestCase
{
    use BuildsWorkflowRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkflowRuntimeTables();
        (require base_path('cms/Core/database/migrations/2026_11_24_000011_add_budget_and_usage_to_workflows.php'))->up();

        config()->set('core.workflow_budget.ai_spend_step_types', ['test_ai']);
        config()->set('core.workflow_budget.default_max_cost_per_run', null);
        config()->set('core.workflow_budget.default_max_runs_per_day', null);

        app(StepRunnerRegistry::class)->register('test_ai', new BudgetAiRunner());
    }

    public function test_usage_of_steps_is_summed_onto_the_instance_and_the_step_record(): void
    {
        $project = WorkflowProject::create(['name' => 'Usage']);
        $first = $this->makeStep($project, 'test_ai', 'first', 1, ['cost' => 0.02, 'tokens_in' => 100, 'tokens_out' => 40]);
        $second = $this->makeStep($project, 'test_ai', 'second', 2, ['cost' => 0.03, 'tokens_in' => 50, 'tokens_out' => 10]);
        $this->connect($project, $first, $second);

        $instance = $this->runProject($project);

        $this->assertSame('completed', $instance->status);
        $this->assertSame(150, (int) $instance->usage_tokens_in);
        $this->assertSame(50, (int) $instance->usage_tokens_out);
        $this->assertEqualsWithDelta(0.05, (float) $instance->usage_cost_usd, 0.000001);
        $this->assertEqualsWithDelta(0.05, (float) data_get($instance->context_snapshot, 'vars.__runtime.usage.cost_usd'), 0.000001);

        $stepRow = WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $second->id)->first();
        $this->assertEqualsWithDelta(0.03, (float) data_get($stepRow->result_data, 'usage.cost_usd'), 0.000001);
    }

    public function test_run_stops_before_the_ai_step_that_would_pass_the_cost_cap(): void
    {
        $project = WorkflowProject::create(['name' => 'Cost cap']);
        $project->budget_max_cost_per_run = 0.05;
        $project->save();

        $first = $this->makeStep($project, 'test_ai', 'first', 1, ['cost' => 0.04]);
        $second = $this->makeStep($project, 'test_ai', 'second', 2, ['cost' => 0.01, 'limits' => ['usd_per_call' => 0.02]]);
        $this->connect($project, $first, $second);

        $instance = $this->runProject($project);

        $this->assertSame('budget_exceeded', $instance->status);
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $second->id)->count());
    }

    public function test_config_default_cost_cap_applies_when_the_project_has_none(): void
    {
        config()->set('core.workflow_budget.default_max_cost_per_run', 0.01);

        $project = WorkflowProject::create(['name' => 'Default cap']);
        $first = $this->makeStep($project, 'test_ai', 'first', 1, ['cost' => 0.01]);
        $second = $this->makeStep($project, 'test_ai', 'second', 2, ['cost' => 0.01]);
        $this->connect($project, $first, $second);

        $instance = $this->runProject($project);

        $this->assertSame('budget_exceeded', $instance->status);
        $this->assertSame(1, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $first->id)->count());
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $second->id)->count());
    }

    public function test_missing_usage_on_a_paid_step_stops_the_next_ai_step_when_a_cost_cap_is_set(): void
    {
        $project = WorkflowProject::create(['name' => 'Unknown usage']);
        $project->budget_max_cost_per_run = 5;
        $project->save();

        $first = $this->makeStep($project, 'test_ai', 'first', 1, ['report' => false]);
        $second = $this->makeStep($project, 'test_ai', 'second', 2, ['cost' => 0.01]);
        $this->connect($project, $first, $second);

        $instance = $this->runProject($project);

        $this->assertSame('budget_exceeded', $instance->status);
        $this->assertSame(1, (int) $instance->usage_unknown_calls);
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $second->id)->count());
    }

    public function test_missing_usage_does_not_stop_a_run_without_a_cost_cap(): void
    {
        $project = WorkflowProject::create(['name' => 'No cap']);
        $first = $this->makeStep($project, 'test_ai', 'first', 1, ['report' => false]);
        $second = $this->makeStep($project, 'test_ai', 'second', 2, ['cost' => 0.01]);
        $this->connect($project, $first, $second);

        $this->assertSame('completed', $this->runProject($project)->status);
    }

    public function test_runs_per_day_cap_stops_a_run_before_its_ai_step(): void
    {
        $project = WorkflowProject::create(['name' => 'Daily cap']);
        $project->budget_max_runs_per_day = 1;
        $project->save();
        $this->makeStep($project, 'test_ai', 'only', 1, ['cost' => 0.01]);

        \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'completed', 'started_at' => now()]);

        $instance = $this->runProject($project);

        $this->assertSame('budget_exceeded', $instance->status);
    }

    public function test_an_approval_in_jev_mode_is_budget_checked_like_an_ai_step_and_other_modes_are_not(): void
    {
        // The runner is replaced so the test needs no approval tables; the safety comes from the node definition.
        app(StepRunnerRegistry::class)->register('approval', new BudgetAiRunner());

        foreach (['jev' => 'budget_exceeded', 'human' => 'completed'] as $mode => $expected) {
            $project = WorkflowProject::create(['name' => 'Approval ' . $mode]);
            $project->budget_max_runs_per_day = 1;
            $project->save();
            $this->makeStep($project, 'approval', 'approve', 1, ['mode' => $mode]);
            \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'completed', 'started_at' => now()]);

            $this->assertSame($expected, $this->runProject($project)->status, "mode {$mode}");
        }
    }

    /** Review finding 26: with a cost cap, an AI step that declares no `limits.usd_per_call` is not assumed free. */
    public function test_a_step_without_a_per_call_estimate_uses_the_default_estimate_against_the_cap(): void
    {
        config()->set('core.workflow_budget.default_usd_per_call', 0.02);

        $project = WorkflowProject::create(['name' => 'Default estimate']);
        $project->budget_max_cost_per_run = 0.05;
        $project->save();

        $first = $this->makeStep($project, 'test_ai', 'first', 1, ['cost' => 0.04]);
        $second = $this->makeStep($project, 'test_ai', 'second', 2, ['cost' => 0.04]);
        $this->connect($project, $first, $second);

        $instance = $this->runProject($project);

        $this->assertSame('budget_exceeded', $instance->status);
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $second->id)->count());
    }

    protected function runProject(WorkflowProject $project): WorkflowInstance
    {
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);
        app(WorkflowGraphExecutor::class)->execute($instance->id);

        return $instance->fresh();
    }
}

class BudgetAiRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $cfg = $step->stepConfig;
        $result = StepRunResult::success(['text' => 'ok']);

        if (($cfg['report'] ?? true) === false) {
            return $result;
        }

        return $result->withUsage([
            'task_key' => 'test',
            'provider' => 'fake',
            'model' => 'fake-1',
            'tokens_in' => $cfg['tokens_in'] ?? 0,
            'tokens_out' => $cfg['tokens_out'] ?? 0,
            'cost_usd' => $cfg['cost'] ?? 0,
        ]);
    }
}
