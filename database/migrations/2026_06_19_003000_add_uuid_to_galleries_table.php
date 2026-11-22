<?php

use App\Constants\Tables;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    private const UUID_INDEX = 'galleries_uuid_unique';

    public function up(): void
    {
        if (!Schema::hasTable(Tables::GALLERIES)) {
            return;
        }

        if (!Schema::hasColumn(Tables::GALLERIES, 'uuid')) {
            Schema::table(Tables::GALLERIES, static function (Blueprint $table): void {
                $table->uuid()->nullable()->after('id');
            });
        }

        DB::table(Tables::GALLERIES)
            ->whereNull('uuid')
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, static function ($galleries): void {
                foreach ($galleries as $gallery) {
                    DB::table(Tables::GALLERIES)
                        ->where('id', $gallery->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        if (!Schema::hasIndex(Tables::GALLERIES, self::UUID_INDEX, 'unique')) {
            Schema::table(Tables::GALLERIES, static function (Blueprint $table): void {
                $table->unique('uuid', self::UUID_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(Tables::GALLERIES) || !Schema::hasColumn(Tables::GALLERIES, 'uuid')) {
            return;
        }

        if (Schema::hasIndex(Tables::GALLERIES, self::UUID_INDEX, 'unique')) {
            Schema::table(Tables::GALLERIES, static function (Blueprint $table): void {
                $table->dropUnique(self::UUID_INDEX);
            });
        }

        Schema::table(Tables::GALLERIES, static function (Blueprint $table): void {
            $table->dropColumn('uuid');
        });
    }
};
