<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Cms\Core\Models\EnumerateType;

class EnumerateTypeTableSeed extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $records = require __DIR__ . '/exported/enumerates_types.php';

        foreach ($records as $record) {
            $data = collect($record)->except(['id', 'created_at', 'updated_at'])->toArray();

            EnumerateType::query()->updateOrCreate(
                ['id' => $record['id']],
                array_merge($data, [
                    'created_at' => $record['created_at'] ?? now(),
                    'updated_at' => $record['updated_at'] ?? now(),
                ]),
            );
        }

        $this->command?->info('Enumerate types synced!');
    }
}
