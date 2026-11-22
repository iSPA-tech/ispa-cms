<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the remaining Widget site-layout translation rows under their own group prefix.
 *
 * Rows such as `manifest.*`, `gallery.*` or `slot.*` were stored without the
 * `widget_site_layout.` prefix, so the locale generator sharded them by their first
 * segment while the components call `$t('widget_site_layout.<family>.*')`, which showed
 * raw labels. The translation importer never renames rows, so the rename happens here,
 * before `cms:translation-sync` runs on deploy.
 */
return new class extends Migration
{
    private const GROUP = 'widget_site_layout';

    /**
     * First name segments of the families that move under the group prefix.
     *
     * @var list<string>
     */
    private const FAMILIES = [
        'manifest',
        'gallery',
        'server_error',
        'action',
        'error',
        'slot',
        'region',
        'cardinality',
        'scope',
        'template_context',
    ];

    /**
     * Rename each legacy row in place; drop it when the canonical row already exists.
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
            if (str_starts_with($row->name, self::GROUP.'.')) {
                continue;
            }

            if (! in_array(explode('.', $row->name)[0], self::FAMILIES, true)) {
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
     * Restore the legacy names of the renamed families, skipping any row whose legacy name is taken.
     *
     * The `editor.*` rows are owned by the earlier migration and are never touched here.
     */
    public function down(): void
    {
        if (! Schema::hasTable(CoreTableConstant::TRANSLATES)) {
            return;
        }

        $prefix = self::GROUP.'.';

        $rows = DB::table(CoreTableConstant::TRANSLATES)
            ->where('group_name', self::GROUP)
            ->get(['id', 'name']);

        foreach ($rows as $row) {
            if (! str_starts_with($row->name, $prefix)) {
                continue;
            }

            $legacyName = substr($row->name, strlen($prefix));

            if (! in_array(explode('.', $legacyName)[0], self::FAMILIES, true)) {
                continue;
            }

            if (DB::table(CoreTableConstant::TRANSLATES)->where('name', $legacyName)->exists()) {
                continue;
            }

            DB::table(CoreTableConstant::TRANSLATES)
                ->where('id', $row->id)
                ->update(['name' => $legacyName, 'updated_at' => now()]);
        }
    }
};
