<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the Widget site-layout editor translation rows under their own group prefix.
 *
 * The rows were stored as `editor.*` in group `widget_site_layout`. The locale generator
 * shards admin keys by the first segment of the full row name, so they landed in the
 * `editor` group while the components call `$t('widget_site_layout.editor.*')`, which
 * showed raw labels. The translation importer matches rows by name and never renames
 * them, so the rename happens here, before `cms:translation-sync` runs on deploy.
 */
return new class extends Migration
{
    private const GROUP = 'widget_site_layout';

    private const LEGACY_PREFIX = 'editor.';

    private const CANONICAL_PREFIX = 'widget_site_layout.editor.';

    /**
     * Rename each legacy `editor.*` row in place; drop it when the canonical row already exists.
     *
     * Safe to run repeatedly: once renamed, no legacy rows remain to match.
     */
    public function up(): void
    {
        if (! Schema::hasTable(CoreTableConstant::TRANSLATES)) {
            return;
        }

        $rows = DB::table(CoreTableConstant::TRANSLATES)
            ->where('group_name', self::GROUP)
            ->get(['id', 'name']);

        foreach ($rows as $row) {
            if (! str_starts_with($row->name, self::LEGACY_PREFIX)) {
                continue;
            }

            $canonicalName = self::GROUP.'.'.$row->name;

            if (DB::table(CoreTableConstant::TRANSLATES)->where('name', $canonicalName)->exists()) {
                DB::table(CoreTableConstant::TRANSLATES)->where('id', $row->id)->delete();

                continue;
            }

            DB::table(CoreTableConstant::TRANSLATES)
                ->where('id', $row->id)
                ->update(['name' => $canonicalName, 'updated_at' => now()]);
        }
    }

    /**
     * Restore the legacy `editor.*` names, skipping any row whose legacy name is already taken.
     */
    public function down(): void
    {
        if (! Schema::hasTable(CoreTableConstant::TRANSLATES)) {
            return;
        }

        $rows = DB::table(CoreTableConstant::TRANSLATES)
            ->where('group_name', self::GROUP)
            ->get(['id', 'name']);

        foreach ($rows as $row) {
            if (! str_starts_with($row->name, self::CANONICAL_PREFIX)) {
                continue;
            }

            $legacyName = substr($row->name, strlen(self::GROUP.'.'));

            if (DB::table(CoreTableConstant::TRANSLATES)->where('name', $legacyName)->exists()) {
                continue;
            }

            DB::table(CoreTableConstant::TRANSLATES)
                ->where('id', $row->id)
                ->update(['name' => $legacyName, 'updated_at' => now()]);
        }
    }
};
