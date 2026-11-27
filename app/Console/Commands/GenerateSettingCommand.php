<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Throwable;
use Cms\Core\Models\Setting;
use Illuminate\Console\Command;
use App\Constants\CacheConstants;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Core\Services\Settings\EnvBoundSettings;
use Illuminate\Support\Facades\Artisan;

class GenerateSettingCommand extends Command
{
    private const MAIL_ENV_SETTING_KEYS = EnvBoundSettings::MAIL_KEYS;

    /** @var bool */
    public bool $isDiscovery = false;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:setting-initialize {--updateEnv=false : Update .env file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate setting from database to config file';

    /**
     * Execute the console command.
     *
     */
    public function handle(): void
    {
        try {
            $settings = Setting::query()->with('language:id,path,path_alias')->get();
            $isCacheEnableValue = (string)($settings->firstWhere('name', 'CACHE_ENABLE')->value ?? 'false');

            $envSettings = collect();
            $publicSettings = [];
            $privateSettings = [];
            $counts = [
                'env' => ['skipped' => 0, 'exported' => 0],
                'public' => ['skipped' => 0, 'exported' => 0],
                'private' => ['skipped' => 0, 'exported' => 0],
            ];
            $shouldUpdateEnv = $this->option('updateEnv') === 'true';

            foreach ($settings as $setting) {
                $inputSetting = (array)$setting->input_setting;
                // $this->info('Processing setting: ' . 'setting_key: ' . $setting->setting_key . ' - input_setting: ' . json_encode($inputSetting));
                $isEnv = $this->shouldSynchronizeToEnvironment($setting->setting_key, $inputSetting);
                $isPrivate = ($inputSetting['isPublic'] ?? true) === false;

                // Normalize value for downstream usage
                if ($setting->type === 'number') {
                    $setting->value = max((int)$setting->value, 0);
                }

                if ($isEnv) {
                    $envSettings->push($setting);
                    $counts['env']['exported']++;
                    $this->info('Queued .env setting: ' . $setting->setting_key);
                    continue;
                }

                if ($isPrivate) {
                    $privateSettings[$setting->setting_key] = $setting->value;
                    $counts['private']['skipped']++;
                    continue;
                }

                $publicSettings = $this->assignToGroupedSettings($publicSettings, $setting);
                $counts['public']['exported']++;
                $this->warn('Exported public setting: ' . $setting->setting_key);
            }

            CMSLogger::info([
                'message' => 'GenerateSettingCommand classified settings',
                'counts' => $counts,
            ]);

            if (!empty($publicSettings)) {
                $this->info('Generating public settings...');
                put_config($publicSettings);
            } else {
                $this->error('No public settings to generate.');
            }

            if ($shouldUpdateEnv && $envSettings->isNotEmpty()) {
                $this->writeEnvironmentFile($envSettings, $isCacheEnableValue);
            }

            if (!empty($privateSettings) && config('database.redis.enable', false)) {
                $this->info('Warming private settings to Redis...');
                try {
                    $redisKey = RedisHelper::makeKey(['settings', 'runtime', 'private']);
                    RedisHelper::set($redisKey, $privateSettings);
                } catch (Throwable $e) {
                    CMSLogger::error([
                        'message' => 'GenerateSettingCommand failed to warm private settings to redis',
                        'exception' => $e,
                    ]);
                }
            }

            if (config('database.redis.enable', false)) {
                RedisHelper::websiteSetting();
            }

            $this->refreshSettingCaches();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * Write the env-bound rows to `.env`, on legacy sites only.
     *
     * With the settings runtime on, the database row is the only source of env-bound application settings and `.env`
     * is never written here (build and boot keys change through cms:env-set; EnvBoundSettings). Legacy sites (runtime
     * off) still read `.env`, so the save keeps writing it there.
     *
     * @param \Illuminate\Support\Collection<int, Setting> $envSettings
     *
     * @return bool Whether `.env` was written.
     */
    protected function writeEnvironmentFile(\Illuminate\Support\Collection $envSettings, string $isCacheEnableValue): bool
    {
        if (EnvBoundSettings::databaseIsSource()) {
            $this->info('Settings are read from the database; .env is not written (theme and boot keys: cms:env-set).');

            return false;
        }

        $this->info('Updating .env file...');
        env_update($envSettings, $isCacheEnableValue);

        return true;
    }

    /**
     * Make the new settings visible without emptying the application cache.
     *
     * The command used to end with `config:clear` and `cache:clear`. `cache:clear` flushes the whole cache store, and on a
     * Redis database shared by several sites (server .215) every admin settings save emptied the cache of all of them
     * (2026-11-27). Only what the settings feed is refreshed: the CMSSettingRuntime scopes and the public settings payload.
     * A cached configuration is rebuilt so it carries the new `config/setting.php` and `.env`; an uncached one stays
     * uncached. Other cached entries keep their own TTL.
     */
    protected function refreshSettingCaches(): void
    {
        app(CMSSettingRuntime::class)->forget();

        try {
            RedisHelper::getInstance()->del(CacheConstants::CACHE_SETTINGS);
        } catch (Throwable $e) {
            CMSLogger::warning([
                'message' => 'GenerateSettingCommand could not drop the public settings payload',
                'exception' => $e,
            ]);
        }

        if (app()->configurationIsCached()) {
            $this->info('Rebuilding the config cache...');
            try {
                Artisan::call('config:cache');
            } catch (Throwable $e) {
                CMSLogger::error([
                    'message' => 'GenerateSettingCommand could not rebuild the config cache',
                    'exception' => $e,
                ]);
            }
        }
    }

    private function shouldSynchronizeToEnvironment(string $settingKey, array $inputSetting): bool
    {
        return EnvBoundSettings::isEnvBound($settingKey, $inputSetting);
    }

    protected function assignToGroupedSettings(array $groupedSettings, Setting $setting): array
    {
        $exk = explode('.', $setting->setting_key);

        if (isset($exk[2])) {
            if ($setting->language) {
                $groupedSettings[$exk[0]][$exk[1]][$setting->language->path_alias ?? $setting->language->path] = $setting->value;
            } else {
                $groupedSettings[$exk[0]][$exk[1]][$exk[2]] = $setting->value;
            }
        } else if (isset($exk[1])) {
            if ($setting->language) {
                $groupedSettings[$exk[0]][$exk[1]][$setting->language->path_alias ?? $setting->language->path] = $setting->value;
            } else {
                $groupedSettings[$exk[0]][$exk[1]] = $setting->value;
            }
        } else if ($setting->language) {
            $groupedSettings[$exk[0]][$setting->language->path_alias ?? $setting->language->path] = $setting->value;
        } else {
            $groupedSettings[$exk[0]] = $setting->value;
        }

        return $groupedSettings;
    }
}
