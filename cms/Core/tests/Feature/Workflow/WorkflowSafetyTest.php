<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Tests\TestCase;

class WorkflowSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->migrateTables();
    }

    public function test_max_steps_guard(): void
    {
        config(['core.workflow.max_steps' => 2]);

        $project = WorkflowProject::create(['name' => 'Loop']);
        $step = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'loop',
            'step_code' => 'loop',
        ]);

        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $step->id,
            'to_step_id' => $step->id,
        ]);

        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'status' => 'pending',
        ]);

        $registry = app(StepRunnerRegistry::class);
        $registry->register('loop', new SleepingRunner(0));

        $executor = app(WorkflowGraphExecutor::class);
        $executor->execute($instance->id, false);

        $instance->refresh();
        $this->assertSame('failed', $instance->status);
    }

    public function test_step_timeout_guard(): void
    {
        $project = WorkflowProject::create(['name' => 'Timeout']);
        $step = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'slow',
            'step_code' => 'slow',
            'step_config' => ['timeoutSeconds' => 1],
        ]);

        $instance = WorkflowInstance::create([
            'project_id' => $project->id,
            'status' => 'pending',
        ]);

        $registry = app(StepRunnerRegistry::class);
        $registry->register('slow', new SleepingRunner(2));

        $executor = app(WorkflowGraphExecutor::class);
        $executor->execute($instance->id, false);

        $instance->refresh();
        $this->assertSame('failed', $instance->status);
    }

    protected function migrateTables(): void
    {
        Schema::dropIfExists('workflow_instance_steps');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_connections');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_projects');

        Schema::create('workflow_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('step_code')->nullable();
            $table->string('step_type');
            $table->json('step_config')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('workflow_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('from_step_id')->nullable();
            $table->unsignedBigInteger('to_step_id')->nullable();
            $table->json('condition')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('status')->default('pending');
            $table->json('context_snapshot')->nullable();
            $table->unsignedBigInteger('resume_step_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
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
            $table->softDeletes();
            $table->timestamps();
        });
    }
}

class SleepingRunner implements StepRunner
{
    public function __construct(protected int $seconds)
    {
    }

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        if ($this->seconds > 0) {
            sleep($this->seconds);
        }
        return StepRunResult::success('done');
    }
}
