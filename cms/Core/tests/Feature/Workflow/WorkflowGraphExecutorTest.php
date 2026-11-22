<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Cms\Core\Tests\TestCase;

class WorkflowGraphExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->migrateTables();

        $registry = app(StepRunnerRegistry::class);
        $registry->register('input', new DummyRunner());
        $registry->register('output', new DummyRunner());
        $registry->register('decision', new DecisionRunner());
        $registry->register('pause', new PausingRunner());
    }

    public function test_executes_simple_linear_flow(): void
    {
        [$project, $steps] = $this->buildLinearWorkflow();
        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'target_type' => DummyTarget::class,
            'target_id' => 1,
            'status' => 'pending',
        ]);

        $executor = app(WorkflowGraphExecutor::class);
        $executor->execute($instance->id);

        $instance->refresh();
        $this->assertSame('completed', $instance->status);
        $this->assertEquals(2, WorkflowInstanceStep::where('instance_id', $instance->id)->count());
    }

    public function test_executes_branching_with_hint(): void
    {
        [$project, $steps] = $this->buildDecisionWorkflow();
        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'target_type' => DummyTarget::class,
            'target_id' => 1,
            'status' => 'pending',
        ]);

        $executor = app(WorkflowGraphExecutor::class);
        $executor->execute($instance->id);

        $instance->refresh();
        $this->assertSame('completed', $instance->status);

        $executed = WorkflowInstanceStep::where('instance_id', $instance->id)->pluck('step_id')->toArray();
        // Only yes branch should execute
        $this->assertContains($steps['yes']->id, $executed);
        $this->assertNotContains($steps['no']->id, $executed);
    }

    public function test_executes_branching_with_legacy_builder_handles(): void
    {
        [$project, $steps] = $this->buildLegacyDecisionWorkflow();
        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'target_type' => DummyTarget::class,
            'target_id' => 1,
            'status' => 'pending',
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id);

        $executed = WorkflowInstanceStep::where('instance_id', $instance->id)->pluck('step_id')->toArray();

        $this->assertContains($steps['yes']->id, $executed);
        $this->assertNotContains($steps['no']->id, $executed);
    }

    public function test_job_wrapper_dispatches_executor(): void
    {
        Queue::fake();
        RunWorkflowInstanceJob::dispatch(999);
        Queue::assertPushed(RunWorkflowInstanceJob::class);
    }

    public function test_paused_step_stops_execution_and_marks_instance_as_awaiting_status(): void
    {
        $project = WorkflowProject::create(['name' => 'Paused']);
        $pause = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'pause',
            'step_code' => 'pause',
            'position_x' => 1,
        ]);
        $after = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'output',
            'step_code' => 'after_pause',
            'position_x' => 2,
        ]);

        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $pause->id,
            'to_step_id' => $after->id,
            'condition' => null,
        ]);

        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'target_type' => DummyTarget::class,
            'target_id' => 1,
            'status' => 'pending',
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id);

        $instance->refresh();
        $this->assertSame('awaiting_approval', $instance->status);

        $steps = WorkflowInstanceStep::where('instance_id', $instance->id)->orderBy('id')->get();
        $this->assertCount(1, $steps);
        $this->assertSame($pause->id, $steps->first()->step_id);
        $this->assertSame('awaiting_approval', $steps->first()->status);
    }

    public function test_cancelling_instance_is_acknowledged_without_running_steps(): void
    {
        [$project] = $this->buildLinearWorkflow();
        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'target_type' => DummyTarget::class,
            'target_id' => 1,
            'status' => 'cancelling',
        ]);

        app(WorkflowGraphExecutor::class)->execute($instance->id);

        $instance->refresh();
        $this->assertSame('cancelled', $instance->status);
        $this->assertEquals(0, WorkflowInstanceStep::where('instance_id', $instance->id)->count());
    }

    protected function migrateTables(): void
    {
        Schema::create('dummy_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('workflow_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('step_code')->nullable();
            $table->string('name')->nullable();
            $table->string('step_type');
            $table->json('step_config')->nullable();
            $table->integer('position_x')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('from_step_id')->nullable();
            $table->unsignedBigInteger('to_step_id')->nullable();
            $table->json('condition')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status')->default('pending');
            $table->json('context_snapshot')->nullable();
            $table->unsignedBigInteger('resume_step_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_instance_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('instance_id')->nullable();
            $table->unsignedBigInteger('step_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('result_data')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function buildLinearWorkflow(): array
    {
        $project = WorkflowProject::create(['name' => 'Test']);
        $input = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'input',
            'step_code' => 'input',
            'position_x' => 1,
        ]);
        $output = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'output',
            'step_code' => 'output',
            'position_x' => 2,
        ]);

        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $input->id,
            'to_step_id' => $output->id,
            'condition' => null,
        ]);

        return [$project, ['input' => $input, 'output' => $output]];
    }

    protected function buildDecisionWorkflow(): array
    {
        $project = WorkflowProject::create(['name' => 'Decision']);
        $input = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'input',
            'step_code' => 'input',
            'position_x' => 1,
        ]);
        $decision = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'decision',
            'step_code' => 'decision',
            'position_x' => 2,
        ]);
        $yes = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'output',
            'step_code' => 'yes',
            'position_x' => 3,
        ]);
        $no = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'output',
            'step_code' => 'no',
            'position_x' => 3,
        ]);

        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $input->id,
            'to_step_id' => $decision->id,
            'condition' => null,
        ]);

        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $decision->id,
            'to_step_id' => $yes->id,
            'condition' => ['condition_type' => 'equals', 'condition_value' => 'yes'],
        ]);

        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $decision->id,
            'to_step_id' => $no->id,
            'condition' => ['condition_type' => 'equals', 'condition_value' => 'no'],
        ]);

        return [$project, ['input' => $input, 'decision' => $decision, 'yes' => $yes, 'no' => $no]];
    }

    protected function buildLegacyDecisionWorkflow(): array
    {
        [$project, $steps] = $this->buildDecisionWorkflow();

        WorkflowConnection::query()
            ->where('project_id', $project->id)
            ->where('from_step_id', $steps['decision']->id)
            ->delete();

        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $steps['decision']->id,
            'to_step_id' => $steps['yes']->id,
            'condition' => ['condition' => 'yes', 'source_handle' => 'yes'],
        ]);

        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $steps['decision']->id,
            'to_step_id' => $steps['no']->id,
            'condition' => ['condition' => 'no', 'source_handle' => 'no'],
        ]);

        return [$project, $steps];
    }
}

class DummyTarget extends Model
{
    protected $table = 'dummy_targets';
    protected $guarded = [];
}

class DummyRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::success(['step' => $step->stepCode]);
    }
}

class DecisionRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::success(['step' => $step->stepCode], [], 'yes');
    }
}

class PausingRunner implements StepRunner
{
    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        return StepRunResult::paused('awaiting_approval', ['step' => $step->stepCode]);
    }
}
