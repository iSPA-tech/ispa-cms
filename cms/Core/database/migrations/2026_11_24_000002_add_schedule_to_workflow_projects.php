<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Cron schedule for authored workflow projects (run by cms:workflow-run-scheduled).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable(Tables::WORKFLOW_PROJECT)) {
            return;
        }

        Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) {
            if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'schedule_cron')) {
                $table->string('schedule_cron', 120)->nullable();
            }

            if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'schedule_timezone')) {
                $table->string('schedule_timezone', 64)->nullable();
            }

            if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'scheduled_by_user_id')) {
                $table->foreignId('scheduled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'last_scheduled_run_at')) {
                $table->timestamp('last_scheduled_run_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(Tables::WORKFLOW_PROJECT)) {
            return;
        }

        Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) {
            if (Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'scheduled_by_user_id')) {
                $table->dropConstrainedForeignId('scheduled_by_user_id');
            }

            foreach (['schedule_cron', 'schedule_timezone', 'last_scheduled_run_at'] as $column) {
                if (Schema::hasColumn(Tables::WORKFLOW_PROJECT, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
