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
 * A job that read the instance before another execution advanced it must continue from the stored cursor.
 */
class WorkflowStaleClaimTest extends TestCase
{
    use BuildsWorkflowRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkflowRuntimeTables();
        app(StepRunnerRegistry::class)->register('test_noop', new StaleClaimNoopRunner());
    }

    public function test_a_stale_job_that_claims_after_the_cursor_moved_does_not_repeat_earlier_steps(): void
    {
        $project = WorkflowProject::create(['name' => 'Stale']);
        $first = $this->makeStep($project, 'test_noop', 'first', 1);
        $second = $this->makeStep($project, 'test_noop', 'second', 2);
        $this->connect($project, $first, $second);

        // The job loaded the instance while its cursor still pointed at `first`.
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'status' => 'waiting',
            'resume_step_id' => $first->id,
            'context_snapshot' => ['trigger' => [], 'vars' => ['progress' => 'old'], 'steps' => []],
        ]);

        // Before this job claims, another execution ran `first` and rescheduled the run at `second`.
        $executor = app(StaleClaimExecutor::class);
        $executor->beforeClaim = function () use ($instance, $second) {
            WorkflowInstance::query()->whereKey($instance->id)->update([
                'status' => 'waiting',
                'resume_step_id' => $second->id,
                'context_snapshot' => json_encode(['trigger' => [], 'vars' => ['progress' => 'new'], 'steps' => []]),
            ]);
        };

        $executor->execute($instance->id, true);

        $this->assertSame('completed', $instance->fresh()->status);
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $first->id)->count());
        $this->assertSame(1, WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $second->id)->count());
        $this->assertSame('new', StaleClaimNoopRunner::$progress);
    }
}

class StaleClaimExecutor extends WorkflowGraphExecutor
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $beforeClaim = null;

    protected function claimInstance(WorkflowInstance $instance): bool
    {
        if ($this->beforeClaim) {
            ($this->beforeClaim)();
        }

        return parent::claimInstance($instance);
    }
}

class StaleClaimNoopRunner implements StepRunner
{
    public static mixed $progress = null;

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        self::$progress = $context->getVarPath('progress');

        return StepRunResult::success();
    }
}
