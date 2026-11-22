<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_instances: reusable IDENTITY ONLY (plan Chunk 2 Task 13a;
 * architecture.md "widget_instances"; ADR adr-widget-instance-scope-schema.md
 * decision 1, D-007, rejecting S-002).
 *
 * Deliberately does NOT contain widget_id, widget_theme_id, schema, values,
 * provider contract, or artifact identity -- that immutable configuration
 * lives entirely on widget_instance_versions. `current_version_id` is
 * intentionally ABSENT from this migration: architecture.md requires it
 * "added by a second migration step after the version table exists", so it
 * is created (nullable) in the deferred
 * *_add_widget_site_layout_foreign_keys migration together with its
 * composite FK -- this is the two-step cyclic-pointer pattern
 * (architecture.md "Foreign keys and deletion"; ADR decision 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::INSTANCE, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->string('project_name');
            $table->string('name');
            $table->string('status', 40)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_name', 'uuid'], 'widget_instance_project_uuid_unique');
            $table->unique(['id', 'project_name'], 'widget_instance_id_project_unique');
            $table->index(['project_name', 'status'], 'widget_instance_project_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::INSTANCE);
    }
};
