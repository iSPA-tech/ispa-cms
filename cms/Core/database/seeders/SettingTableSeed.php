<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Cms\Core\Constants\CoreTableConstant;

class SettingTableSeed extends Seeder
{
    public function run(): void
    {
        $records = require __DIR__ . '/exported/settings.php';

        foreach ($records as $record) {
            $settingKey = trim((string) ($record['setting_key'] ?? ''));
            if ($settingKey === '') {
                continue;
            }

            $existingId = DB::table(CoreTableConstant::SETTINGS)
                ->where('setting_key', $settingKey)
                ->value('id');

            if ($existingId !== null) {
                DB::table(CoreTableConstant::SETTINGS)
                    ->where('id', $existingId)
                    ->update([
                        'type' => $record['type'],
                        'enumerate_id' => $record['enumerate_id'],
                        'language_id' => $record['language_id'],
                        'input_setting' => $record['input_setting'],
                        'updated_at' => $record['updated_at'] ?? now(),
                        'deleted_at' => $record['deleted_at'] ?? null,
                    ]);

                continue;
            }

            DB::table(CoreTableConstant::SETTINGS)->insert([
                'setting_key' => $settingKey,
                'type' => $record['type'],
                'group_name' => $record['group_name'],
                'name' => $record['name'],
                'value' => $record['value'], // Private values are redacted by the settings export contract.
                'enumerate_id' => $record['enumerate_id'],
                'language_id' => $record['language_id'],
                'input_setting' => $record['input_setting'],
                'created_at' => $record['created_at'] ?? now(),
                'updated_at' => $record['updated_at'] ?? now(),
                'deleted_at' => $record['deleted_at'] ?? null,
            ]);
        }

        $this->syncSequence();

        $this->command?->info('Settings synced!');
    }

    private function syncSequence(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $sequence = DB::selectOne(
            'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
            [CoreTableConstant::SETTINGS, 'id']
        );

        $sequenceName = $sequence->sequence_name ?? null;
        if (! is_string($sequenceName) || $sequenceName === '') {
            return;
        }

        $maxId = DB::table(CoreTableConstant::SETTINGS)->max('id');
        if (! is_numeric($maxId)) {
            return;
        }

        DB::statement('SELECT setval(?::regclass, ?, true)', [$sequenceName, (int) $maxId]);
    }
}
