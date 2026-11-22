<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(CoreTableConstant::AI_EXTRACTION_RUNS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('module', 120)->nullable();
            $table->string('task', 160)->nullable();
            $table->string('schema_name', 180);
            $table->string('schema_version', 60)->default('v1');
            $table->string('provider', 80)->default('agentic_ai');
            $table->string('model', 140);
            $table->char('input_hash', 64);
            $table->char('schema_hash', 64);
            $table->char('instruction_hash', 64);
            $table->char('request_hash', 64)->unique();
            $table->unsignedInteger('input_bytes')->default(0);
            $table->unsignedInteger('prompt_bytes')->default(0);
            $table->json('context_payload')->nullable();
            $table->json('schema_payload')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('parsed_payload')->nullable();
            $table->json('usage_payload')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('status', 40)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'schema_name'], 'core_ai_extract_status_schema_idx');
            $table->index(['module', 'task'], 'core_ai_extract_module_task_idx');
            $table->index(['input_hash', 'schema_hash'], 'core_ai_extract_input_schema_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::AI_EXTRACTION_RUNS);
    }
};
