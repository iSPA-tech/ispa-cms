<?php

declare(strict_types=1);

use Cms\Content\Constants\ContentTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**********************************************************************************************************************
 * Copyright (c) 2026 iSPA Team
 **********************************************************************************************************************/

/**
 * Public feed and admin dashboard indexes on `posts`.
 *
 * Moved out of the root migrations 2026_08_08_000001_add_public_feed_indexes and
 * 2026_10_23_000001_add_admin_dashboard_indexes (the module that owns a table owns its
 * migrations). A database that already ran those files has both indexes, so this migration only records itself there.
 * Adds indexes only; nothing is dropped or renamed in up() (Content is schema-guarded for online upgrades).
 */
return new class extends Migration {
    /** @var array<string, list<string>> index name => columns */
    private const INDEXES = [
        'posts_public_feed_index' => ['type', 'status', 'language_id', 'published_at'],
        'posts_status_created_at_dashboard_index' => ['status', 'created_at'],
    ];

    public function up(): void
    {
        $table = ContentTableConstant::POSTS;

        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if (! Schema::hasColumns($table, $columns)
                || Schema::hasIndex($table, $columns)
                || Schema::hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, static fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        $table = ContentTableConstant::POSTS;

        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, static fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
