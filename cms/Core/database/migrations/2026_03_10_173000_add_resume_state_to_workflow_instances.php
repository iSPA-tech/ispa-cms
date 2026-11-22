<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable(Tables::WORKFLOW_INSTANCE)) {
            return;
        }

        Schema::table(Tables::WORKFLOW_INSTANCE, function (Blueprint $table) {
            if (!Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'context_snapshot')) {
                $table->json('context_snapshot')->nullable()->after('status');
            }

            if (!Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'resume_step_id')) {
                $table->unsignedBigInteger('resume_step_id')->nullable()->after('context_snapshot');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(Tables::WORKFLOW_INSTANCE)) {
            return;
        }

        Schema::table(Tables::WORKFLOW_INSTANCE, function (Blueprint $table) {
            if (Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'resume_step_id')) {
                $table->dropColumn('resume_step_id');
            }

            if (Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'context_snapshot')) {
                $table->dropColumn('context_snapshot');
            }
        });
    }
};
