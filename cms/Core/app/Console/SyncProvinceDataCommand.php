<?php

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use JsonException;
use App\Constants\Tables;
use App\Enums\StatusEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Syncs province data from the exported seeder file into the database.
 *
 * Reads the provinces PHP export from cms/Core/database/seeders/exported/provinces.php,
 * inserts any province that does not yet exist (matched by slug), and skips already-present
 * records. Foreign key constraints are disabled for the duration of the sync. Clears the
 * application cache after completing the import.
 *
 * Usage: php artisan cms:sync-province-data
 *
 * @package Cms\Core\Console
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SyncProvinceDataCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;

    protected $signature = 'cms:sync-province-data';

    protected $description = 'Sync province data from seeder file to the database';

    /**
     * Execute the console command.
     *
     * @return void
     * @throws JsonException
     */
    public function handle(): void
    {
        $path = base_path('cms/Core/database/seeders/exported/provinces.php');
        if (!File::exists($path)) {
            $this->error('Province export file not found at cms/Core/database/seeders/exported/provinces.php.');
            return;
        }

        /** @var array<int, array> $provinces */
        $provinces = include $path;

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        $this->output->progressStart(count($provinces));

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($provinces as $province) {
                $existing = DB::table(Tables::PROVINCES)
                    ->where('slug', $province['slug'])
                    ->first(['status']);

                if ($existing === null) {
                    DB::table(Tables::PROVINCES)->insert([
                        'name' => $province['name'],
                        'global_name' => $province['global_name'],
                        'uuid' => $province['uuid'],
                        'uid' => $province['uid'],
                        'slug' => $province['slug'],
                        'type' => 0,
                        'parent_id' => null,
                        'language_id' => null,
                        'maps_id' => null,
                        'latitude' => $province['latitude'],
                        'longitude' => $province['longitude'],
                        'priority' => 0,
                        'config' => json_encode($province['config'], JSON_THROW_ON_ERROR),
                        'status' => StatusEnum::PUBLISHED->value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $inserted++;
                } elseif ($existing->status === 'published') {
                    DB::table(Tables::PROVINCES)
                        ->where('slug', $province['slug'])
                        ->update([
                            'status' => StatusEnum::PUBLISHED->value,
                            'updated_at' => now(),
                        ]);
                    $updated++;
                } else {
                    $skipped++;
                }

                $this->output->progressAdvance();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        $this->output->progressFinish();

        $this->components->twoColumnDetail(
            'Provinces',
            "inserted {$inserted}, updated {$updated}, skipped {$skipped}, total " . count($provinces)
        );
    }
}
