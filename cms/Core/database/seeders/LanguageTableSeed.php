<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Cms\Core\Models\Language;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguageTableSeed extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    /**
     * Definition fields refreshed on an existing language row. Everything NOT
     * listed here (used-for: for_web/for_adm/for_app, default_for:
     * default_web/default_adm/default_app, status, is_locked, deleted_at) is
     * operator-managed and must be preserved, so a re-run (e.g. CICD db:seed)
     * never re-enables, re-defaults, or restores a language. New rows still get
     * every field.
     *
     * @var list<string>
     */
    private const DEFINITION_FIELDS = [
        'name', 'currency', 'path', 'path_alias', 'code', 'flag', 'time_zone',
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        foreach ($this->languages() as $language) {
            $model = Language::withTrashed()->firstOrNew(['id' => $language['id']]);

            if ($model->exists) {
                // Update: only definition fields; leave used-for/default-for/status/lock alone.
                foreach (self::DEFINITION_FIELDS as $field) {
                    if (array_key_exists($field, $language)) {
                        $model->{$field} = $language[$field];
                    }
                }
            } else {
                // Create: full seed defaults (including used-for/default-for/status).
                $model->fill($language);
            }

            $model->save();
        }

        $this->syncSequence();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function languages(): array
    {
        return [[
            'id' => 1,
            'name' => 'Tiếng Việt',
            'currency' => 'đ',
            'path' => 'vi',
            'path_alias' => 'vi-VN',
            'code' => 'VN',
            'flag' => 'vn.svg',
            'time_zone' => 'Asia/Ho_Chi_Minh',
            'default_web' => true,
            'default_adm' => true,
            'default_app' => false,
            'for_web' => true,
            'for_adm' => true,
            'for_app' => false,
            'status' => 'published',
            'is_locked' => false,
        ], [
            'id' => 2,
            'name' => 'English',
            'currency' => '$',
            'path' => 'en',
            'path_alias' => 'en-US',
            'code' => 'EN',
            'flag' => 'en.svg',
            'time_zone' => 'UTC',
            'default_web' => false,
            'default_adm' => false,
            'default_app' => false,
            'for_web' => true,
            'for_adm' => true,
            'for_app' => false,
            'status' => 'published',
            'is_locked' => false,
        ]];
    }

    private function syncSequence(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $sequence = DB::selectOne(
            'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
            [(new Language())->getTable(), 'id']
        );

        $sequenceName = $sequence->sequence_name ?? null;
        if (! is_string($sequenceName) || $sequenceName === '') {
            return;
        }

        $maxId = Language::query()->max('id');
        if (! is_numeric($maxId)) {
            return;
        }

        DB::statement('SELECT setval(?::regclass, ?, true)', [$sequenceName, (int) $maxId]);
    }
}
