<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

use Throwable;
use Illuminate\Support\Arr;
use Cms\Core\Models\Setting;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Helpers\RedisHelper;
use Illuminate\Support\Facades\Cache;

/**
 * Unified runtime resolver for CMS settings.
 *
 * Resolution order intentionally follows the runtime separation strategy:
 * 1) Env settings resolve through Setting.name -> env()
 * 2) Private settings resolve from Redis runtime private map
 * 3) Public settings resolve from config('setting.*') snapshot
 *
 * DB value fallback is disabled by default and only available via options for troubleshooting.
 * @package Cms\Core\Services
 */
class CMSSettingUnified
{
    protected const string META_CACHE_PREFIX = 'settings:meta:';
    protected const int META_CACHE_TTL_SECONDS = 900;

    /**
     * Per-request cache for metadata only. Values are either metadata arrays or null when missing.
     *
     * @var array<string, array<string, mixed>|null>
     */
    protected static array $metaLocalCache = [];

    /**
     * Resolve a setting value by setting_key using env/private/public runtime layers.
     *
     * @param string $settingKey Dot-separated key from settings.setting_key.
     * @param mixed $default Returned when no runtime value is resolved.
     * @param array $options Supported: ['allow_db_fallback' => bool] (default false).
     *
     * @return mixed
     */
    public function get(string $settingKey, mixed $default = null, array $options = []): mixed
    {
        $meta = $this->getSettingMeta($settingKey);
        if ($meta === null) {
            return $default;
        }

        [$isEnv, $isPublic] = $this->classifyInputSetting($meta['input_setting'] ?? []);

        // Env settings are sourced by env variable name from Setting.name, not setting_key.
        if ($isEnv) {
            $value = $this->resolveEnvValue($meta['name'] ?? null);

            return $value !== null ? $value : $this->resolveOptionalDbFallback($settingKey, $default, $options);
        }

        // Private settings must come from runtime private Redis map.
        if ($isPublic === false) {
            $value = $this->resolvePrivateValue($settingKey);

            return $value !== null ? $value : $this->resolveOptionalDbFallback($settingKey, $default, $options);
        }

        // Public settings are read from generated config snapshot.
        $value = $this->resolvePublicValue($settingKey);

        return $value !== null ? $value : $this->resolveOptionalDbFallback($settingKey, $default, $options);
    }

    /**
     * Fetch and cache non-sensitive setting metadata needed for routing.
     *
     * @param string $settingKey
     *
     * @return array<string, mixed>|null
     */
    protected function getSettingMeta(string $settingKey): ?array
    {
        if (array_key_exists($settingKey, self::$metaLocalCache)) {
            return self::$metaLocalCache[$settingKey];
        }

        $cacheKey = self::META_CACHE_PREFIX . $settingKey;

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                self::$metaLocalCache[$settingKey] = ($cached['__missing'] ?? false) ? null : $cached;

                return self::$metaLocalCache[$settingKey];
            }
        } catch (Throwable $e) {
            CMSLogger::warning([
                'message' => 'CMSSettingUnified metadata cache read failed',
                'setting_key' => $settingKey,
                'exception' => $e,
            ]);
        }

        $setting = Setting::query()
            ->where('setting_key', $settingKey)
            ->first(['setting_key', 'name', 'type', 'input_setting']);

        $meta = $setting ? [
            'setting_key' => $setting->setting_key,
            'name' => $setting->name,
            'type' => $setting->type,
            'input_setting' => $setting->input_setting,
        ] : null;

        self::$metaLocalCache[$settingKey] = $meta;

        try {
            Cache::put(
                $cacheKey,
                $meta ?? ['__missing' => true],
                now()->addSeconds(self::META_CACHE_TTL_SECONDS)
            );
        } catch (Throwable $e) {
            CMSLogger::warning([
                'message' => 'CMSSettingUnified metadata cache write failed',
                'setting_key' => $settingKey,
                'exception' => $e,
            ]);
        }

        return $meta;
    }

    /**
     * Parse input_setting flags into [isEnv, isPublic].
     *
     * @param mixed $inputSetting
     *
     * @return array{0: bool, 1: bool}
     */
    protected function classifyInputSetting(mixed $inputSetting): array
    {
        $data = $inputSetting;

        if (is_string($inputSetting)) {
            $decoded = json_decode($inputSetting, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $isEnv = (bool)Arr::get($data, 'isEnv', Arr::get($data, 'is_env', false));
        $isPublic = (bool)Arr::get($data, 'isPublic', Arr::get($data, 'is_public', false));

        return [$isEnv, $isPublic];
    }

    /**
     * Resolve env-backed settings using Setting.name as env variable key.
     *
     * @param string|null $envName
     *
     * @return mixed
     */
    protected function resolveEnvValue(?string $envName): mixed
    {
        if ($envName === null || $envName === '') {
            return null;
        }

        $value = env_file_value($envName);
        if ($value !== null) {
            return $value;
        }

        return config($envName);
    }

    /**
     * Resolve private runtime settings from Redis map keyed by setting_key.
     *
     * @param string $settingKey
     *
     * @return mixed
     */
    protected function resolvePrivateValue(string $settingKey): mixed
    {
        $redisKey = RedisHelper::makeKey(['settings', 'runtime', 'private']);
        $privateMap = RedisHelper::get($redisKey);

        if (!is_array($privateMap)) {
            return null;
        }

        if (array_key_exists($settingKey, $privateMap)) {
            return $privateMap[$settingKey];
        }

        return data_get($privateMap, $settingKey);
    }

    /**
     * Resolve public runtime settings from generated config snapshot.
     *
     * @param string $settingKey
     *
     * @return mixed
     */
    protected function resolvePublicValue(string $settingKey): mixed
    {
        return config('setting.' . $settingKey);
    }

    /**
     * Optionally return DB value for troubleshooting when enabled by caller.
     *
     * @param string $settingKey
     * @param mixed $default
     * @param array $options
     *
     * @return mixed
     */
    protected function resolveOptionalDbFallback(string $settingKey, mixed $default, array $options): mixed
    {
        if ((bool)Arr::get($options, 'allow_db_fallback', false) !== true) {
            return $default;
        }

        $setting = Setting::query()
            ->where('setting_key', $settingKey)
            ->first(['id', 'setting_key', 'type', 'group_name', 'name', 'value', 'input_setting']);

        if ($setting === null || $setting->value === null) {
            return $default;
        }

        return $setting->value;
    }
}
