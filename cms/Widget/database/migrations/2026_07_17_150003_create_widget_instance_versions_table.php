<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_instance_versions: the IMMUTABLE configured revision (plan Chunk 2
 * Task 13a; architecture.md "widget_instance_versions"; ADR
 * adr-widget-instance-scope-schema.md decision 2, D-007).
 *
 * Owns everything identity does not: pinned legacy widget_id/widget_theme_id
 * (snapshot values, not DB-enforced against cms/Core -- a module-boundary
 * choice; see the deferred migration's docblock for the FK consolidation
 * this schema follows for every project-scoped composite FK), renderer
 * identity, trust classification, canonical schema JSON + hash, normalized
 * values JSON, structured provider references JSON, and the pinned Manifest.
 *
 * Deliberately has NO `artifact_status` column (D-042): a Version is only
 * ever inserted after its exact Blob is verified/readable and Manifest is
 * approved; that verification is Task 15a/15b's materialization/
 * reconciliation responsibility, not a mutable flag on this immutable row.
 *
 * Fully immutable: only `created_at` exists, no `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::INSTANCE_VERSION, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('instance_id');
            $table->string('project_name');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('widget_id');
            $table->unsignedBigInteger('widget_theme_id');
            $table->string('renderer_key');
            $table->string('renderer_version');
            $table->string('trust_classification', 40);
            $table->json('schema');
            $table->char('schema_hash', 64);
            $table->json('values');
            $table->json('provider_refs');
            $table->unsignedBigInteger('artifact_manifest_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['instance_id', 'version'], 'widget_instance_version_instance_version_unique');
            $table->unique(['id', 'project_name'], 'widget_instance_version_id_project_unique');
            $table->unique(['instance_id', 'id', 'project_name'], 'widget_instance_version_instance_id_project_unique');
            $table->index(['project_name', 'artifact_manifest_id'], 'widget_instance_version_project_manifest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::INSTANCE_VERSION);
    }
};
