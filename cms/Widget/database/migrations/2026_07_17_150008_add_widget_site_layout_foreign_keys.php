<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred migration: two-step cyclic pointers PLUS every composite
 * restrictive FK in the Widget Site Layout schema (plan Chunk 2 Task 13a;
 * architecture.md "Foreign keys and deletion"; ADR
 * adr-widget-instance-scope-schema.md decision 13; ADR
 * adr-widget-database-portability.md decisions 6 and 8).
 *
 * INTERPRETATION DISCLOSED: the eight create-table migrations leave every
 * composite FK -- not only the three genuinely cyclic pointers -- undeclared,
 * and this single migration adds all eleven of them together. The
 * authoritative sources (adr-widget-instance-scope-schema.md decision 13 and
 * architecture.md "Foreign keys and deletion") scope the mandatory two-step
 * deferral specifically to the cyclic current-version/draft/published/
 * lineage pointers; the six non-cyclic composite FKs (Manifest->Blob,
 * InstanceVersion->Instance/Manifest, LayoutVersion->Layout,
 * Placement->LayoutVersion/InstanceVersion) COULD have been added inline in
 * their own creation migrations since migration ordering already guarantees
 * each referenced parent table and its candidate key exist by then. This
 * migration nonetheless consolidates ALL eleven composite FKs here because:
 * (a) this task's own design notes say the deferred migration adds "all
 * composite FKs that need both tables"; (b) the migration file name itself
 * is the general "*_add_widget_site_layout_foreign_keys", not a narrower
 * "*_add_widget_cyclic_pointers"; (c) it gives ONE place to reason about and
 * dual-engine-test "restrictive-only, no cascade" for every relationship in
 * this new-ground composite-FK mechanism (adr-widget-database-portability.md
 * decision 6). No authoritative source forbids this consolidation.
 *
 * DRIVER NOTE (documented per adr-widget-database-portability.md decision 7's
 * driver-branch precedent): SQLite's schema grammar compiles
 * `Schema::table()->foreign()` to NO SQL at all (verified by reading
 * SQLiteGrammar::compileForeign(), which is a documented no-op for
 * ALTER-added foreign keys) -- so on sqlite every `foreign()` call below is
 * silently inert (neither declared nor enforced; proof is
 * WidgetDatabaseIntegrityTest's driver-aware skip). `down()` must still use
 * the exact same custom constraint names on mysql/pgsql to precisely target
 * what was created, but SQLite's `compileDropForeign()` throws unless given
 * the column-array form (it never resolves by name) -- hence the driver
 * branch in `down()` below. This divergence is intentional and harmless: it
 * only ever no-ops against a constraint that itself was never created.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: create the deferred nullable cyclic-pointer columns.
        Schema::table(Tables::INSTANCE, static function (Blueprint $table): void {
            $table->unsignedBigInteger('current_version_id')->nullable()->after('status');
        });

        Schema::table(Tables::LAYOUT, static function (Blueprint $table): void {
            $table->unsignedBigInteger('current_draft_version_id')->nullable()->after('key');
            $table->unsignedBigInteger('current_published_version_id')->nullable()->after('current_draft_version_id');
        });

        // Step 2: non-cyclic composite restrictive FKs (see docblock for why
        // these are consolidated here rather than added inline earlier).
        Schema::table(Tables::ARTIFACT_MANIFEST, static function (Blueprint $table): void {
            $table->foreign(['artifact_blob_id', 'project_name'], 'widget_manifest_blob_fk')
                ->references(['id', 'project_name'])->on(Tables::ARTIFACT_BLOB)
                ->restrictOnDelete();
        });

        Schema::table(Tables::INSTANCE_VERSION, static function (Blueprint $table): void {
            $table->foreign(['instance_id', 'project_name'], 'widget_instance_version_instance_fk')
                ->references(['id', 'project_name'])->on(Tables::INSTANCE)
                ->restrictOnDelete();

            $table->foreign(['artifact_manifest_id', 'project_name'], 'widget_instance_version_manifest_fk')
                ->references(['id', 'project_name'])->on(Tables::ARTIFACT_MANIFEST)
                ->restrictOnDelete();
        });

        Schema::table(Tables::LAYOUT_VERSION, static function (Blueprint $table): void {
            $table->foreign(['layout_id', 'project_name'], 'widget_layout_version_layout_fk')
                ->references(['id', 'project_name'])->on(Tables::LAYOUT)
                ->restrictOnDelete();

            // Self-referential lineage pointers -- same Layout owner shape.
            //
            // KNOWN ENGINE FINDING (2026-07-17): on Homebrew MySQL 9.6.0
            // these two SELF-referencing composite FKs (child and parent
            // column lists sharing `layout_id`) register in
            // information_schema/INNODB_FOREIGN metadata but are NOT
            // enforced on INSERT/UPDATE -- reproduced minimally with raw SQL
            // outside Laravel. Every non-self-referencing composite FK in
            // this migration enforces correctly on the same build. The
            // widget-site-layout-db-matrix job (mariadb:10 + postgres:16,
            // this schema's actual target engines) is the arbiter; see the
            // based_on/rollback_of tests in WidgetDatabaseIntegrityTest.
            $table->foreign(['layout_id', 'based_on_version_id', 'project_name'], 'widget_layout_version_based_on_fk')
                ->references(['layout_id', 'id', 'project_name'])->on(Tables::LAYOUT_VERSION)
                ->restrictOnDelete();

            $table->foreign(['layout_id', 'rollback_of_version_id', 'project_name'], 'widget_layout_version_rollback_of_fk')
                ->references(['layout_id', 'id', 'project_name'])->on(Tables::LAYOUT_VERSION)
                ->restrictOnDelete();
        });

        Schema::table(Tables::PLACEMENT, static function (Blueprint $table): void {
            $table->foreign(['layout_version_id', 'project_name'], 'widget_placement_layout_version_fk')
                ->references(['id', 'project_name'])->on(Tables::LAYOUT_VERSION)
                ->restrictOnDelete();

            $table->foreign(['widget_instance_version_id', 'project_name'], 'widget_placement_instance_version_fk')
                ->references(['id', 'project_name'])->on(Tables::INSTANCE_VERSION)
                ->restrictOnDelete();
        });

        // Step 3: the genuinely cyclic current-version/draft/published pointer
        // FKs -- the reason this migration must be deferred at all.
        Schema::table(Tables::INSTANCE, static function (Blueprint $table): void {
            $table->foreign(['id', 'current_version_id', 'project_name'], 'widget_instance_current_version_fk')
                ->references(['instance_id', 'id', 'project_name'])->on(Tables::INSTANCE_VERSION)
                ->restrictOnDelete();
        });

        Schema::table(Tables::LAYOUT, static function (Blueprint $table): void {
            $table->foreign(['id', 'current_draft_version_id', 'project_name'], 'widget_layout_current_draft_fk')
                ->references(['layout_id', 'id', 'project_name'])->on(Tables::LAYOUT_VERSION)
                ->restrictOnDelete();

            $table->foreign(['id', 'current_published_version_id', 'project_name'], 'widget_layout_current_published_fk')
                ->references(['layout_id', 'id', 'project_name'])->on(Tables::LAYOUT_VERSION)
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        // SQLite's compileDropForeign() throws unless given the column-array
        // form (it never resolves a constraint by name); mysql/pgsql need the
        // exact custom name to target what was actually created above.
        $drop = static function (string $table, array $columns, string $name) use ($isSqlite): void {
            Schema::table($table, static function (Blueprint $blueprint) use ($isSqlite, $columns, $name): void {
                $blueprint->dropForeign($isSqlite ? $columns : $name);
            });
        };

        $drop(Tables::LAYOUT, ['id', 'current_published_version_id', 'project_name'], 'widget_layout_current_published_fk');
        $drop(Tables::LAYOUT, ['id', 'current_draft_version_id', 'project_name'], 'widget_layout_current_draft_fk');
        $drop(Tables::INSTANCE, ['id', 'current_version_id', 'project_name'], 'widget_instance_current_version_fk');

        $drop(Tables::PLACEMENT, ['widget_instance_version_id', 'project_name'], 'widget_placement_instance_version_fk');
        $drop(Tables::PLACEMENT, ['layout_version_id', 'project_name'], 'widget_placement_layout_version_fk');

        $drop(Tables::LAYOUT_VERSION, ['layout_id', 'rollback_of_version_id', 'project_name'], 'widget_layout_version_rollback_of_fk');
        $drop(Tables::LAYOUT_VERSION, ['layout_id', 'based_on_version_id', 'project_name'], 'widget_layout_version_based_on_fk');
        $drop(Tables::LAYOUT_VERSION, ['layout_id', 'project_name'], 'widget_layout_version_layout_fk');

        $drop(Tables::INSTANCE_VERSION, ['artifact_manifest_id', 'project_name'], 'widget_instance_version_manifest_fk');
        $drop(Tables::INSTANCE_VERSION, ['instance_id', 'project_name'], 'widget_instance_version_instance_fk');

        $drop(Tables::ARTIFACT_MANIFEST, ['artifact_blob_id', 'project_name'], 'widget_manifest_blob_fk');

        Schema::table(Tables::LAYOUT, static function (Blueprint $table): void {
            $table->dropColumn(['current_draft_version_id', 'current_published_version_id']);
        });

        Schema::table(Tables::INSTANCE, static function (Blueprint $table): void {
            $table->dropColumn('current_version_id');
        });
    }
};
