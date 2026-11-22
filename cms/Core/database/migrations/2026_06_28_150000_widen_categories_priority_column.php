<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable(CoreTableConstant::CATEGORIES) || ! Schema::hasColumn(CoreTableConstant::CATEGORIES, 'priority')) {
            return;
        }

        $driver = DB::getDriverName();
        $table = DB::connection()->getQueryGrammar()->wrapTable(CoreTableConstant::CATEGORIES);
        $column = DB::connection()->getQueryGrammar()->wrap('priority');

        match ($driver) {
            'mysql', 'mariadb' => DB::statement("ALTER TABLE {$table} MODIFY {$column} SMALLINT NOT NULL DEFAULT 0"),
            'pgsql' => DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE SMALLINT USING {$column}::smallint"),
            default => null,
        };
    }

    public function down(): void
    {
        if (! Schema::hasTable(CoreTableConstant::CATEGORIES) || ! Schema::hasColumn(CoreTableConstant::CATEGORIES, 'priority')) {
            return;
        }

        $driver = DB::getDriverName();
        $tableName = CoreTableConstant::CATEGORIES;
        $table = DB::connection()->getQueryGrammar()->wrapTable($tableName);
        $column = DB::connection()->getQueryGrammar()->wrap('priority');

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $minPriority = DB::table($tableName)->min('priority') ?? 0;
            $maxPriority = DB::table($tableName)->max('priority') ?? 0;

            if ($minPriority < -128 || $maxPriority > 127) {
                throw new RuntimeException('Cannot shrink categories.priority to TINYINT while values are outside -128..127.');
            }

            DB::statement("ALTER TABLE {$table} MODIFY {$column} TINYINT NOT NULL DEFAULT 0");
        }
    }
};
