<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use App\Constants\CacheConstants;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Models\Setting;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Core\Services\Environment\EnvironmentSettingService;
use Cms\Core\Services\Settings\EnvBoundSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Throwable;

/**
 * Change one `.env` variable the safe way (the only way for build and boot keys such as the theme folder).
 *
 * Usage (on the server, as the deploy user, in the site's `current` release):
 *   php artisan cms:env-set VITE_TEMPLATE_FOLDER Arc
 *   php artisan cms:env-set WORKFLOW_SCHEDULER_ENABLED true
 *   php artisan cms:env-set MAILGUN_SECRET            (asks for the value without showing it)
 *   php artisan cms:env-set SOME_KEY --clear
 *
 * Steps: copy `.env` to `storage/app/env-backups/` (0600); write the line in place (same file, so its owner, mode and
 * symlinks stay), with the theme's three derived Tailwind lines for VITE_TEMPLATE_FOLDER; keep an env-bound settings
 * row of the same name equal to it; rebuild the config cache when the site has one and print which config keys
 * changed (names only); drop the settings caches and restart the queue workers. Never `config:clear` or `cache:clear`
 * (a shared Redis cache database would be emptied for every site on it). The value is never printed.
 *
 * A theme change still needs a deploy: the build reads VITE_TEMPLATE_FOLDER to make that theme's CSS and JS.
 *
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   2026.11 (2026-11-27)
 */
class EnvSetCommand extends Command
{
    protected $signature = 'cms:env-set
        {name : The .env variable, e.g. VITE_TEMPLATE_FOLDER}
        {value? : The new value; left out, it is asked for without being shown}
        {--clear : Write an empty value}';

    protected $description = 'Change one .env variable with a backup, the matching settings row and a config rebuild.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) {
            $this->error('The name must be an .env variable in capitals, e.g. VITE_TEMPLATE_FOLDER.');

            return self::FAILURE;
        }

        $value = $this->option('clear') ? '' : $this->argument('value');
        if ($value === null) {
            $value = (string) ($this->secret('Value for ' . $name) ?? '');
        }
        $value = (string) $value;

        if (!$this->option('clear')) {
            try {
                EnvironmentSettingService::assertValidValue($value);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $path = app()->environmentFilePath();
        if (!is_file($path) || !is_writable($path)) {
            $this->error('The .env file is missing or not writable by this user.');

            return self::FAILURE;
        }

        $backup = $this->backup($path, $name);
        $this->line('Backup: ' . $backup);

        $cached = app()->configurationIsCached();
        $before = $cached ? $this->hashes(config()->all()) : [];

        $content = (string) file_get_contents($path);
        foreach (EnvBoundSettings::envLines($name, $value) as $key => $line) {
            $content = prepare_env($content, $key, (object) ['value' => $line]);
        }
        file_put_contents($path, $content);
        $this->info('Written: ' . implode(', ', array_keys(EnvBoundSettings::envLines($name, $value))));

        $rows = $this->syncSettingRows($name, $value);
        if ($rows !== []) {
            $this->line('Settings rows kept equal: ' . implode(', ', $rows));
        }

        if ($cached) {
            Artisan::call('config:cache');
            $after = $this->hashes((array) require app()->getCachedConfigPath());
            $changed = array_keys(array_diff_assoc($after, $before) + array_diff_key($before, $after));
            sort($changed);
            $this->line('Config keys changed: ' . ($changed === [] ? 'none' : implode(', ', $changed)));
        } else {
            $this->line('The config is not cached here: new processes read the new value.');
        }

        $this->dropSettingCaches();
        Artisan::call('queue:restart');

        if (str_starts_with($name, 'VITE_TEMPLATE')) {
            $this->warn('Deploy the site now: the build makes the CSS and JS of the theme in ' . $name . '.');
        }

        return self::SUCCESS;
    }

    /**
     * Copy `.env` next to the other backups, readable by its owner only.
     */
    private function backup(string $path, string $name): string
    {
        $dir = storage_path('app/env-backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $target = $dir . '/env-' . date('Ymd-His') . '-' . $name;
        copy($path, $target);
        chmod($target, 0600);

        return $target;
    }

    /**
     * Keep env-bound settings rows named after the variable equal to the new value (saved through Eloquent so
     * secrets stay encrypted).
     *
     * @return list<string> Setting keys changed.
     */
    private function syncSettingRows(string $name, string $value): array
    {
        try {
            $settings = Setting::query()->where('name', $name)->whereNull('language_id')->get();
        } catch (Throwable) {
            return [];
        }

        $changed = [];
        foreach ($settings as $setting) {
            if (!EnvBoundSettings::isEnvBound((string) $setting->setting_key, $setting->input_setting)) {
                continue;
            }
            $setting->value = $value === '' ? null : $value;
            $setting->save();
            $changed[] = (string) $setting->setting_key;
        }

        return $changed;
    }

    private function dropSettingCaches(): void
    {
        try {
            app(CMSSettingRuntime::class)->forget();
            RedisHelper::getInstance()->del(CacheConstants::CACHE_SETTINGS);
        } catch (Throwable) {
            // The runtime cache expires on its own; the next settings save drops it too.
        }
    }

    /**
     * Hash of every flattened config value (compared by key; values never printed).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, string>
     */
    private function hashes(array $config): array
    {
        $out = [];
        foreach (Arr::dot($config) as $key => $value) {
            $out[(string) $key] = md5((string) json_encode($value));
        }

        return $out;
    }
}
