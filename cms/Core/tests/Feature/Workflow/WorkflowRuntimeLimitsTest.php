<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Tests\Feature\Workflow\Concerns\BuildsWorkflowRuntimeTables;
use Cms\Core\Tests\Support\WorkflowTestVersions;
use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Runtime\Contracts\IdempotentStepRunner;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Queue;

/**
 * Run-wide limits of the graph executor (review findings 8, 11, 19, 20).
 */
class WorkflowRuntimeLimitsTest extends TestCase
{
    use BuildsWorkflowRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkflowRuntimeTables();
        LimitsCountingRunner::$calls = 0;
    }

    /** Finding 8: the attempt count survives the reschedule, so `retry.max` ends the run. */
    public function test_queued_retry_with_backoff_stops_at_max_attempts(): void
    {
        Queue::fake();
        app(StepRunnerRegistry::class)->register('limits_always_fails', new LimitsAlwaysFailsRunner());

        $project = WorkflowProject::create(['name' => 'Retry cap', 'is_active' => true]);
        $this->makeStep($project, 'limits_always_fails', 'post', 0, ['retry' => ['max' => 3, 'backoffSeconds' => [30, 30]]]);
        $instance = WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        // Each queued execution is one attempt; the job a reschedule dispatches is simulated by calling execute again.
        for ($i = 0; $i < 10 && $instance->refresh()->status !== 'failed'; $i++) {
            app(WorkflowGraphExecutor::class)->execute($instance->id, true);
        }

        $this->assertSame('failed', $instance->refresh()->status);
        $this->assertSame(3, LimitsCountingRunner::$calls);
        // Queue::fake keeps the unique lock of the first pushed job, so later pushes of the same run are not recorded.
        Queue::assertPushed(RunWorkflowInstanceJob::class);
        $this->assertSame(3, $instance->steps()->where('status', 'failed')->whereNotNull('step_id')->count());
    }

    /** Finding 19: a step that finished after `timeoutSeconds` failed, but is not run again. */
    public function test_slow_step_that_succeeded_is_not_retried(): void
    {
        app(StepRunnerRegistry::class)->register('limits_slow', new LimitsSlowRunner());

        $project = WorkflowProject::create(['name' => 'Slow', 'is_active' => true]);
        $this->makeStep($project, 'limits_slow', 'send', 0, ['timeoutSeconds' => 1, 'retry' => ['max' => 3, 'backoffSeconds' => [0, 0]]]);
        $instance = WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        app(WorkflowGraphExecutor::class)->execute($instance->id, false);

        $this->assertSame('failed', $instance->refresh()->status);
        $this->assertSame(1, LimitsCountingRunner::$calls);
    }

    /** Finding 19: a runner declared idempotent may be retried after a timeout. */
    public function test_slow_idempotent_step_is_retried(): void
    {
        app(StepRunnerRegistry::class)->register('limits_slow_idempotent', new LimitsSlowIdempotentRunner());

        $project = WorkflowProject::create(['name' => 'Slow read', 'is_active' => true]);
        $this->makeStep($project, 'limits_slow_idempotent', 'read', 0, ['timeoutSeconds' => 1, 'retry' => ['max' => 2, 'backoffSeconds' => [0]]]);
        $instance = WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        app(WorkflowGraphExecutor::class)->execute($instance->id, false);

        $this->assertSame(2, LimitsCountingRunner::$calls);
    }

    /** Finding 20: a cycle through a delay node is counted across executions and ends at max_steps. */
    public function test_cycle_through_delay_ends_at_run_wide_step_cap(): void
    {
        Queue::fake();
        config(['core.workflow.max_steps' => 6]);
        app(StepRunnerRegistry::class)->register('limits_count', new LimitsCountingRunner());

        $project = WorkflowProject::create(['name' => 'Delay cycle', 'is_active' => true]);
        $work = $this->makeStep($project, 'limits_count', 'work', 0);
        $wait = $this->makeStep($project, 'delay', 'wait', 1, ['seconds' => 5]);
        $this->connect($project, $work, $wait);
        $this->connect($project, $wait, $work);
        $instance = WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        for ($i = 0; $i < 20 && $instance->refresh()->status !== 'failed'; $i++) {
            app(WorkflowGraphExecutor::class)->execute($instance->id, true);
        }

        $this->assertSame('failed', $instance->refresh()->status);
        $this->assertLessThanOrEqual(3, LimitsCountingRunner::$calls);
    }

    /** Finding 20: the default cap fits a legal loop of max_loop_items with a small body. */
    public function test_default_step_cap_fits_a_full_loop_with_a_small_body(): void
    {
        $this->assertGreaterThanOrEqual(
            (int) config('core.workflow.max_loop_items') * 10,
            (int) config('core.workflow.max_steps')
        );
    }

    /** Finding 26: one run may not make more outside calls than `actions_per_run`. */
    public function test_a_run_stops_before_its_outside_call_over_the_per_run_cap(): void
    {
        config(['core.workflow_guard.breaker.actions_per_run' => 2]);
        app(StepRunnerRegistry::class)->register('http_request', new LimitsCountingRunner());

        $project = WorkflowProject::create(['name' => 'Many calls', 'is_active' => true]);
        $a = $this->makeStep($project, 'http_request', 'a', 0, ['method' => 'GET', 'url' => 'https://example.com']);
        $b = $this->makeStep($project, 'http_request', 'b', 1, ['method' => 'GET', 'url' => 'https://example.com']);
        $c = $this->makeStep($project, 'http_request', 'c', 2, ['method' => 'GET', 'url' => 'https://example.com']);
        $this->connect($project, $a, $b);
        $this->connect($project, $b, $c);
        $instance = WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        app(WorkflowGraphExecutor::class)->execute($instance->id, false);

        $this->assertSame('failed', $instance->refresh()->status);
        $this->assertSame(2, LimitsCountingRunner::$calls);
    }

    /** Finding 11: an in-flight run that is claimed while every workflow is paused goes back to `waiting`. */
    public function test_global_pause_stops_a_resumed_run_before_its_next_step(): void
    {
        Queue::fake();
        app(StepRunnerRegistry::class)->register('limits_count', new LimitsCountingRunner());

        $project = WorkflowProject::create(['name' => 'Paused', 'is_active' => true]);
        $this->makeStep($project, 'limits_count', 'work', 0);
        $instance = WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'waiting']);

        config(['core.workflow_guard.paused_all' => true]);
        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $this->assertSame('waiting', $instance->refresh()->status);
        $this->assertSame(0, LimitsCountingRunner::$calls);
        Queue::assertPushed(RunWorkflowInstanceJob::class);

        config(['core.workflow_guard.paused_all' => false]);
        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $this->assertSame('completed', $instance->refresh()->status);
        $this->assertSame(1, LimitsCountingRunner::$calls);
    }
}

class LimitsCountingRunner implements StepRunner
{
    public static int $calls = 0;

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        self::$calls++;

        return StepRunResult::success(['n' => self::$calls]);
    }
}

class LimitsAlwaysFailsRunner extends LimitsCountingRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        self::$calls++;

        return StepRunResult::failed('endpoint down');
    }
}

class LimitsSlowRunner extends LimitsCountingRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        self::$calls++;
        sleep(2);

        return StepRunResult::success(['sent' => true]);
    }
}

class LimitsSlowIdempotentRunner extends LimitsSlowRunner implements IdempotentStepRunner
{
}
