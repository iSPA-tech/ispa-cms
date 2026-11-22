<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            DB::getDriverName() !== 'mysql'
            || ! Schema::hasTable(CoreTableConstant::META_VALUES_STRING)
            || ! Schema::hasColumn(CoreTableConstant::META_VALUES_STRING, 'value')
        ) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `value` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
            CoreTableConstant::META_VALUES_STRING
        ));
    }

    public function down(): void
    {
        if (
            DB::getDriverName() !== 'mysql'
            || ! Schema::hasTable(CoreTableConstant::META_VALUES_STRING)
            || ! Schema::hasColumn(CoreTableConstant::META_VALUES_STRING, 'value')
        ) {
            return;
        }

        $table = CoreTableConstant::META_VALUES_STRING;
        $duplicates = DB::selectOne(
            "SELECT COUNT(*) AS aggregate
             FROM (
                 SELECT `value` COLLATE utf8mb4_unicode_ci
                 FROM `{$table}`
                 WHERE `value` IS NOT NULL
                 GROUP BY `value` COLLATE utf8mb4_unicode_ci
                 HAVING COUNT(*) > 1
             ) AS collisions"
        );

        if ((int) ($duplicates->aggregate ?? 0) > 0) {
            throw new \RuntimeException(
                'Cannot restore accent-insensitive meta value collation after distinct values have been stored.'
            );
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY `value` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            $table
        ));
    }
};
