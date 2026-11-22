<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Support;

/**
 * Creates the data-scope tables in a test database by running the real migration.
 */
trait DataScopeTestTables
{
    /**
     * Run the data-scope migration's up() (idempotent).
     */
    protected function createDataScopeTables(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_05_120000_create_data_scope_tables.php';
        $migration->up();
    }
}
