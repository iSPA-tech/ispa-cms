<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Tables::AGENT_PROFILE, static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('capability_class', 64)->index();
            $table->string('status', 32)->default('active')->index();
            $table->longText('system_prompt_template')->nullable();
            $table->json('model_config')->nullable();
            $table->json('budget_config')->nullable();
            $table->timestamps();
        });

        Schema::create(Tables::AGENT_TOOL_DEFINITION, static function (Blueprint $table) {
            $table->id();
            $table->string('tool_name')->unique();
            $table->string('tool_family', 128)->nullable()->index();
            $table->string('contract_steward', 128)->nullable()->index();
            $table->text('description')->nullable();
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->string('safety_level', 32)->default('safe')->index();
            $table->string('side_effect_class', 64)->default('read_only')->index();
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->json('retry_policy')->nullable();
            $table->json('idempotency_strategy')->nullable();
            $table->json('lock_scope')->nullable();
            $table->json('compensation_policy')->nullable();
            $table->boolean('citation_required')->default(false);
            $table->boolean('approval_required')->default(false);
            $table->string('capability_class', 64)->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
        });

        Schema::create(Tables::AGENT_TOOL_BINDING, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_definition_id')
                ->constrained(Tables::AGENT_TOOL_DEFINITION)
                ->cascadeOnDelete();
            $table->string('module_scope', 64)->nullable()->index();
            $table->string('environment', 32)->nullable()->index();
            $table->string('tenant_id', 191)->nullable()->index();
            $table->string('policy_scope', 191)->nullable()->index();
            $table->json('rollout_scope')->nullable();
            $table->string('binding_type', 64);
            $table->string('binding_target');
            $table->json('binding_config')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->integer('priority')->default(0)->index();
            $table->timestamps();
        });

        Schema::create(Tables::AGENT_PROFILE_TOOL, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')
                ->constrained(Tables::AGENT_PROFILE)
                ->cascadeOnDelete();
            $table->foreignId('tool_definition_id')
                ->constrained(Tables::AGENT_TOOL_DEFINITION)
                ->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('safety_override', 32)->nullable();
            $table->timestamps();

            $table->unique(['agent_profile_id', 'tool_definition_id'], 'agent_profile_tools_unique');
        });

        Schema::create(Tables::AGENT_RUN, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')
                ->nullable()
                ->constrained(Tables::AGENT_PROFILE)
                ->nullOnDelete();
            $table->foreignId('workflow_instance_id')
                ->nullable()
                ->constrained(Tables::WORKFLOW_INSTANCE)
                ->nullOnDelete();
            $table->foreignId('parent_workflow_step_id')
                ->nullable()
                ->constrained(Tables::WORKFLOW_INSTANCE_STEP)
                ->nullOnDelete();
            $table->string('environment', 32)->nullable()->index();
            $table->string('tenant_id', 191)->nullable()->index();
            $table->string('module_scope', 64)->nullable()->index();
            $table->string('target_type', 191)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->string('status', 32)->default('init')->index();
            $table->text('input_goal')->nullable();
            $table->json('working_memory')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->longText('reasoning_summary')->nullable();
            $table->json('budget_config')->nullable();
            $table->json('budget_usage')->nullable();
            $table->string('terminal_reason', 191)->nullable()->index();
            $table->string('post_approval_outcome', 64)->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_checkpoint_at')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_id'], 'agent_runs_target_index');
        });

        Schema::create(Tables::AGENT_ARTIFACT, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')
                ->nullable()
                ->constrained(Tables::AGENT_RUN)
                ->cascadeOnDelete();
            $table->unsignedBigInteger('artifact_group_id')->nullable()->index();
            $table->foreignId('parent_artifact_id')
                ->nullable()
                ->constrained(Tables::AGENT_ARTIFACT)
                ->nullOnDelete();
            $table->string('artifact_type', 64)->index();
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->string('source_snapshot_ref')->nullable();
            $table->json('original_data_snapshot')->nullable();
            $table->json('payload')->nullable();
            $table->string('storage_driver', 64)->nullable();
            $table->string('storage_ref')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('render_hint', 64)->nullable()->index();
            $table->string('apply_contract_version', 64)->default('v1');
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'agent_artifacts_subject_index');
            $table->index(['run_id', 'artifact_type'], 'agent_artifacts_run_type_index');
        });

        Schema::create(Tables::AGENT_APPROVAL, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')
                ->constrained(Tables::AGENT_RUN)
                ->cascadeOnDelete();
            $table->foreignId('artifact_id')
                ->constrained(Tables::AGENT_ARTIFACT)
                ->cascadeOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->json('diff_data')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained(Tables::USERS)
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('apply_outcome', 64)->nullable()->index();
            $table->text('apply_error')->nullable();
            $table->timestamp('apply_started_at')->nullable();
            $table->timestamp('apply_finished_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status'], 'agent_approvals_run_status_index');
        });

        Schema::create(Tables::AGENT_RUN_STEP, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')
                ->constrained(Tables::AGENT_RUN)
                ->cascadeOnDelete();
            $table->unsignedInteger('step_index');
            $table->string('state', 64)->nullable()->index();
            $table->string('action_type', 64)->nullable();
            $table->string('action_name', 191)->nullable();
            $table->text('reasoning_summary')->nullable();
            $table->text('observation_summary')->nullable();
            $table->foreignId('observation_artifact_id')
                ->nullable()
                ->constrained(Tables::AGENT_ARTIFACT)
                ->nullOnDelete();
            $table->json('snapshot_state')->nullable();
            $table->string('status', 32)->default('completed')->index();
            $table->string('terminal_reason', 191)->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'step_index'], 'agent_run_steps_run_step_unique');
        });

        Schema::create(Tables::AGENT_TOOL_CALL, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')
                ->constrained(Tables::AGENT_RUN)
                ->cascadeOnDelete();
            $table->foreignId('run_step_id')
                ->nullable()
                ->constrained(Tables::AGENT_RUN_STEP)
                ->cascadeOnDelete();
            $table->foreignId('tool_definition_id')
                ->nullable()
                ->constrained(Tables::AGENT_TOOL_DEFINITION)
                ->nullOnDelete();
            $table->foreignId('tool_binding_id')
                ->nullable()
                ->constrained(Tables::AGENT_TOOL_BINDING)
                ->nullOnDelete();
            $table->foreignId('input_artifact_id')
                ->nullable()
                ->constrained(Tables::AGENT_ARTIFACT)
                ->nullOnDelete();
            $table->foreignId('output_artifact_id')
                ->nullable()
                ->constrained(Tables::AGENT_ARTIFACT)
                ->nullOnDelete();
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->string('retry_scope', 32)->default('pipeline')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->string('idempotency_key', 191)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status'], 'agent_tool_calls_run_status_index');
        });

        Schema::create(Tables::AGENT_MEMORY, static function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 191)->nullable();
            $table->string('entity_id', 191)->nullable();
            $table->string('memory_class', 64)->nullable()->index();
            $table->string('memory_type', 64)->nullable()->index();
            $table->string('context_key', 191)->index();
            $table->text('content')->nullable();
            $table->string('embedding_ref')->nullable();
            $table->json('source_citation')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->decimal('decay_score', 6, 4)->nullable();
            $table->string('quality_state', 32)->default('active')->index();
            $table->timestamp('promoted_to_policy_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'agent_memories_entity_index');
        });

        Schema::create(Tables::AGENT_POLICY, static function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 64)->index();
            $table->string('scope_id', 191)->nullable()->index();
            $table->string('policy_type', 64)->index();
            $table->string('policy_key', 191)->index();
            $table->json('policy_value')->nullable();
            $table->foreignId('source_memory_id')
                ->nullable()
                ->constrained(Tables::AGENT_MEMORY)
                ->nullOnDelete();
            $table->boolean('enabled')->default(true)->index();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained(Tables::USERS)
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'policy_type'], 'agent_policies_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::AGENT_POLICY);
        Schema::dropIfExists(Tables::AGENT_MEMORY);
        Schema::dropIfExists(Tables::AGENT_TOOL_CALL);
        Schema::dropIfExists(Tables::AGENT_RUN_STEP);
        Schema::dropIfExists(Tables::AGENT_APPROVAL);
        Schema::dropIfExists(Tables::AGENT_ARTIFACT);
        Schema::dropIfExists(Tables::AGENT_RUN);
        Schema::dropIfExists(Tables::AGENT_PROFILE_TOOL);
        Schema::dropIfExists(Tables::AGENT_TOOL_BINDING);
        Schema::dropIfExists(Tables::AGENT_TOOL_DEFINITION);
        Schema::dropIfExists(Tables::AGENT_PROFILE);
    }
};
