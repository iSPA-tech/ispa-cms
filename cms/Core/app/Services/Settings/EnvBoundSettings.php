<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Settings;

/**
 * One rule for the settings that also exist as `.env` variables ("env-bound": `input_setting.isEnv` or a mail key).
 *
 * Two kinds:
 * - Application settings (captcha keys, API keys, mail server, Telegram logger, ...): with the settings runtime on
 *   (`ispa-cms.setting_runtime_enabled`, the default) the database row is the only source. Readers take the row first
 *   and the `.env` value only when the row is empty; the admin save writes the row and never the `.env` file.
 * - Build and boot keys (the theme folder, the cache driver): `.env` is the source. They are read while the site
 *   builds or starts, before any database row is available, so the admin shows them read-only and
 *   `php artisan cms:env-set` changes them (followed by a deploy for the theme, which rebuilds its CSS).
 *
 * With the runtime off (legacy sites) nothing changes: `.env` stays first and the admin save still writes it.
 *
 * @package Cms\Core\Services\Settings
 * @author  iSPA Team
 * @since   2026.11 (2026-11-27)
 */
final class EnvBoundSettings
{
    /** Mail keys that are env-bound even without `isEnv` (GenerateSettingCommand always treated them so). */
    public const MAIL_KEYS = [
        'email.MAIL_MAILER',
        'email.MAIL_HOST',
        'email.MAIL_PORT',
        'email.MAIL_ENCRYPTION',
        'email.MAIL_USERNAME',
        'email.MAIL_PASSWORD',
        'email.MAIL_FROM_ADDRESS',
        'email.MAIL_FROM_NAME',
        'email.MAIL_REPLY',
    ];

    /** Setting keys whose source stays `.env` (read at build or boot time); never changed by the admin save. */
    public const BOOT_KEYS = [
        'advanced.VITE_TEMPLATE_FOLDER',
        'cache.CACHE_DRIVER',
    ];

    /** `.env` variables that may only change through `cms:env-set` (the boot keys' names). */
    public const BOOT_ENV_NAMES = [
        'VITE_TEMPLATE_FOLDER',
        'CACHE_DRIVER',
    ];

    /**
     * Whether a setting row is env-bound.
     *
     * @param string $settingKey   Setting key (e.g. `api.GOOGLE_API`).
     * @param mixed  $inputSetting The row's `input_setting` (array or JSON string).
     */
    public static function isEnvBound(string $settingKey, mixed $inputSetting): bool
    {
        return self::flag($inputSetting, 'isEnv') || in_array($settingKey, self::MAIL_KEYS, true);
    }

    /**
     * Whether a setting key is a build or boot key (`.env` is its source).
     */
    public static function isBootKey(string $settingKey): bool
    {
        return in_array($settingKey, self::BOOT_KEYS, true);
    }

    /**
     * Whether the database is the source of env-bound application settings on this site (settings runtime on).
     */
    public static function databaseIsSource(): bool
    {
        return (bool) config('ispa-cms.setting_runtime_enabled', true);
    }

    /**
     * Whether a reader takes the database row before the `.env` value for this key.
     */
    public static function databaseFirst(string $settingKey): bool
    {
        return self::databaseIsSource() && !self::isBootKey($settingKey);
    }

    /**
     * `.env` lines written together with a variable: the theme folder also names its Tailwind entry, config and output.
     *
     * @param string $name  `.env` variable.
     * @param string $value Its new value.
     *
     * @return array<string, string> Variable => value, the variable itself first.
     */
    public static function envLines(string $name, string $value): array
    {
        if ($name !== 'VITE_TEMPLATE_FOLDER') {
            return [$name => $value];
        }

        return [
            'VITE_TEMPLATE_FOLDER' => $value,
            'VITE_TEMPLATE_TAILWIND_CSS' => 'projects/' . $value . '/dev/theme.css',
            'VITE_TEMPLATE_TAILWIND_CONFIG' => 'projects/' . $value . '/dev/tailwind.config.js',
            'VITE_TEMPLATE_TAILWIND_OUTPUT' => './public/' . $value . '/assets/css',
        ];
    }

    /**
     * Read a boolean flag from `input_setting` (array, JSON string or JSON encoded twice).
     */
    private static function flag(mixed $inputSetting, string $name): bool
    {
        $data = $inputSetting;
        for ($i = 0; $i < 2 && is_string($data); $i++) {
            $data = json_decode($data, true);
        }

        return is_array($data) && ($data[$name] ?? false) === true;
    }
}
