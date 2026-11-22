<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(CoreTableConstant::ENRICHMENT_SOURCES)) {
            Schema::create(CoreTableConstant::ENRICHMENT_SOURCES, static function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('module', 100);
                $table->string('target_type', 120);
                $table->unsignedBigInteger('target_id')->nullable();
                $table->uuid('target_uuid')->nullable();
                $table->string('source_kind', 80)->default('website');
                $table->text('source_url')->nullable();
                $table->char('source_url_hash', 64)->nullable();
                $table->string('adapter', 160);
                $table->string('ai_schema_key', 180)->nullable();
                $table->string('status', 40)->default('active');
                $table->decimal('default_min_confidence', 5, 4)->nullable();
                $table->unsignedSmallInteger('cadence_interval_days')->default(7);
                $table->unsignedSmallInteger('min_interval_days')->default(1);
                $table->unsignedSmallInteger('max_interval_days')->default(60);
                $table->timestamp('next_run_at')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_changed_at')->nullable();
                $table->timestamp('last_unchanged_at')->nullable();
                $table->json('field_map')->nullable();
                $table->json('confidence_thresholds')->nullable();
                $table->json('confirmed_field_paths')->nullable();
                $table->json('settings')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['module', 'target_type', 'status'], 'enrichment_sources_target_status_idx');
                $table->index(['status', 'next_run_at'], 'enrichment_sources_due_idx');
                $table->index('source_url_hash', 'enrichment_sources_url_hash_idx');
                $table->index('target_uuid', 'enrichment_sources_target_uuid_idx');
                $table->index(['module', 'target_type', 'target_id', 'source_url_hash'], 'enrichment_sources_target_url_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::ENRICHMENT_SOURCES);
    }
};
