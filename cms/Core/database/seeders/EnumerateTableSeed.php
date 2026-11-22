<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Cms\Core\Models\Enumerate;
use Cms\Core\Models\EnumerateType;
use Illuminate\Database\Seeder;

class EnumerateTableSeed extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        //  Every row here points at an enumerates_types parent through a
        //  foreign key, and the seeder discovery in cms:modules-sync orders
        //  by class name — which puts EnumerateTableSeed before
        //  EnumerateTypeTableSeed. Seeding the parents from here fixes the
        //  order and takes the type seeder off the top level, because the
        //  discovery drops seeders that another seeder already calls.
        $this->call(EnumerateTypeTableSeed::class);

        $records = $this->records();
        $knownTypeIds = EnumerateType::query()->pluck('id')->all();
        $knownTypeIds = array_fill_keys(array_map('intval', $knownTypeIds), true);
        $orphans = [];

        foreach ($records as $record) {
            //  A row whose parent type is not on this install would abort the
            //  seeder with a foreign key violation and take every later row
            //  with it. Report it instead: an export can name a type that was
            //  never exported alongside it.
            $typeId = $record['type_id'] ?? null;
            if ($typeId !== null && !isset($knownTypeIds[(int) $typeId])) {
                $orphans[] = $record['enum_key'];

                continue;
            }

            /** @var array $data */
            $data = collect($record)
                ->except(['id', 'created_at', 'updated_at', 'deleted_at'])
                ->toArray();

            Enumerate::query()->upsert(
                [array_merge($data, [
                    'created_at' => $record['created_at'] ?? now(),
                    'updated_at' => $record['updated_at'] ?? now(),
                    'deleted_at' => $record['deleted_at'],
                ])],
                ['enum_key'],
                array_keys($data),
            );
        }

        if ($orphans !== []) {
            $this->command?->warn(
                'Enumerate rows skipped, no such enumerate type: ' . implode(', ', $orphans)
            );
        }

        $this->command?->info('Enumerate rows synced!');
    }

    /**
     * The exported rows to sync.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function records(): array
    {
        return require __DIR__ . '/exported/enumerates.php';
    }
}
