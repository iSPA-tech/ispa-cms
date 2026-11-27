<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Cms\Core\Jobs\RunWorkflowInstanceJob;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowInstanceStep;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Runtime\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Tests\TestCase;

class WorkflowRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->migrateTables();
    }

    public function test_retry_inline_until_success(): void
    {
        FlakyRunner::resetAttempts();

        $project = WorkflowProject::create(['name' => 'Retry']);
        $step = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'retryable',
            'step_code' => 'retryable',
            'step_config' => ['retry' => ['max' => 2, 'backoffSeconds' => [0]]],
        ]);
        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $step->id,
            'to_step_id' => null,
        ]);
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'status' => 'pending',
        ]);

        $registry = app(StepRunnerRegistry::class);
        $registry->register('retryable', new FlakyRunner());

        $executor = app(WorkflowGraphExecutor::class);
        $executor->execute($instance->id, false);

        $instance->refresh();
        $this->assertSame('completed', $instance->status);
        $this->assertGreaterThanOrEqual(1, $instance->steps()->count());
    }

    public function test_retry_async_persists_resume_cursor_before_redispatch(): void
    {
        Queue::fake();
        FlakyRunner::resetAttempts();

        $project = WorkflowProject::create(['name' => 'Queued Retry']);
        $step = WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => 'queued_retryable',
            'step_code' => 'queued_retryable',
            'step_config' => ['retry' => ['max' => 2, 'backoffSeconds' => [5]]],
        ]);
        $instance = \Cms\Core\Tests\Support\WorkflowTestVersions::instance([
            'project_id' => $project->id,
            'status' => 'pending',
        ]);

        $registry = app(StepRunnerRegistry::class);
        $registry->register('queued_retryable', new FlakyRunner());

        app(WorkflowGraphExecutor::class)->execute($instance->id, true);

        $instance->refresh();
        $instanceStep = WorkflowInstanceStep::query()->where('instance_id', $instance->id)->latest('id')->first();

        $this->assertSame('waiting', $instance->status);
        $this->assertSame($step->id, $instance->resume_step_id);
        $this->assertNotNull($instanceStep);
        $this->assertSame('failed', $instanceStep->status);
        $this->assertNotNull($instanceStep->finished_at);

        Queue::assertPushed(RunWorkflowInstanceJob::class, function (RunWorkflowInstanceJob $job) use ($instance) {
            return $job->workflowInstanceId() === $instance->id;
        });
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
            $table->uuid('uuid')->nullable()->unique();
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

class FlakyRunner implements StepRunner
{
    protected static int $attempts = 0;

    public static function resetAttempts(): void
    {
        self::$attempts = 0;
    }

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        self::$attempts++;
        if (self::$attempts === 1) {
            return StepRunResult::failed('fail once');
        }
        return StepRunResult::success('ok');
    }
}
