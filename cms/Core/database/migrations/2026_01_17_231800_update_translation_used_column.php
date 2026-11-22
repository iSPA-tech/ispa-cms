<?php

/**
 * Fixes workflow_instance_steps.step_id FK (was pointing to workflow_instances).
 * Drops incorrect FK if present, then adds FK to workflow_steps.id; data preserved.
 * Down reverts by dropping corrected FK and re-adding previous FK to workflow_instances when tables exist.
 */

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table(CoreTableConstant::TRANSLATES, static function (Blueprint $table) {
            $table->boolean('is_used')->after('status')->default(false)->index();
            $table->timestamp('last_scanned_at')->after('is_used')->nullable()->index();
            $table->json('used_in')->after('last_scanned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(CoreTableConstant::TRANSLATES, static function (Blueprint $table) {
            $table->dropColumn(['is_used', 'last_scanned_at', 'used_in']);
        });
    }
};
