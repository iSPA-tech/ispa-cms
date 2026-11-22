<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('storages', 'uuid')) {
            Schema::table('storages', static function (Blueprint $table) {
                $table->uuid()->nullable()->after('id');
            });

            DB::table('storages')
                ->whereNull('uuid')
                ->orderBy('id')
                ->get(['id'])
                ->each(static function ($storage): void {
                    DB::table('storages')
                        ->where('id', $storage->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                });

            Schema::table('storages', static function (Blueprint $table) {
                $table->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('storages', 'uuid')) {
            Schema::table('storages', static function (Blueprint $table) {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            });
        }
    }
};
