<?php

namespace Cms\Core\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables every agent-action workflow test needs: a dummy write target, users, the workflow tables and the agentic
 * runtime tables. Tests that also exercise module tools add their module tables on top.
 */
trait AgentActionWorkflowSchema
{
    protected function migrateAgentActionWorkflowTables(): void
    {
        Schema::create('agent_action_dummy_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
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

        $migration = require base_path('cms/Core/database/migrations/2026_03_10_160000_create_agentic_runtime_tables.php');
        $migration->up();
        $controlMigration = require base_path('cms/Core/database/migrations/2026_03_10_190000_add_agentic_run_controls_and_schedules.php');
        $controlMigration->up();
        $stopMigration = require base_path('cms/Core/database/migrations/2026_03_10_213000_add_stop_request_fields_to_agent_runs.php');
        $stopMigration->up();
    }
}
