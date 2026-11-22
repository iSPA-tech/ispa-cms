<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_artifact_manifests: immutable render/provenance identity plus
 * audited eligibility attestation and independent emergency runtime control
 * (plan Chunk 2 Task 13a; architecture.md "widget_artifact_manifests"; ADR
 * adr-widget-artifact-store.md decision 10).
 *
 * `artifact_blob_id` is a plain column here -- created before its composite
 * restrictive FK to widget_artifact_blobs(id, project_name), which is added
 * in the deferred *_add_widget_site_layout_foreign_keys migration alongside
 * every other composite FK in this schema (see that migration's docblock
 * for the interpretation this consolidation follows).
 *
 * Canonical provenance fields (manifest_hash, artifact_blob_id, generator/
 * renderer identity, applicability, fingerprints, provider_contracts, trust
 * policy/lane) never mutate. Only attestation metadata (eligibility_state,
 * attestation_version, approved/revoked actor+time) and independent
 * emergency control metadata (control_version, blocked, blocked/cleared
 * actor+time, reasons) are ever updated -- hence `created_at`/`updated_at`
 * both exist here, unlike the fully immutable Blob row.
 *
 * No DB enum: eligibility_state/trust_lane/etc. are plain strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::ARTIFACT_MANIFEST, static function (Blueprint $table): void {
            $table->id();
            $table->string('project_name');
            $table->char('manifest_hash', 64);
            $table->unsignedBigInteger('artifact_blob_id');
            $table->string('generator_version');
            $table->string('renderer_key');
            $table->string('renderer_version');
            $table->json('applicability');
            $table->char('executable_surface_fingerprint_hash', 64);
            $table->char('canonical_schema_hash', 64);
            $table->json('provider_contracts');
            $table->string('trust_policy_version');
            $table->string('trust_lane');

            // Monotonic eligibility attestation (pending|approved|revoked, app-level only).
            $table->string('eligibility_state', 40)->default('pending');
            $table->unsignedInteger('attestation_version')->default(1);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('eligibility_reason')->nullable();

            // Independent emergency runtime control (separate axis from eligibility).
            $table->unsignedInteger('control_version')->default(1);
            $table->boolean('blocked')->default(false);
            $table->unsignedBigInteger('blocked_by')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->unsignedBigInteger('cleared_by')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->text('control_reason')->nullable();

            $table->timestamps();

            $table->unique(['project_name', 'manifest_hash'], 'widget_manifest_project_hash_unique');
            $table->unique(['id', 'project_name'], 'widget_manifest_id_project_unique');
            $table->index(['project_name', 'eligibility_state'], 'widget_manifest_project_eligibility_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::ARTIFACT_MANIFEST);
    }
};
