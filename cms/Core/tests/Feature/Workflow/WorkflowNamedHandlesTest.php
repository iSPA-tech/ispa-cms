<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Tests\Feature\Workflow\Concerns\BuildsWorkflowRuntimeTables;
use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;

/**
 * The executor routes by a runner's named output matched against the edge `source_handle`.
 */
class WorkflowNamedHandlesTest extends TestCase
{
    use BuildsWorkflowRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkflowRuntimeTables();

        $registry = app(StepRunnerRegistry::class);
        $registry->register('test_output', new HandleOutputRunner());
        $registry->register('test_noop', new HandleNoopRunner());
    }

    public function test_switch_case_output_follows_the_matching_case_edge(): void
    {
        [$project, $start] = $this->project('case:b');
        $a = $this->makeStep($project, 'test_noop', 'a', 2);
        $b = $this->makeStep($project, 'test_noop', 'b', 3);
        $this->edge($project, $start, $a, 'case:a');
        $this->edge($project, $start, $b, 'case:b');

        $instance = $this->runProject($project);

        $this->assertSame('completed', $instance->status);
        $this->assertRan($instance, $b);
        $this->assertNotRan($instance, $a);
    }

    public function test_unmatched_case_falls_back_to_the_default_edge(): void
    {
        [$project, $start] = $this->project('case:zzz');
        $a = $this->makeStep($project, 'test_noop', 'a', 2);
        $fallback = $this->makeStep($project, 'test_noop', 'fallback', 3);
        $this->edge($project, $start, $a, 'case:a');
        $this->edge($project, $start, $fallback, 'default');

        $instance = $this->runProject($project);

        $this->assertSame('completed', $instance->status);
        $this->assertRan($instance, $fallback);
        $this->assertNotRan($instance, $a);
    }

    public function test_unmatched_case_without_default_ends_the_branch(): void
    {
        [$project, $start] = $this->project('case:zzz');
        $a = $this->makeStep($project, 'test_noop', 'a', 2);
        $b = $this->makeStep($project, 'test_noop', 'b', 3);
        $this->edge($project, $start, $a, 'case:a');
        $this->edge($project, $start, $b, 'case:b');

        $instance = $this->runProject($project);

        $this->assertSame('completed', $instance->status);
        $this->assertNotRan($instance, $a);
        $this->assertNotRan($instance, $b);
    }

    public function test_a_single_branch_edge_for_another_output_is_not_taken(): void
    {
        [$project, $start] = $this->project('false');
        $yes = $this->makeStep($project, 'test_noop', 'yes', 2);
        $this->edge($project, $start, $yes, 'true');

        $instance = $this->runProject($project);

        $this->assertSame('completed', $instance->status);
        $this->assertNotRan($instance, $yes);
    }

    public function test_true_false_handles_accept_the_legacy_yes_no_outputs(): void
    {
        [$project, $start] = $this->project('no');
        $yes = $this->makeStep($project, 'test_noop', 'yes', 2);
        $no = $this->makeStep($project, 'test_noop', 'no', 3);
        $this->edge($project, $start, $yes, 'true');
        $this->edge($project, $start, $no, 'false');

        $instance = $this->runProject($project);

        $this->assertRan($instance, $no);
        $this->assertNotRan($instance, $yes);
    }

    public function test_approval_outputs_route_by_name(): void
    {
        [$project, $start] = $this->project('rejected');
        $approved = $this->makeStep($project, 'test_noop', 'approved', 2);
        $rejected = $this->makeStep($project, 'test_noop', 'rejected', 3);
        $this->edge($project, $start, $approved, 'approved');
        $this->edge($project, $start, $rejected, 'rejected');

        $instance = $this->runProject($project);

        $this->assertRan($instance, $rejected);
        $this->assertNotRan($instance, $approved);
    }

    public function test_a_legacy_output_handle_is_the_plain_output_next_to_a_named_edge(): void
    {
        // Older designer saves stored the main output as `output`; with a second (named) edge the run used to fail
        // with "Ambiguous next step".
        [$project, $start] = $this->project(null);
        $next = $this->makeStep($project, 'test_noop', 'next', 2);
        $rejected = $this->makeStep($project, 'test_noop', 'rejected', 3);
        $this->edge($project, $start, $next, 'output');
        $this->edge($project, $start, $rejected, 'rejected');

        $instance = $this->runProject($project);

        $this->assertSame('completed', $instance->status);
        $this->assertRan($instance, $next);
        $this->assertNotRan($instance, $rejected);
    }

    public function test_plain_output_still_follows_a_single_unnamed_edge(): void
    {
        [$project, $start] = $this->project(null);
        $next = $this->makeStep($project, 'test_noop', 'next', 2);
        $this->edge($project, $start, $next, null);

        $instance = $this->runProject($project);

        $this->assertRan($instance, $next);
    }

    /**
     * @return array{0: WorkflowProject, 1: WorkflowStep}
     */
    protected function project(?string $output): array
    {
        $project = WorkflowProject::create(['name' => 'Handles']);
        $start = $this->makeStep($project, 'test_output', 'start', 1, ['output' => $output]);

        return [$project, $start];
    }

    protected function edge(WorkflowProject $project, WorkflowStep $from, WorkflowStep $to, ?string $handle): void
    {
        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $from->id,
            'to_step_id' => $to->id,
            // Shape the designer saves: source_handle plus the equals condition derived from it.
            'condition' => $handle === null ? null : [
                'source_handle' => $handle,
                'condition_type' => 'equals',
                'condition_value' => $handle,
            ],
        ]);
    }

    protected function runProject(WorkflowProject $project): WorkflowInstance
    {
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);
        app(WorkflowGraphExecutor::class)->execute($instance->id);

        return $instance->fresh();
    }

    protected function assertRan(WorkflowInstance $instance, WorkflowStep $step): void
    {
        $this->assertSame(1, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $step->id)->count());
    }

    protected function assertNotRan(WorkflowInstance $instance, WorkflowStep $step): void
    {
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $step->id)->count());
    }
}

class HandleOutputRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $output = $step->stepConfig['output'] ?? null;

        return StepRunResult::success(['ok' => true])->withOutput(is_string($output) ? $output : null);
    }
}

class HandleNoopRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::success();
    }
}
