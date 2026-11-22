<?php

use App\Constants\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stops MySQL/MariaDB from rewriting `tracking_generation_runs.barrier_started_at` on every UPDATE.
 *
 * The aggregate tracking schema first declared the column as `timestamp()` and was changed to
 * `dateTime()` on the same day (e0ff8784e). Databases that ran the first version on MariaDB with
 * explicit_defaults_for_timestamp=0 kept a TIMESTAMP with `DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP` (seen on a production database on 2026-10-22), so every status change of a run
 * moved the barrier start to now and hid stale barriers from tracking:reconcile-aggregates.
 * Unlike the module fixes, this one converts to DATETIME because that is what the create
 * migration declares; the session time zone is pinned by config/database.php, so stored values
 * keep their wall-clock text. On databases that already have DATETIME the statement changes
 * nothing. Other drivers (PostgreSQL, SQLite in tests) are left alone.
 */
return new class extends Migration {
    /**
     * Re-declare the column as the create migration does on mysql/mariadb; no-op elsewhere.
     */
    public function up(): void
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        $connection->statement(sprintf(
            'ALTER TABLE %s MODIFY %s DATETIME NOT NULL',
            $grammar->wrapTable(Tables::TRACKING_GENERATION_RUNS),
            $grammar->wrap('barrier_started_at'),
        ));
    }

    /**
     * Intentionally empty: restoring ON UPDATE CURRENT_TIMESTAMP would bring the bug back.
     */
    public function down(): void
    {
    }
};
