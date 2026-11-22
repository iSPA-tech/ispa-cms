<?php

use App\Constants\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable(Tables::TRACKING_PRUNE_RUNS)) {
            return;
        }

        Schema::create(Tables::TRACKING_PRUNE_RUNS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->dateTime('cutoff_at');
            $table->unsignedBigInteger('freeze_max_id');
            $table->unsignedInteger('window_size');
            $table->string('status', 24);
            $table->unsignedBigInteger('expected_rows');
            $table->bigInteger('expected_views');
            $table->unsignedBigInteger('current_window_start')->nullable();
            $table->unsignedBigInteger('current_window_end')->nullable();
            $table->unsignedBigInteger('last_processed_id')->default(0);
            $table->unsignedBigInteger('deleted_rows')->default(0);
            $table->bigInteger('deleted_views')->default(0);
            $table->char('reconciliation_hash', 64);
            $table->dateTime('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at'], 'tracking_prune_runs_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::TRACKING_PRUNE_RUNS);
    }
};
