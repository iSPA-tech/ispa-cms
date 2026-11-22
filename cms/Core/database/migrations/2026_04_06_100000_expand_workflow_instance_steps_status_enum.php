<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `workflow_instance_steps` MODIFY `status` ENUM('pending','running','success','failed','skipped','awaiting_approval','awaiting_apply','applied','cancelled','apply_failed','approval_stale','approval_rejected','rejected','completed') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE `workflow_instances` MODIFY `status` ENUM('pending','running','completed','failed','awaiting_approval','awaiting_apply','applied','cancelled','apply_failed','approval_stale','approval_rejected','rejected') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Intentionally no-op: shrinking the enum would lose data for rows
        // that already use the expanded values.
    }
};
