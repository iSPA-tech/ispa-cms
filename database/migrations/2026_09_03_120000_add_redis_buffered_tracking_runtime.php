<?php

use App\Constants\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable(Tables::TRACKING_COUNTER_RUNTIME)) {
            Schema::create(Tables::TRACKING_COUNTER_RUNTIME, static function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->string('active_driver', 16)->default('receipt');
                $table->string('transition_state', 32)->default('stable');
                $table->unsignedBigInteger('epoch')->default(1);
                $table->dateTime('changed_at')->nullable();
                $table->string('changed_by')->nullable();
                $table->string('change_reason')->nullable();
                $table->timestamps();
            });
        }

        DB::table(Tables::TRACKING_COUNTER_RUNTIME)->insertOrIgnore([
            'id' => 1,
            'active_driver' => 'receipt',
            'transition_state' => 'stable',
            'epoch' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!Schema::hasTable(Tables::TRACKING_COUNTER_FLUSH_BATCHES)) {
            Schema::create(Tables::TRACKING_COUNTER_FLUSH_BATCHES, static function (Blueprint $table): void {
                $table->id();
                $table->string('bucket_id', 96)->unique();
                $table->char('checksum', 64);
                $table->string('status', 16);
                $table->unsignedBigInteger('write_generation');
                $table->json('deltas');
                $table->unsignedInteger('target_count')->default(0);
                $table->unsignedBigInteger('total_views')->default(0);
                $table->dateTime('applied_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::TRACKING_COUNTER_FLUSH_BATCHES);
        Schema::dropIfExists(Tables::TRACKING_COUNTER_RUNTIME);
    }
};
