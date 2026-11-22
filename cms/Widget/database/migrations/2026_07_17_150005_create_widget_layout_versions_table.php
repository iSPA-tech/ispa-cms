<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_layout_versions: draft or released layout snapshot (plan Chunk 2
 * Task 13a; architecture.md "widget_layout_versions").
 *
 * `state` is frozen to draft|superseded|release at the APPLICATION layer
 * only -- a plain string column, no native DB enum (plan "no database
 * enum"). Only the row referenced by widget_layouts.current_draft_version_id
 * is treated as mutable by convention; Releases are append-only and
 * immutable (enforcement is service-layer, later tasks).
 *
 * `based_on_version_id`/`rollback_of_version_id` columns are created here
 * (nullable, no FK yet) because architecture.md lists them among this
 * table's own initial columns, unlike widget_layouts'/widget_instances'
 * pointers which are described as "added after". Their composite
 * same-Layout-owner FK -- `(layout_id, based_on_version_id|rollback_of_version_id,
 * project_name) -> (layout_id, id, project_name)` -- is self-referential and
 * is added in the deferred *_add_widget_site_layout_foreign_keys migration
 * per the plan checklist's explicit instruction ("...in the deferred
 * migration").
 *
 * `registry_contracts` (json) + `registry_contract_hash` snapshot only
 * referenced Slot/Template Context/Target Context/provider semantic fields
 * (architecture.md); `eligibility_snapshot` (json, nullable) is populated
 * only on Release rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::LAYOUT_VERSION, static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('layout_id');
            $table->string('project_name');
            $table->unsignedInteger('version');
            $table->string('state', 20)->default('draft');
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->unsignedBigInteger('rollback_of_version_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->json('registry_contracts');
            $table->char('registry_contract_hash', 64);
            $table->json('eligibility_snapshot')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamps();

            $table->unique(['layout_id', 'version'], 'widget_layout_version_layout_version_unique');
            $table->unique(['id', 'project_name'], 'widget_layout_version_id_project_unique');
            $table->unique(['layout_id', 'id', 'project_name'], 'widget_layout_version_layout_id_project_unique');
            $table->index(['layout_id', 'state'], 'widget_layout_version_layout_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::LAYOUT_VERSION);
    }
};
