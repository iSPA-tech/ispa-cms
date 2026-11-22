<?php

namespace Tests\Support;

use App\Constants\Tables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class TrackingTestSchema
{
    public static function create(bool $withFinalConstraints = true): void
    {
        self::drop();

        Schema::create(Tables::TRACKS, static function (Blueprint $table): void {
            $table->id();
            $table->json('tags')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->default(0);
            $table->ipAddress('ip_address')->nullable();
            $table->text('agent')->nullable();
            $table->text('url')->nullable();
            $table->string('session_id')->nullable();
            $table->bigInteger('view')->default(0);
            $table->timestamps();
            $table->index(['session_id', 'target_type', 'target_id']);
        });

        Schema::create(Tables::TRACKING_DAILY, static function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('views')->default(0);
            $table->bigInteger('visitors')->default(0);
            $table->bigInteger('place_scan')->default(0);
            $table->bigInteger('item_scan')->default(0);
            $table->bigInteger('scans')->default(0);
            $table->bigInteger('new_register')->default(0);
            $table->bigInteger('login')->default(0);
            $table->bigInteger('logout')->default(0);
            $table->bigInteger('subscribe')->default(0);
            $table->bigInteger('unsubscribe')->default(0);
            $table->bigInteger('new_order')->default(0);
            $table->bigInteger('income')->default(0);
            $table->bigInteger('comments')->default(0);
            $table->bigInteger('download')->default(0);
            $table->bigInteger('sessions')->default(0);
            $table->bigInteger('payments')->default(0);
            $table->date('date')->nullable();
            $table->timestamps();
        });

        Schema::create(Tables::TRACKING_TOP_DAILY, static function (Blueprint $table): void {
            $table->id();
            $table->date('date')->nullable();
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->bigInteger('total_views')->default(0);
            $table->timestamps();
            $table->index(['date', 'target_id', 'target_type']);
        });

        Schema::create(Tables::TRACKING_TOP, static function (Blueprint $table): void {
            $table->id();
            $table->string('range_name', 50)->nullable();
            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->bigInteger('total_views')->default(0);
            $table->integer('rank')->default(0);
            $table->timestamps();
        });

        $migration = require database_path(
            'migrations/2026_07_23_120000_add_aggregate_first_tracking_schema.php'
        );
        $migration->up();
        (require database_path(
            'migrations/2026_07_23_190000_create_tracking_prune_runs.php'
        ))->up();

        if ($withFinalConstraints) {
            Schema::table(Tables::TRACKING_DAILY, static function (Blueprint $table): void {
                $table->unique('date', 'tracking_daily_date_unique');
            });
            Schema::table(Tables::TRACKING_TARGET_DAILY, static function (Blueprint $table): void {
                $table->unique(
                    ['date', 'target_key'],
                    'tracking_target_daily_date_key_unique'
                );
            });
        }
    }

    public static function drop(): void
    {
        foreach (self::tables() as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        return [
            Tables::TRACKING_COUNTER_FLUSH_BATCHES,
            Tables::TRACKING_COUNTER_RUNTIME,
            Tables::TRACKING_PRUNE_RUNS,
            Tables::TRACKING_EVENT_RECEIPTS,
            Tables::TRACKING_GENERATION_RUNS,
            Tables::TRACKING_RUNTIME_STATE,
            Tables::TRACKING_TARGET_CATALOG,
            Tables::TRACKING_TARGET_TOTALS,
            Tables::TRACKING_SITE_TOTALS,
            Tables::TRACKING_TARGET_DAILY,
            Tables::TRACKING_TOP,
            Tables::TRACKING_TOP_DAILY,
            Tables::TRACKING_DAILY,
            Tables::TRACKS,
        ];
    }
}
