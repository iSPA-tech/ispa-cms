<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN value TYPE TEXT USING value::text',
                CoreTableConstant::SETTINGS
            ));
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY COLUMN `value` LONGTEXT NULL',
            CoreTableConstant::SETTINGS
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN value TYPE VARCHAR(255) USING CASE WHEN value IS NULL THEN NULL ELSE LEFT(value, 255) END',
                CoreTableConstant::SETTINGS
            ));
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` MODIFY COLUMN `value` VARCHAR(255) NULL',
            CoreTableConstant::SETTINGS
        ));
    }
};
