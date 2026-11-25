<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Per-project budget caps, per-run usage totals, and the run statuses the executor now writes.
 *
 * - workflow_projects: budget_max_cost_per_run (USD), budget_max_runs_per_day (null = config default).
 * - workflow_instances: usage_tokens_in / usage_tokens_out / usage_cost_usd summed from steps'
 *   meta.usage, usage_unknown_calls = ai_spend steps that reported no cost.
 * - status enums (MySQL/MariaDB enum, PostgreSQL check constraint): add `waiting`, `queued`,
 *   `budget_exceeded`, `success` to instances and `rescheduled`, `budget_exceeded` to instance steps.
 *   The executor already writes `waiting` / `rescheduled` (continuations, delays) and SQLite tests
 *   did not catch the narrower enum.
 */
return new class extends Migration
{
    private const INSTANCE_STATUSES = [
        'pending', 'running', 'completed', 'failed', 'awaiting_approval', 'awaiting_apply', 'applied',
        'cancelled', 'apply_failed', 'approval_stale', 'approval_rejected', 'rejected',
        'waiting', 'queued', 'budget_exceeded', 'success',
    ];

    private const STEP_STATUSES = [
        'pending', 'running', 'success', 'failed', 'skipped', 'awaiting_approval', 'awaiting_apply',
        'applied', 'cancelled', 'apply_failed', 'approval_stale', 'approval_rejected', 'rejected', 'completed',
        'rescheduled', 'budget_exceeded', 'waiting', 'queued',
    ];

    public function up(): void
    {
        if (Schema::hasTable(Tables::WORKFLOW_PROJECT)) {
            Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) {
                if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'budget_max_cost_per_run')) {
                    $table->decimal('budget_max_cost_per_run', 12, 4)->nullable();
                }
                if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'budget_max_runs_per_day')) {
                    $table->unsignedInteger('budget_max_runs_per_day')->nullable();
                }
            });
        }

        if (Schema::hasTable(Tables::WORKFLOW_INSTANCE)) {
            Schema::table(Tables::WORKFLOW_INSTANCE, function (Blueprint $table) {
                if (!Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'usage_tokens_in')) {
                    $table->unsignedBigInteger('usage_tokens_in')->default(0);
                }
                if (!Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'usage_tokens_out')) {
                    $table->unsignedBigInteger('usage_tokens_out')->default(0);
                }
                if (!Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'usage_cost_usd')) {
                    $table->decimal('usage_cost_usd', 14, 6)->default(0);
                }
                if (!Schema::hasColumn(Tables::WORKFLOW_INSTANCE, 'usage_unknown_calls')) {
                    $table->unsignedInteger('usage_unknown_calls')->default(0);
                }
            });

            $this->widenStatus(Tables::WORKFLOW_INSTANCE, self::INSTANCE_STATUSES);
        }

        if (Schema::hasTable('workflow_instance_steps')) {
            $this->widenStatus('workflow_instance_steps', self::STEP_STATUSES);
        }
    }

    public function down(): void
    {
        // Status values are not narrowed again: rows may already use them.
        foreach ([
            Tables::WORKFLOW_PROJECT => ['budget_max_cost_per_run', 'budget_max_runs_per_day'],
            Tables::WORKFLOW_INSTANCE => ['usage_tokens_in', 'usage_tokens_out', 'usage_cost_usd', 'usage_unknown_calls'],
        ] as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($tableName, $c)));
            if ($present !== []) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($present));
            }
        }
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
            // Laravel creates enum columns on PostgreSQL as varchar + `<table>_status_check`.
            DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$table}_status_check\"");
            DB::statement("ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$table}_status_check\" CHECK (\"status\" IN ({$list}))");
        }
    }
};
