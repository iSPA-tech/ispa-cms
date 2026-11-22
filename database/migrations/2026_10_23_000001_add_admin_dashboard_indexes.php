<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Core indexes used by the aggregate admin dashboard summary.
 * Guarded so re-runs are safe.
 *
 * The order and post indexes this file also added live in their modules since 2026-11-21 (root migrations hold Core
 * tables only): cms/Ecommerce/database/migrations/2026_11_21_000001_add_orders_dashboard_index.php and
 * cms/Content/database/migrations/2026_11_21_000001_add_posts_feed_and_dashboard_indexes.php. Every statement is
 * guarded, so editing this applied migration changes nothing on a database that already ran it.
 */
return new class extends Migration
{
    /**
     * Index definitions: table => [index name => columns].
     *
     * @return array<string,array<string,list<string>>>
     */
    private function indexes(): array
    {
        return [
            'comments' => ['comments_status_created_at_dashboard_index' => ['status', 'created_at']],
            'users' => ['users_type_status_created_at_dashboard_index' => ['type', 'status', 'created_at']],
        ];
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->indexes() as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($defs as $name => $columns) {
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $columns) || Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, static fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->indexes() as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($defs) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, static fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
