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
 * A failed step with an outgoing `error` edge continues the run there instead of failing it.
 */
class WorkflowErrorRouteTest extends TestCase
{
    use BuildsWorkflowRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createWorkflowRuntimeTables();
        (require base_path('cms/Core/database/migrations/2026_11_24_000010_add_error_workflow_to_workflow_projects.php'))->up();
        ErrorRouteRecorder::$seenError = null;

        $registry = app(StepRunnerRegistry::class);
        $registry->register('test_fail', new ErrorRouteFailRunner());
        $registry->register('test_record', new ErrorRouteRecorder());
    }

    public function test_failed_step_with_an_error_edge_continues_there_with_the_error_in_vars(): void
    {
        $project = WorkflowProject::create(['name' => 'Error route']);
        $fail = $this->makeStep($project, 'test_fail', 'fetch', 1);
        $next = $this->makeStep($project, 'test_record', 'next', 2);
        $handler = $this->makeStep($project, 'test_record', 'handler', 3);
        $this->edge($project, $fail, $next, null);
        $this->edge($project, $fail, $handler, 'error');

        $instance = $this->runProject($project);

        $this->assertSame('completed', $instance->status);
        $this->assertSame(1, $this->ranCount($instance, $handler));
        $this->assertSame(0, $this->ranCount($instance, $next));
        $this->assertSame(['step_code' => 'fetch', 'message' => 'upstream timed out'], ErrorRouteRecorder::$seenError);
        $this->assertSame('failed', WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $fail->id)->value('status'));
    }

    public function test_failed_step_without_an_error_edge_still_fails_the_run_and_records_the_error_workflow(): void
    {
        $handlerProject = WorkflowProject::create(['name' => 'On error']);
        $project = WorkflowProject::create(['name' => 'Plain']);
        $project->error_workflow_project_id = $handlerProject->id;
        $project->save();

        $fail = $this->makeStep($project, 'test_fail', 'fetch', 1);
        $next = $this->makeStep($project, 'test_record', 'next', 2);
        $this->edge($project, $fail, $next, null);

        $instance = $this->runProject($project);

        $this->assertSame('failed', $instance->status);
        $this->assertSame(0, $this->ranCount($instance, $next));
        $this->assertSame($handlerProject->id, (int) $instance->error_workflow_project_id);
    }

    protected function edge(WorkflowProject $project, WorkflowStep $from, WorkflowStep $to, ?string $handle): void
    {
        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $from->id,
            'to_step_id' => $to->id,
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

    protected function ranCount(WorkflowInstance $instance, WorkflowStep $step): int
    {
        return WorkflowInstanceStep::where('instance_id', $instance->id)->where('step_id', $step->id)->count();
    }
}

class ErrorRouteFailRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::failed('upstream timed out');
    }
}

class ErrorRouteRecorder implements StepRunner
{
    public static mixed $seenError = null;

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        if ($step->stepCode === 'handler') {
            self::$seenError = $context->getVarPath('__error');
        }

        return StepRunResult::success();
    }
}
