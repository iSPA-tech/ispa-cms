<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which user applied an agent approval.
 *
 * Approvals already store the reviewer; this column stores the user who ran the
 * apply step, so every apply is attributable.
 */
return new class extends Migration
{
    /**
     * Add the nullable `applied_by_user_id` column to agent approvals.
     */
    public function up(): void
    {
        if (! Schema::hasTable(CoreTableConstant::AGENT_APPROVAL)
            || Schema::hasColumn(CoreTableConstant::AGENT_APPROVAL, 'applied_by_user_id')) {
            return;
        }

        Schema::table(CoreTableConstant::AGENT_APPROVAL, static function (Blueprint $table): void {
            $table->unsignedBigInteger('applied_by_user_id')->nullable()->index();
        });
    }

    /**
     * Drop the `applied_by_user_id` column.
     */
    public function down(): void
    {
        if (Schema::hasTable(CoreTableConstant::AGENT_APPROVAL)
            && Schema::hasColumn(CoreTableConstant::AGENT_APPROVAL, 'applied_by_user_id')) {
            Schema::table(CoreTableConstant::AGENT_APPROVAL, static function (Blueprint $table): void {
                $table->dropIndex(['applied_by_user_id']);
                $table->dropColumn('applied_by_user_id');
            });
        }
    }
};
