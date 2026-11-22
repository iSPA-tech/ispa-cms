<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(CoreTableConstant::PUBLISHED_DATA_SOURCE_CLUSTERS)) {
            Schema::create(CoreTableConstant::PUBLISHED_DATA_SOURCE_CLUSTERS, static function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('module', 80);
                $table->string('profile_slug', 64);
                $table->string('cluster_type', 64)->default('source_cluster');
                $table->char('cluster_key', 64);
                $table->string('language_code', 10)->default('vi');
                $table->string('source_domain', 255)->nullable();
                $table->string('source_url', 2048)->nullable();
                $table->char('source_url_hash', 64)->nullable();
                $table->string('status', 40)->default('active');
                $table->string('title', 255)->nullable();
                $table->unsignedInteger('source_count')->default(0);
                $table->boolean('strict_verification_required')->default(false);
                $table->boolean('allow_published_first')->default(false);
                $table->string('canonical_target_type', 120)->nullable();
                $table->unsignedBigInteger('canonical_target_id')->nullable();
                $table->uuid('canonical_target_uuid')->nullable();
                $table->json('match_payload')->nullable();
                $table->json('decision_payload')->nullable();
                $table->json('synthesis_payload')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['module', 'profile_slug', 'cluster_key'], 'core_pub_data_cluster_identity_unique');
                $table->index(['module', 'profile_slug', 'status'], 'core_pub_data_cluster_module_status_idx');
                $table->index(['module', 'cluster_type'], 'core_pub_data_cluster_type_idx');
                $table->index('source_domain', 'core_pub_data_cluster_domain_idx');
                $table->index('canonical_target_uuid', 'core_pub_data_cluster_target_uuid_idx');
            });
        }

        if (! Schema::hasTable(CoreTableConstant::PUBLISHED_DATA_CANDIDATES)) {
            Schema::create(CoreTableConstant::PUBLISHED_DATA_CANDIDATES, static function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('source_cluster_id')
                    ->nullable()
                    ->constrained(CoreTableConstant::PUBLISHED_DATA_SOURCE_CLUSTERS)
                    ->nullOnDelete();
                $table->string('module', 80);
                $table->string('profile_slug', 64);
                $table->string('candidate_type', 80);
                $table->string('target_type', 120)->nullable();
                $table->string('source', 80)->nullable();
                $table->string('external_type', 80)->nullable();
                $table->string('external_id', 255)->nullable();
                $table->string('external_key', 255)->nullable();
                $table->char('identity_hash', 64);
                $table->string('source_label', 255)->nullable();
                $table->string('source_url', 2048)->nullable();
                $table->char('source_url_hash', 64)->nullable();
                $table->string('canonical_key', 255)->nullable();
                $table->char('canonical_hash', 64)->nullable();
                $table->char('content_hash', 64)->nullable();
                $table->char('normalized_hash', 64)->nullable();
                $table->string('language_code', 10)->default('vi');
                $table->string('status', 40)->default('staged');
                $table->string('proposed_action', 64)->default('create');
                $table->string('match_status', 64)->nullable();
                $table->decimal('match_score', 5, 4)->nullable();
                $table->decimal('confidence_score', 5, 2)->nullable();
                $table->json('raw_payload')->nullable();
                $table->json('candidate_payload')->nullable();
                $table->json('normalized_payload')->nullable();
                $table->json('evidence_payload')->nullable();
                $table->json('match_payload')->nullable();
                $table->json('decision_payload')->nullable();
                $table->json('quality_payload')->nullable();
                $table->decimal('quality_score', 5, 2)->nullable();
                $table->boolean('strict_verification_required')->default(false);
                $table->boolean('publish_ready')->default(false);
                $table->string('canonical_target_type', 120)->nullable();
                $table->unsignedBigInteger('canonical_target_id')->nullable();
                $table->uuid('canonical_target_uuid')->nullable();
                $table->datetime('reviewed_at')->nullable();
                $table->datetime('published_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['module', 'profile_slug', 'identity_hash'], 'core_pub_data_candidate_identity_unique');
                $table->index('source_cluster_id', 'core_pub_data_candidate_cluster_idx');
                $table->index(['module', 'profile_slug', 'status'], 'core_pub_data_candidate_module_status_idx');
                $table->index(['candidate_type', 'status'], 'core_pub_data_candidate_type_status_idx');
                $table->index('source_url_hash', 'core_pub_data_candidate_url_hash_idx');
                $table->index('canonical_hash', 'core_pub_data_candidate_canonical_hash_idx');
                $table->index('content_hash', 'core_pub_data_candidate_content_hash_idx');
                $table->index('publish_ready', 'core_pub_data_candidate_publish_ready_idx');
                $table->index('canonical_target_uuid', 'core_pub_data_candidate_target_uuid_idx');
            });
        }

        if (! Schema::hasTable(CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS)) {
            Schema::create(CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS, static function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('source_cluster_id')
                    ->nullable()
                    ->constrained(CoreTableConstant::PUBLISHED_DATA_SOURCE_CLUSTERS)
                    ->nullOnDelete();
                $table->foreignId('candidate_id')
                    ->nullable()
                    ->constrained(CoreTableConstant::PUBLISHED_DATA_CANDIDATES)
                    ->nullOnDelete();
                $table->string('module', 80);
                $table->string('profile_slug', 64);
                $table->string('owner_type', 100)->nullable();
                $table->uuid('owner_uuid')->nullable();
                $table->string('embedding_purpose', 64)->default('dedupe');
                $table->string('language_code', 10)->default('vi');
                $table->char('text_hash', 64);
                $table->string('embedding_model', 96);
                $table->string('embedding_model_version', 96)->default('default');
                $table->unsignedSmallInteger('embedding_dimensions');
                $table->json('embedding_json');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('source_cluster_id', 'core_pub_data_embedding_cluster_idx');
                $table->index('candidate_id', 'core_pub_data_embedding_candidate_idx');
                $table->index(['module', 'profile_slug', 'language_code'], 'core_pub_data_embedding_profile_idx');
                $table->index(['owner_type', 'owner_uuid'], 'core_pub_data_embedding_owner_idx');
                $table->index('text_hash', 'core_pub_data_embedding_text_hash_idx');
                $table->unique(
                    ['owner_type', 'owner_uuid', 'embedding_model', 'embedding_model_version', 'text_hash'],
                    'core_pub_data_embedding_owner_unique',
                );
            });
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $dimensions = max(1, (int) env('CORE_PUBLISHED_DATA_EMBEDDING_DIMENSIONS', 1536));

        if ($this->pgVectorExtensionInstalled()) {
            DB::statement(sprintf(
                'ALTER TABLE %s ADD COLUMN embedding_vector vector(%d)',
                CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS,
                $dimensions,
            ));
            DB::statement(sprintf(
                'CREATE INDEX core_published_data_embedding_vector_idx ON %s USING ivfflat (embedding_vector vector_cosine_ops) WITH (lists = 100)',
                CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS,
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::PUBLISHED_DATA_EMBEDDINGS);
        Schema::dropIfExists(CoreTableConstant::PUBLISHED_DATA_CANDIDATES);
        Schema::dropIfExists(CoreTableConstant::PUBLISHED_DATA_SOURCE_CLUSTERS);
    }

    private function pgVectorExtensionInstalled(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        return (bool) DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'vector' LIMIT 1");
    }
};
