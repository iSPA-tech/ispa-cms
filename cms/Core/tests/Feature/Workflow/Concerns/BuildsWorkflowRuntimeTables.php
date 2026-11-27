<?php

namespace Cms\Core\Tests\Feature\Workflow\Concerns;

use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the minimal workflow tables the graph executor needs, so executor tests run in isolation.
 */
trait BuildsWorkflowRuntimeTables
{
    protected function createWorkflowRuntimeTables(): void
    {
        foreach (['workflow_instance_steps', 'workflow_instances', 'workflow_connections', 'workflow_steps', 'workflow_projects'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('workflow_projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->string('name');
            $table->boolean('is_active')->nullable();
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

        // Runs execute published versions only (guardrails B1).
        \Cms\Core\Tests\Support\WorkflowTestVersions::ensureSchema();
    }

    protected function makeStep(WorkflowProject $project, string $type, string $code, int $x, array $config = []): WorkflowStep
    {
        return WorkflowStep::create([
            'project_id' => $project->id,
            'step_type' => $type,
            'step_code' => $code,
            'step_config' => $config,
            'position_x' => $x,
        ]);
    }

    protected function connect(WorkflowProject $project, WorkflowStep $from, WorkflowStep $to, ?string $hint = null): void
    {
        WorkflowConnection::create([
            'project_id' => $project->id,
            'from_step_id' => $from->id,
            'to_step_id' => $to->id,
            'condition' => $hint ? ['condition_type' => 'equals', 'condition_value' => $hint] : null,
        ]);
    }
}
