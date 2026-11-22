<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_artifact_blobs: immutable project-scoped byte metadata (plan Chunk
 * 2 Task 13a; architecture.md "widget_artifact_blobs"; ADR
 * adr-widget-artifact-store.md decision 10, D-012/D-042).
 *
 * Root table -- no foreign keys. Content-addressed identity is
 * (project_name, artifact_hash); a Blob row NEVER carries manifest,
 * provider, ownership, label, source, or secret-value metadata (that
 * provenance lives exclusively on widget_artifact_manifests).
 *
 * Fully immutable: only `created_at` exists, no `updated_at` -- an existing
 * hash with different bytes is an integrity failure, never an overwrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::ARTIFACT_BLOB, static function (Blueprint $table): void {
            $table->id();
            $table->string('project_name');
            $table->char('artifact_hash', 64);
            $table->string('storage_disk');
            $table->string('storage_key', 512);
            $table->unsignedBigInteger('byte_size');
            $table->string('media_type');
            $table->string('checksum');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_name', 'artifact_hash'], 'widget_artifact_blob_project_hash_unique');
            $table->unique(['id', 'project_name'], 'widget_artifact_blob_id_project_unique');
            $table->unique(['project_name', 'storage_key'], 'widget_artifact_blob_project_storage_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::ARTIFACT_BLOB);
    }
};
