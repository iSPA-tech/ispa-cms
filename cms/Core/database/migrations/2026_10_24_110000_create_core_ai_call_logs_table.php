<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the per-call LLM trace log (metadata only, no prompt or response content).
     *
     * `called_at` is a dateTime (UTC), not a second timestamp column, so MariaDB with
     * explicit_defaults_for_timestamp OFF does not inject defaults.
     */
    public function up(): void
    {
        if (Schema::hasTable(CoreTableConstant::AI_CALL_LOGS)) {
            return;
        }

        Schema::create(CoreTableConstant::AI_CALL_LOGS, static function (Blueprint $table): void {
            $table->id();
            $table->dateTime('called_at')->index();
            $table->string('task_key', 150);
            $table->string('provider', 40);
            $table->string('model', 120);
            $table->string('via', 20);
            $table->unsignedSmallInteger('attempt');
            $table->boolean('is_fallback')->default(false);
            $table->boolean('failed')->default(false);
            $table->string('refused_reason', 40)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->char('prompt_hash', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('origin', 20);

            $table->index(['task_key', 'called_at']);
            $table->index('prompt_hash');
            $table->index('request_id');
        });
    }

    /**
     * Drop the call log table.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::AI_CALL_LOGS);
    }
};
