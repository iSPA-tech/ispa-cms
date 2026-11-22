<?php

use App\Helpers\SchemaHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin track listing filters on `tracks.updated_at`, which had no index at all.
 *
 * The public feed index on the Content posts table that this file also added lives in
 * cms/Content/database/migrations/2026_11_21_000001_add_posts_feed_and_dashboard_indexes.php since 2026-11-21
 * (root migrations hold Core tables only). Every statement is guarded, so editing this applied migration changes
 * nothing on a database that already ran it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tracks') && ! Schema::hasIndex('tracks', 'tracks_updated_at_index')) {
            Schema::table('tracks', static function (Blueprint $table) {
                $table->index(['updated_at'], 'tracks_updated_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tracks')) {
            Schema::table('tracks', static function (Blueprint $table) {
                SchemaHelper::dropIndexIfExists($table, 'tracks', 'tracks_updated_at_index');
            });
        }
    }
};
