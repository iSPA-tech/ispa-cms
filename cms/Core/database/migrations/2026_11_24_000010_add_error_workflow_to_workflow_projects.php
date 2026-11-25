<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Error workflow setting: the project to start when a run of this project fails.
 * The executor only records it on the failed instance for now; starting it is a later lane (N3).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([Tables::WORKFLOW_PROJECT, Tables::WORKFLOW_INSTANCE] as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'error_workflow_project_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('error_workflow_project_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach ([Tables::WORKFLOW_PROJECT, Tables::WORKFLOW_INSTANCE] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'error_workflow_project_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['error_workflow_project_id']);
                $table->dropColumn('error_workflow_project_id');
            });
        }
    }
};
