<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable(CoreTableConstant::SETTINGS)) {
            return;
        }

        $sequence = DB::selectOne(
            'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
            [CoreTableConstant::SETTINGS, 'id']
        );

        $sequenceName = $sequence->sequence_name ?? null;
        if (! is_string($sequenceName) || $sequenceName === '') {
            return;
        }

        $maxId = DB::table(CoreTableConstant::SETTINGS)->max('id');
        if (! is_numeric($maxId)) {
            return;
        }

        DB::statement('SELECT setval(?::regclass, ?, true)', [$sequenceName, (int) $maxId]);
    }
};
