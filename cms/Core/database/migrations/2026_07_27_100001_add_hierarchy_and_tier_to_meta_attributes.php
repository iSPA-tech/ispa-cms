<?php
/**********************************************************************************************************************
 * Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                       *
 **********************************************************************************************************************/

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-layer spec presentation.
 *
 * `meta_attributes` is the single authority for spec LABELS, so the
 * presentation axis belongs here rather than in a parallel config:
 *
 * - `parent_id`   one level only — a summary attribute ("Lốp / La-zăng")
 *                 owns its detail children (diameter, profile, load index,
 *                 run-flat). Deeper nesting is deliberately not supported.
 * - `display_tier` 1 = shown expanded (~the VnExpress-sized basic sheet),
 *                 2 = rendered in the HTML but collapsed. Both tiers stay
 *                 public and indexable; the tier only drives expand state.
 * - `priority`    stable ordering inside a group; without it row order
 *                 follows whichever source happened to write last.
 * - `compose_rule` how a parent derives its value from children when the
 *                 source ships only the parts instead of the combined
 *                 string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(CoreTableConstant::META_ATTRIBUTES, function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_id')->nullable()->after('id');
            $table->unsignedTinyInteger('display_tier')->default(1)->after('is_filter');
            $table->unsignedSmallInteger('priority')->default(0)->after('display_tier');
            $table->string('compose_rule', 191)->nullable()->after('priority');

            $table->index('parent_id', 'meta_attributes_parent_idx');
            $table->index(['display_tier', 'priority'], 'meta_attributes_tier_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::table(CoreTableConstant::META_ATTRIBUTES, function (Blueprint $table): void {
            $table->dropIndex('meta_attributes_parent_idx');
            $table->dropIndex('meta_attributes_tier_priority_idx');
            $table->dropColumn(['parent_id', 'display_tier', 'priority', 'compose_rule']);
        });
    }
};
