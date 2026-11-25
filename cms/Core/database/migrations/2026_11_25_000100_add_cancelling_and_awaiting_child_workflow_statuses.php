<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the run statuses the code writes but the status lists did not allow (review finding 9):
 *
 * - `cancelling` — "Stop run" (AgentRunControlService) on instances and on the parent step;
 * - `awaiting_child` — a sub-workflow step in `wait` mode (SubWorkflowService::AWAITING_STATUS) on the parent
 *   instance and on its step row.
 *
 * MySQL and MariaDB (driver `mysql` or `mariadb`) keep an ENUM, PostgreSQL a `<table>_status_check` constraint;
 * SQLite is left alone (plain varchar there). `WorkflowStatusEnumTest` compares
 * these lists with the statuses the code writes: a later migration that changes them must keep the constant names.
 */
return new class extends Migration
{
    public const INSTANCE_STATUSES = [
        'pending', 'running', 'completed', 'failed', 'awaiting_approval', 'awaiting_apply', 'applied',
        'cancelled', 'apply_failed', 'approval_stale', 'approval_rejected', 'rejected',
        'waiting', 'queued', 'budget_exceeded', 'success',
        'cancelling', 'awaiting_child',
    ];

    public const STEP_STATUSES = [
        'pending', 'running', 'success', 'failed', 'skipped', 'awaiting_approval', 'awaiting_apply',
        'applied', 'cancelled', 'apply_failed', 'approval_stale', 'approval_rejected', 'rejected', 'completed',
        'rescheduled', 'budget_exceeded', 'waiting', 'queued',
        'cancelling', 'awaiting_child',
    ];

    public function up(): void
    {
        if (Schema::hasTable(Tables::WORKFLOW_INSTANCE)) {
            $this->widenStatus(Tables::WORKFLOW_INSTANCE, self::INSTANCE_STATUSES);
        }

        if (Schema::hasTable('workflow_instance_steps')) {
            $this->widenStatus('workflow_instance_steps', self::STEP_STATUSES);
        }
    }

    public function down(): void
    {
        // Status values are not narrowed again: rows may already use them.
    }

    /**
     * @param array<int, string> $values
     */
    private function widenStatus(string $table, array $values): void
    {
        $driver = DB::getDriverName();
        $list = implode(',', array_map(fn ($v) => "'{$v}'", $values));

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `status` ENUM({$list}) NOT NULL DEFAULT 'pending'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$table}_status_check\"");
            DB::statement("ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$table}_status_check\" CHECK (\"status\" IN ({$list}))");

        }

        // SQLite: the status columns are plain varchar there (no CHECK) and a table rebuild would bring back the stale
        // step_id foreign key of workflow_instance_steps, so nothing is changed.
    }
};
