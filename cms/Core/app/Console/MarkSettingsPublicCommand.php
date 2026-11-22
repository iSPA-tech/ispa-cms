<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Cms\Core\Models\Setting;
use Illuminate\Console\Command;

/**
 * Marks settings as publicly accessible by adding isPublic=true to their input_setting metadata.
 *
 * Iterates over settings whose setting_key starts with one of the given prefix strings (or the
 * built-in default prefixes), and adds {"isPublic": true} to the input_setting JSON column
 * without touching the setting value. Settings that already have the isPublic key are skipped.
 * Processes records in chunks of 200 to avoid memory issues on large datasets.
 *
 * Usage: php artisan cms:settings-mark-public [settingKeys...]
 * Arguments:
 *   settingKeys  Optional list of setting_key prefixes (dot-separated). Falls back to defaults if empty.
 *
 * @package Cms\Core\Console
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MarkSettingsPublicCommand extends Command
{
    protected $signature = 'cms:settings-mark-public
        {settingKeys?* : Optional list of setting_key prefixes to mark as public (dot-separated)}';

    protected $description = 'Mark settings as public in input_setting metadata without altering values';

    /**
     * Verified safe defaults; extend as needed.
     *
     * @var string[]
     */
    protected array $defaultPrefixes = [
        'setting.basic',
        'setting.contact',
        'setting.social',
        'setting.image',
        'setting.logo',
        'setting.html',
        'setting.ads',
    ];

    /**
     * Execute the console command.
     *
     * Resolves the prefix list from arguments or defaults, chunks over matching settings,
     * and sets isPublic=true on input_setting for any setting that does not already have it.
     *
     * @return int Command::SUCCESS
     */
    public function handle(): int
    {
        $prefixes = $this->argument('settingKeys');
        if (empty($prefixes)) {
            $prefixes = $this->defaultPrefixes;
            $this->info('Using default verified prefixes: ' . implode(', ', $prefixes));
        }

        $updated = 0;
        $skipped = 0;

        foreach ($prefixes as $prefix) {
            $query = Setting::query()->where('setting_key', 'like', $prefix . '%');

            $query->chunkById(200, function ($settings) use (&$updated, &$skipped) {
                foreach ($settings as $setting) {
                    $input = $setting->input_setting;

                    $isPublicPresent = is_array($input) && array_key_exists('isPublic', $input);
                    if ($isPublicPresent) {
                        $skipped++;
                        continue;
                    }

                    $input = is_array($input) ? $input : [];
                    $input['isPublic'] = true;

                    $setting->input_setting = $input;
                    $setting->save();
                    $updated++;
                }
            });
        }

        $this->info(sprintf('Updated %d settings; skipped %d already-marked settings.', $updated, $skipped));

        return self::SUCCESS;
    }
}
