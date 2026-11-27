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
use Cms\Core\Services\Settings\EnvBoundSettings;

/**
 * Runtime settings resolver with three-tier scoping (env, private, public).
 *
 * Loads CMS settings from the database, caches them in Redis and
 * per-request static memory. Settings are classified as environment
 * variables, private, or public based on their input_setting metadata.
 * Env-scoped settings resolve from the database row first and the `.env` value when the row is empty (build and
 * boot keys keep `.env` first, see EnvBoundSettings; since 2026-11-27, before that `.env` always came first).
 *
 * @see Setting
 * @package Cms\Core\Services
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CMSSettingRuntime
{
    /**
     * Per-request in-memory cache partitioned by scope.
     *
     * @var array{env: ?array, private: ?array, public: ?array}
     */
    protected static array $localCache = [
        'env' => null,
        'private' => null,
        'public' => null,
    ];

    /**
     * Get a setting value by its setting_key. Alias for get().
     *
     * @param string $settingKey The dot-notated setting key.
     * @param mixed  $default    Default value if not found.
     * @return mixed
     */
    public function getBySettingKey(string $settingKey, mixed $default = null): mixed
    {
        return $this->get($settingKey, $default);
    }

    /**
     * Retrieve a setting value by dot-notated path, searching env then private then public.
     *
     * @param string $path    The dot-notated setting path.
     * @param mixed  $default Default value if not found.
     * @return mixed
     */
    public function get(string $path, mixed $default = null): mixed
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $this->warmEnv();
        $value = $this->readScopeValue(self::$localCache['env'], $path);
        if ($value !== null) {
            return $value;
        }

        $this->warmPrivate();
        $value = $this->readScopeValue(self::$localCache['private'], $path);
        if ($value !== null) {
            return $value;
        }

        $this->warmPublic();
        $value = $this->readScopeValue(self::$localCache['public'], $path);

        return $value !== null ? $value : $default;
    }

    /**
     * Read one value out of a warmed scope map.
     *
     * The maps are FLAT and keyed by the dotted setting_key
     * ('advanced.recaptcha_provider'), so the exact key must be tried first:
     * data_get() splits on dots and walks a nested structure, so it never
     * matches these keys and silently returned the default for every dotted
     * setting — which is why the captcha gateway kept reading 'off' no
     * matter what the admin had configured. data_get stays as the fallback
     * for callers that pass a genuinely nested path.
     *
     * @param array<string, mixed>|null $map
     */
    private function readScopeValue(?array $map, string $path): mixed
    {
        if (is_array($map) && array_key_exists($path, $map)) {
            return $map[$path];
        }

        return data_get($map, $path);
    }

    protected function isEnabled(): bool
    {
        return (bool) config('ispa-cms.setting_runtime_enabled', true);
    }

    /**
     * Warm the environment-scoped settings cache.
     *
     * @return array<string, mixed> The cached env settings.
     */
    public function warmEnv(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        return $this->warmScope('env');
    }

    protected function warmScope(string $scope): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        if (self::$localCache[$scope] !== null) {
            return self::$localCache[$scope];
        }

        $redisKey = RedisHelper::makeKey(['settings', 'runtime', $scope]);
        $redisData = RedisHelper::get($redisKey);
        if (is_array($redisData)) {
            self::$localCache[$scope] = $redisData;
            return $redisData;
        }

        $settings = Setting::query()->get(['setting_key', 'name', 'value', 'input_setting']);
        $result = [];

        foreach ($settings as $setting) {
            [$isEnv, $isPublic] = $this->classifySetting($setting);

            if ($scope === 'env' && !$isEnv) {
                continue;
            }
            if ($scope === 'public' && ($isEnv || !$isPublic)) {
                continue;
            }
            if ($scope === 'private' && ($isEnv || $isPublic)) {
                continue;
            }

            $resolved = $isEnv
                ? $this->resolveEnvBound($setting)
                : $setting->value;

            $result[$setting->setting_key] = $resolved;
        }

        if (!empty($result)) {
            try {
                //  Bounded TTL backstop: admin saves call forget(), but any
                //  writer that misses it must not leave the scope stale forever.
                RedisHelper::set($redisKey, $result, 86400);
            } catch (Throwable $e) {
                CMSLogger::error([
                    'message' => 'CMSSettingRuntime redis write failed',
                    'scope' => $scope,
                    'exception' => $e,
                ]);
            }
        }

        self::$localCache[$scope] = $result;

        return $result;
    }

    /**
     * Classify a setting as environment and/or public.
     *
     * @param Setting $setting The setting model.
     * @return array{0: bool, 1: bool} [isEnv, isPublic].
     */
    public function classifySetting(Setting $setting): array
    {
        return $this->classifyInputSetting($setting->input_setting);
    }

    /**
     * Classify an input_setting value as environment and/or public.
     *
     * @param mixed $inputSetting The input_setting JSON or array.
     * @return array{0: bool, 1: bool} [isEnv, isPublic].
     */
    public function classifyInputSetting(mixed $inputSetting): array
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
     * Value of an env-bound row: the database row first for application settings (the admin save no longer writes
     * `.env`, so the row is the source), the `.env` value only when the row is empty; build and boot keys keep `.env`
     * first ({@see EnvBoundSettings}).
     */
    private function resolveEnvBound(Setting $setting): mixed
    {
        if (EnvBoundSettings::databaseFirst((string) $setting->setting_key)) {
            $value = $setting->value;

            return $value !== null && $value !== '' ? $value : env_file_value((string) $setting->name);
        }

        return env_file_value((string) $setting->name, $setting->value);
    }

    /**
     * Warm the private-scoped settings cache.
     *
     * @return array<string, mixed> The cached private settings.
     */
    public function warmPrivate(): array
    {
        return $this->warmScope('private');
    }

    /**
     * Warm the public-scoped settings cache.
     *
     * @return array<string, mixed> The cached public settings.
     */
    public function warmPublic(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        return $this->warmScope('public');
    }

    public function forget(): void
    {
        self::$localCache = [
            'env' => null,
            'private' => null,
            'public' => null,
        ];

        foreach (['env', 'private', 'public'] as $scope) {
            try {
                RedisHelper::del(RedisHelper::makeKey(['settings', 'runtime', $scope]));
            } catch (Throwable $e) {
                CMSLogger::error([
                    'message' => 'CMSSettingRuntime redis delete failed',
                    'scope' => $scope,
                    'exception' => $e,
                ]);
            }
        }
    }
}
