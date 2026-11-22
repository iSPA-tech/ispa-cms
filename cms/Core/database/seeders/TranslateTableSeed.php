<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Cms\Core\Services\TranslationModuleSeederImporter;
use Illuminate\Support\Facades\File;

class TranslateTableSeed extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $path = __DIR__ . '/exported/translates.php';
        if (!file_exists($path)) {
            $this->command?->warn('Translate export file not found, skipping');
            return;
        }

        $rows = require $path;
        if (!is_array($rows)) {
            $this->command?->warn('Translate export file is not an array, skipping');
            return;
        }

        $sidecarPaths = File::glob(__DIR__ . '/exported/translates-admin-*.php');
        sort($sidecarPaths);

        foreach ($sidecarPaths as $sidecarPath) {
            if (!file_exists($sidecarPath)) {
                continue;
            }

            $sidecarRows = require $sidecarPath;
            if (is_array($sidecarRows)) {
                $rows = array_merge($rows, $sidecarRows);
            } else {
                $this->command?->warn(
                    'Admin translation sidecar is not an array, skipping: ' . basename($sidecarPath)
                );
            }
        }

        $result = app(TranslationModuleSeederImporter::class)->importRecords('Core', $rows, $path);
        $this->command?->info(
            'Translate added: ' . $result['created']
            . ', linked: ' . $result['linked']
            . ', skipped: ' . $result['skipped']
        );
    }
}
