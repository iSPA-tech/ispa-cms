<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Carbon\CarbonImmutable;
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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;

/**
 * One run at a time, inactive projects never run, and long runs continue instead of failing.
 */
class WorkflowRunGuardsTest extends TestCase
{
    use BuildsWorkflowRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkflowRuntimeTables();

        $registry = app(StepRunnerRegistry::class);
        $registry->register('guard_noop', new GuardNoopRunner());
        $registry->register('guard_slow', new GuardSlowRunner());
        $registry->register('guard_pause', new GuardPauseRunner());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{0: WorkflowProject, 1: \Cms\Core\Models\Workflows\WorkflowStep, 2: \Cms\Core\Models\Workflows\WorkflowStep}
     */
    protected function linearProject(?bool $active = true): array
    {
        $project = WorkflowProject::create(['name' => 'Guards', 'is_active' => $active]);
        $first = $this->makeStep($project, 'guard_noop', 'first', 1);
        $second = $this->makeStep($project, 'guard_noop', 'second', 2);
        $this->connect($project, $first, $second);

        return [$project, $first, $second];
    }

    public function test_an_instance_another_worker_already_claimed_is_not_run_again(): void
    {
        [$project] = $this->linearProject();
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'running']);

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);
        (new RunWorkflowInstanceJob($instance->id))->handle(
            app(WorkflowGraphExecutor::class),
            app(\Cms\Core\Services\Agentic\AgentRunControlService::class),
        );

        $this->assertSame('running', $instance->fresh()->status);
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->count());
    }

    public function test_an_instance_awaiting_approval_is_not_resumed_before_apply(): void
    {
        [$project, , $second] = $this->linearProject();
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'status' => 'awaiting_approval',
            'resume_step_id' => $second->id,
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $this->assertSame('awaiting_approval', $instance->fresh()->status);
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->count());
    }

    public function test_an_applied_instance_resumes_from_its_cursor(): void
    {
        [$project, $first, $second] = $this->linearProject();
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'status' => 'applied',
            'resume_step_id' => $second->id,
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $this->assertSame('completed', $instance->fresh()->status);
        $this->assertSame([$second->id], WorkflowInstanceStep::where('instance_id', $instance->id)->pluck('step_id')->all());
    }

    public function test_the_job_is_unique_per_instance(): void
    {
        $job = new RunWorkflowInstanceJob(42);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('42', (string) $job->uniqueId());

        Queue::fake();
        RunWorkflowInstanceJob::dispatch(42);
        RunWorkflowInstanceJob::dispatch(42);
        RunWorkflowInstanceJob::dispatch(43);

        Queue::assertPushed(RunWorkflowInstanceJob::class, 2);
    }

    public function test_an_inactive_project_does_not_start(): void
    {
        [$project] = $this->linearProject(false);
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $instance->refresh();
        $this->assertSame('cancelled', $instance->status);
        $this->assertNull($instance->resume_step_id);
        $rows = WorkflowInstanceStep::where('instance_id', $instance->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->step_id);
        $this->assertSame('project_inactive', $rows->first()->error_message);
    }

    public function test_an_inactive_project_does_not_resume(): void
    {
        [$project, , $second] = $this->linearProject(false);
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'status' => 'applied',
            'resume_step_id' => $second->id,
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $this->assertSame('cancelled', $instance->fresh()->status);
        $this->assertSame(0, WorkflowInstanceStep::where('instance_id', $instance->id)->whereNotNull('step_id')->count());
    }

    public function test_max_duration_defaults_to_sixty_seconds(): void
    {
        $this->assertSame(60, (int) config('core.workflow.max_duration_seconds'));
    }

    public function test_a_long_step_followed_by_an_approval_pause_never_fails_on_duration(): void
    {
        Queue::fake();
        config(['core.workflow.max_duration_seconds' => 60]);

        $project = WorkflowProject::create(['name' => 'Long', 'is_active' => true]);
        $slow = $this->makeStep($project, 'guard_slow', 'slow', 1);
        $pause = $this->makeStep($project, 'guard_pause', 'pause', 2);
        $this->connect($project, $slow, $pause);

        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $instance->refresh();
        $this->assertNotSame('failed', $instance->status);
        $this->assertSame('waiting', $instance->status);
        $this->assertSame($pause->id, (int) $instance->resume_step_id);
        Queue::assertPushed(RunWorkflowInstanceJob::class);

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $this->assertSame('awaiting_approval', $instance->fresh()->status);
    }

    public function test_a_run_that_keeps_exceeding_the_duration_eventually_fails(): void
    {
        Queue::fake();
        config(['core.workflow.max_duration_seconds' => 60, 'core.workflow.max_duration_continuations' => 2]);

        $project = WorkflowProject::create(['name' => 'Endless', 'is_active' => true]);
        $slow = $this->makeStep($project, 'guard_slow', 'slow', 1);
        $this->connect($project, $slow, $slow);

        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance(['project_id' => $project->id, 'status' => 'pending']);

        for ($i = 0; $i < 5 && $instance->fresh()->status !== 'failed'; $i++) {
            app(WorkflowGraphExecutor::class)->execute($instance->id, true);
        }

        $this->assertSame('failed', $instance->fresh()->status);
        $this->assertSame(
            'Workflow execution timeout',
            WorkflowInstanceStep::where('instance_id', $instance->id)->whereNull('step_id')->value('error_message')
        );
    }
}

class GuardNoopRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::success(['step' => $step->stepCode]);
    }
}

class GuardSlowRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $later = CarbonImmutable::now()->addSeconds(90);
        CarbonImmutable::setTestNow($later);
        \Illuminate\Support\Carbon::setTestNow($later);

        return StepRunResult::success(['step' => $step->stepCode]);
    }
}

class GuardPauseRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::paused('awaiting_approval', ['step' => $step->stepCode]);
    }
}
