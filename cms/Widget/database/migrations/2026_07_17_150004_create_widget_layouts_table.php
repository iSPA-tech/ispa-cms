<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_layouts: project-owned layout identity (plan Chunk 2 Task 13a;
 * architecture.md "widget_layouts").
 *
 * `current_draft_version_id` and `current_published_version_id` are
 * intentionally ABSENT here -- architecture.md requires them "both added
 * after child tables exist", so they are created (nullable) in the deferred
 * *_add_widget_site_layout_foreign_keys migration together with their
 * composite FKs (two-step cyclic-pointer pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::LAYOUT, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->string('project_name');
            $table->string('key')->default('site');
            $table->timestamps();

            $table->unique(['project_name', 'key'], 'widget_layout_project_key_unique');
            $table->unique(['id', 'project_name'], 'widget_layout_id_project_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::LAYOUT);
    }
};
