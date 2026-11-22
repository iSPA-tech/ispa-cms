<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_placements: layout-version-owned Placement snapshot (plan Chunk 2
 * Task 13a; architecture.md "widget_placements"; ADR
 * adr-widget-instance-scope-schema.md decision 10).
 *
 * `target_type`/`target_id` are their own columns -- target is never encoded
 * into `scope_key` (FR-6/D-032: target resolution must go through the
 * code-registered WidgetTargetContextResolver, never string matching).
 * `target_id` is a string column: targets are opaque string IDs, no integer
 * coercion (plan Task 5b "typed query/page/resolve DTOs... opaque string
 * target IDs, and no integer coercion").
 *
 * `scope_identity` is the canonical NOT NULL cross-database exact-scope
 * value (`global`; `template:<key>`; `target:<type>:<id>`) used for
 * resolver locking/indexing, alongside (not instead of) the separate
 * scope_key/target_type/target_id fields.
 *
 * No candidate key of its own is declared here: nothing in this schema
 * references widget_placements by composite FK (architecture.md's candidate
 * -key list -- Blob/Manifest/Instance/Layout/Instance Version/Layout Version
 * -- deliberately excludes Placement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::PLACEMENT, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('project_name');
            $table->unsignedBigInteger('layout_version_id');
            $table->unsignedBigInteger('widget_instance_version_id');
            $table->string('slot_key');
            $table->string('scope_type', 20);
            $table->string('scope_identity');
            $table->string('scope_key')->nullable();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->json('rules')->nullable();
            $table->timestamps();

            $table->index(['layout_version_id', 'slot_key', 'scope_type'], 'widget_placement_resolution_idx');
            $table->index(['layout_version_id', 'scope_identity'], 'widget_placement_scope_identity_idx');
            $table->index('widget_instance_version_id', 'widget_placement_instance_version_idx');
            $table->index(['project_name', 'target_type', 'target_id'], 'widget_placement_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::PLACEMENT);
    }
};
