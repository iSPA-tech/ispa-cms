<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Jobs\RunWorkflowInstanceJob;
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
use Illuminate\Support\Facades\Queue;

/**
 * Loops and delays exercised through the executor, not the runners alone.
 */
class WorkflowLoopDelayTest extends TestCase
{
    use BuildsWorkflowRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkflowRuntimeTables();
        LoopBodyRunner::$seen = [];

        $registry = app(StepRunnerRegistry::class);
        $registry->register('test_noop', new LoopNoopRunner());
        $registry->register('test_body', new LoopBodyRunner());
    }

    public function test_loop_each_runs_its_body_once_per_item_then_leaves_by_the_done_edge(): void
    {
        $project = WorkflowProject::create(['name' => 'Loop']);
        $loop = $this->makeStep($project, 'loop_each', 'loop', 1, [
            'collectionExpr' => 'vars.items',
            'itemVar' => 'vars.item',
        ]);
        $body = $this->makeStep($project, 'test_body', 'body', 2);
        $end = $this->makeStep($project, 'test_noop', 'end', 3);
        $this->connect($project, $loop, $body, 'loop');
        $this->connect($project, $body, $loop);
        $this->connect($project, $loop, $end, 'done');

        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'status' => 'pending',
            'context_snapshot' => ['trigger' => [], 'vars' => ['items' => ['a', 'b', 'c']], 'steps' => []],
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id);

        $this->assertSame('completed', $instance->fresh()->status);
        $this->assertSame(['a', 'b', 'c'], LoopBodyRunner::$seen);
        $this->assertSame(1, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $end->id)->count());
    }

    public function test_delay_pauses_the_run_and_the_next_step_runs_on_resume(): void
    {
        Queue::fake();

        $project = WorkflowProject::create(['name' => 'Delay']);
        $start = $this->makeStep($project, 'test_noop', 'start', 1);
        $delay = $this->makeStep($project, 'delay', 'wait', 2, ['seconds' => 60]);
        $after = $this->makeStep($project, 'test_noop', 'after', 3);
        $this->connect($project, $start, $delay);
        $this->connect($project, $delay, $after);

        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $instance->refresh();
        $this->assertSame('waiting', $instance->status);
        $this->assertSame($after->id, (int) $instance->resume_step_id);
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $after->id)->count());
        Queue::assertPushed(RunWorkflowInstanceJob::class, function (RunWorkflowInstanceJob $job) use ($instance) {
            return $job->workflowInstanceId() === $instance->id && $job->delay !== null;
        });

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $this->assertSame('completed', $instance->fresh()->status);
        $this->assertSame(1, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $after->id)->count());
        $this->assertSame(1, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $delay->id)->count());
    }

    public function test_delay_as_the_last_step_completes_without_rerunning_the_workflow(): void
    {
        Queue::fake();

        $project = WorkflowProject::create(['name' => 'Delay last']);
        $start = $this->makeStep($project, 'test_noop', 'start', 1);
        $delay = $this->makeStep($project, 'delay', 'wait', 2, ['seconds' => 60]);
        $this->connect($project, $start, $delay);

        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $this->assertSame('completed', $instance->fresh()->status);
        Queue::assertNothingPushed();
    }
}

class LoopNoopRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::success(['step' => $step->stepCode]);
    }
}

class LoopBodyRunner implements StepRunner
{
    /** @var array<int, mixed> */
    public static array $seen = [];

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        self::$seen[] = $context->getVarPath('item');

        return StepRunResult::success();
    }
}
