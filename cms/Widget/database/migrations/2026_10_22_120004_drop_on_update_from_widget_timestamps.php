<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stops MySQL/MariaDB from rewriting `widget_idempotency_keys.expires_at` on every UPDATE.
 *
 * With explicit_defaults_for_timestamp=0 (production MariaDB), the first NOT NULL TIMESTAMP
 * column of a table created without a default silently gets
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, so any update of an idempotency
 * key (e.g. processing -> committed) moved its expiry to now. Re-declaring the column with an
 * explicit `DEFAULT CURRENT_TIMESTAMP` keeps the stored values and the insert default and only
 * drops the ON UPDATE clause (a plain `NOT NULL` would get it again). The column stays
 * TIMESTAMP so its time-zone semantics do not change. Other drivers (SQLite in tests) are
 * left alone.
 */
return new class extends Migration {
    /** @var array<string, string> Table name => affected column. */
    private const COLUMNS = [
        Tables::IDEMPOTENCY_KEY => 'expires_at',
    ];

    /**
     * Re-declare each column with an explicit default on mysql/mariadb; no-op elsewhere.
     */
    public function up(): void
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        foreach (self::COLUMNS as $table => $column) {
            $connection->statement(sprintf(
                'ALTER TABLE %s MODIFY %s TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
                $grammar->wrapTable($table),
                $grammar->wrap($column),
            ));
        }
    }

    /**
     * Intentionally empty: restoring ON UPDATE CURRENT_TIMESTAMP would bring the bug back.
     */
    public function down(): void
    {
    }
};
