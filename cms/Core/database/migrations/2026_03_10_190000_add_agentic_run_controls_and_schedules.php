<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table(Tables::AGENT_RUN, static function (Blueprint $table) {
            $table->foreignId('source_run_id')
                ->nullable()
                ->after('parent_workflow_step_id')
                ->constrained(Tables::AGENT_RUN)
                ->nullOnDelete();
            $table->string('trigger_mode', 32)->nullable()->after('source_run_id')->index();
            $table->string('trigger_reference_type', 64)->nullable()->after('trigger_mode')->index();
            $table->string('trigger_reference_id', 191)->nullable()->after('trigger_reference_type')->index();
            $table->foreignId('stopped_by_user_id')
                ->nullable()
                ->after('post_approval_outcome')
                ->constrained(Tables::USERS)
                ->nullOnDelete();
            $table->timestamp('stopped_at')->nullable()->after('stopped_by_user_id');
        });

        Schema::create(Tables::AGENT_SCHEDULE, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')
                ->constrained(Tables::AGENT_PROFILE)
                ->cascadeOnDelete();
            $table->foreignId('tool_definition_id')
                ->constrained(Tables::AGENT_TOOL_DEFINITION)
                ->cascadeOnDelete();
            $table->foreignId('last_run_id')
                ->nullable()
                ->constrained(Tables::AGENT_RUN)
                ->nullOnDelete();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained(Tables::USERS)
                ->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('environment', 32)->nullable()->index();
            $table->string('tenant_id', 191)->nullable()->index();
            $table->string('module_scope', 64)->nullable()->index();
            $table->string('target_type', 191)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->text('goal')->nullable();
            $table->json('tool_input')->nullable();
            $table->unsignedInteger('cadence_minutes')->default(60);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_outcome', 64)->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_run_at'], 'agent_schedules_status_next_run_index');
            $table->index(['target_type', 'target_id'], 'agent_schedules_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::AGENT_SCHEDULE);

        Schema::table(Tables::AGENT_RUN, static function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_run_id');
            $table->dropConstrainedForeignId('stopped_by_user_id');
            $table->dropColumn([
                'trigger_mode',
                'trigger_reference_type',
                'trigger_reference_id',
                'stopped_at',
            ]);
        });
    }
};
