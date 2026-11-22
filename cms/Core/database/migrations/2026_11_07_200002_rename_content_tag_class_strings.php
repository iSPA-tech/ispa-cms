<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Content `Tag` / `TagData` shims are gone; the models live in Core. Rows that stored the old class name as a
 * polymorphic type (tracking counters, audit log, slugs, ...) are rewritten to the Core class names. Every step is guarded by
 * hasTable / hasColumn and only touches rows that still carry the old string, so it is idempotent.
 */
return new class extends Migration
{
    /** Old class => new class. */
    private const RENAMES = [
        'Cms\\Content\\Models\\Tag' => 'Cms\\Core\\Models\\Tag',
        'Cms\\Content\\Models\\TagData' => 'Cms\\Core\\Models\\TagData',
    ];

    /** Tables whose `target_type` (or listed column) may hold a Tag class name. */
    private const COLUMNS = [
        'tracks' => 'target_type',
        'tracking_target_totals' => 'target_type',
        'tracking_target_daily_v2' => 'target_type',
        'tracking_top' => 'target_type',
        'tracking_top_daily' => 'target_type',
        'audit_logs' => 'target_type',
        'slugs' => 'target_type',
        'bookmarks' => 'target_type',
        'category_target' => 'target_type',
        'tags_data' => 'target_type',
    ];

    /** Rewrite the stored class names. */
    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }
            foreach (self::RENAMES as $old => $new) {
                DB::table($table)->where($column, $old)->update([$column => $new]);
            }
        }
    }

    /** Not reversible: the Content classes no longer exist. */
    public function down(): void
    {
    }
};
