<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which user a workflow instance and an agent run act for.
 *
 * Adds `delegated_by_user_id` (nullable, null on user delete) to workflow
 * instances and agent runs, and `scope_snapshot` (the delegating user's data
 * scope summary at run start) to agent runs.
 */
return new class extends Migration
{
    /**
     * Add the delegation columns when they are missing.
     */
    public function up(): void
    {
        foreach ([CoreTableConstant::WORKFLOW_INSTANCE, CoreTableConstant::AGENT_RUN] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'delegated_by_user_id')) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->foreignId('delegated_by_user_id')
                    ->nullable()
                    ->index()
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable(CoreTableConstant::AGENT_RUN)
            && ! Schema::hasColumn(CoreTableConstant::AGENT_RUN, 'scope_snapshot')) {
            Schema::table(CoreTableConstant::AGENT_RUN, static function (Blueprint $blueprint): void {
                $blueprint->json('scope_snapshot')->nullable();
            });
        }
    }

    /**
     * Drop the delegation columns and their foreign keys.
     */
    public function down(): void
    {
        if (Schema::hasTable(CoreTableConstant::AGENT_RUN)
            && Schema::hasColumn(CoreTableConstant::AGENT_RUN, 'scope_snapshot')) {
            Schema::table(CoreTableConstant::AGENT_RUN, static function (Blueprint $blueprint): void {
                $blueprint->dropColumn('scope_snapshot');
            });
        }

        foreach ([CoreTableConstant::AGENT_RUN, CoreTableConstant::WORKFLOW_INSTANCE] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'delegated_by_user_id')) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['delegated_by_user_id']);
                $blueprint->dropIndex(['delegated_by_user_id']);
                $blueprint->dropColumn('delegated_by_user_id');
            });
        }
    }
};
