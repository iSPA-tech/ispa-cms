<?php

use App\Constants\Tables;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    private const UUID_INDEX = 'banners_uuid_unique';

    public function up(): void
    {
        if (!Schema::hasTable(Tables::BANNERS)) {
            return;
        }

        if (!Schema::hasColumn(Tables::BANNERS, 'uuid')) {
            Schema::table(Tables::BANNERS, static function (Blueprint $table): void {
                $table->uuid()->nullable()->after('id');
            });
        }

        DB::table(Tables::BANNERS)
            ->whereNull('uuid')
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, static function ($banners): void {
                foreach ($banners as $banner) {
                    DB::table(Tables::BANNERS)
                        ->where('id', $banner->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        if (!Schema::hasIndex(Tables::BANNERS, self::UUID_INDEX, 'unique')) {
            Schema::table(Tables::BANNERS, static function (Blueprint $table): void {
                $table->unique('uuid', self::UUID_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(Tables::BANNERS) || !Schema::hasColumn(Tables::BANNERS, 'uuid')) {
            return;
        }

        if (Schema::hasIndex(Tables::BANNERS, self::UUID_INDEX, 'unique')) {
            Schema::table(Tables::BANNERS, static function (Blueprint $table): void {
                $table->dropUnique(self::UUID_INDEX);
            });
        }

        Schema::table(Tables::BANNERS, static function (Blueprint $table): void {
            $table->dropColumn('uuid');
        });
    }
};
