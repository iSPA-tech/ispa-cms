<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the daily LLM usage ledger (one row per date, provider, model and task).
     */
    public function up(): void
    {
        if (Schema::hasTable(CoreTableConstant::AI_USAGE_DAILY)) {
            return;
        }

        Schema::create(CoreTableConstant::AI_USAGE_DAILY, static function (Blueprint $table): void {
            $table->id();
            $table->date('usage_date');
            $table->char('month', 7);
            $table->string('provider', 40);
            $table->string('model', 120);
            $table->string('task_key', 150);
            $table->unsignedBigInteger('request_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('fallback_count')->default(0);
            $table->unsignedBigInteger('refused_count')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('latency_ms_total')->default(0);
            $table->decimal('estimated_cost_usd', 14, 6)->default(0);
            $table->timestamps();

            $table->unique(['usage_date', 'provider', 'model', 'task_key'], 'core_ai_usage_day_key_unique');
            $table->index('month', 'core_ai_usage_month_idx');
        });
    }

    /**
     * Drop the daily LLM usage ledger.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::AI_USAGE_DAILY);
    }
};
