<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use App\Constants\Tables;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Cms\Core\Services\ModuleSeederImporter;

class MetaDataTableSeed extends Seeder
{
    /**
     * Pivot tables that have no `id` column — use insertOrIgnore instead of importer.
     */
    private const PIVOT_TABLES = [
        Tables::META_ATTRIBUTE_VALUE_PIVOT,
        Tables::META_CLASS_ATTRIBUTE_PIVOT,
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $tables = [
            Tables::META_CLASSES, Tables::META_ATTRIBUTES,
            Tables::META_VALUES_BIG_NUM, Tables::META_VALUES_BOOLEAN, Tables::META_VALUES_DATE,
            Tables::META_VALUES_TINY_NUM, Tables::META_VALUES_RANGE, Tables::META_VALUES_STRING, Tables::META_VALUES_TEXT,
            Tables::META_ATTRIBUTE_VALUE_PIVOT, Tables::META_CLASS_ATTRIBUTE_PIVOT,
        ];

        $importer = app(ModuleSeederImporter::class);

        foreach ($tables as $table) {
            $path = __DIR__ . '/exported/' . $table . '.php';
            if (!file_exists($path)) {
                $this->command?->warn($table . ' export file not found, skipping');
                continue;
            }

            $rows = require $path;
            if (!is_array($rows)) {
                $this->command?->warn($table . ' export file is not an array, skipping');
                continue;
            }

            if (in_array($table, self::PIVOT_TABLES, true)) {
                $inserted = DB::table($table)->insertOrIgnore($rows);
                $this->command?->info($table . ' inserted: ' . $inserted . ', skipped: ' . (count($rows) - $inserted));
                continue;
            }

            $result = $importer->import($table, $rows, 'id');
            $this->command?->info($table . ' inserted: ' . $result['inserted'] . ', skipped: ' . $result['skipped']);
        }
    }
}
