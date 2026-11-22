<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use App\Enums\StatusEnum;
use Cms\Core\Models\Field;
use Cms\Core\Models\FieldItem;
use Illuminate\Database\Seeder;

class FormAndFieldSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fields = require __DIR__ . '/exported/fields.php';
        foreach ($fields as $record) {
            $data = collect($record)->except(['id', 'created_at', 'updated_at', 'deleted_at'])->toArray();
            if (($data['status'] ?? null) === 'published') {
                $data['status'] = StatusEnum::PUBLISHED->value;
            }
            Field::query()->updateOrCreate(
                ['id' => $record['id']],
                array_merge($data, [
                    'created_at' => $record['created_at'] ?? now(),
                    'updated_at' => $record['updated_at'] ?? now(),
                    'deleted_at' => $record['deleted_at'],
                ]),
            );
        }
        $this->command?->info('Fields synced!');

        $fieldItems = require __DIR__ . '/exported/field_items.php';
        foreach ($fieldItems as $record) {
            $data = collect($record)->except(['id', 'created_at', 'updated_at', 'deleted_at'])->toArray();
            FieldItem::query()->updateOrCreate(
                ['id' => $record['id']],
                array_merge($data, [
                    'created_at' => $record['created_at'] ?? now(),
                    'updated_at' => $record['updated_at'] ?? now(),
                    'deleted_at' => $record['deleted_at'],
                ]),
            );
        }
        $this->command?->info('Field items synced!');
    }
}
