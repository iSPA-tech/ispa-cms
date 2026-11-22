<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Environment;

use App\Constants\CacheConstants;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Helpers\RedisHelper;
use Cms\Core\Models\Setting;
use Cms\Core\Services\CMSSettingRuntime;
use Cms\Core\Services\ConfigPackage\SecretGuard;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Lists, sets and clears environment-bound settings (: only rows already declared as settings).
 *
 * Allowed rows are the ones `cms:setting-initialize --updateEnv=true` sends to `.env`: `input_setting.isEnv` true or
 * one of the nine `email.MAIL_*` keys (GenerateSettingCommand::MAIL_ENV_SETTING_KEYS). Values are saved through
 * Eloquent `save()` so the `EncryptedSettingValue` cast encrypts them, then the changed row alone is written to the
 * environment file through `env_update()` (same escaping as `prepare_env`) and the same caches as the settings save
 * are dropped. Secret values are write-only: list() never returns them. No value ever reaches a log, an exception
 * message or an audit entry (audit payload is `{key, secret}` only).
 */
class EnvironmentSettingService
{
    /** Keys GenerateSettingCommand always sends to `.env` even without `isEnv` (kept in sync with that command). */
    public const MAIL_ENV_SETTING_KEYS = [
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

    /** Longest accepted value. */
    public const MAX_VALUE_LENGTH = 4096;

    /** Groups whose change restarts the queue workers (they cache mail and notifier configuration). */
    private const QUEUE_RESTART_PREFIXES = ['email.', 'notify.'];

    /**
     * @param SecretGuard $guard Shared secret classifier.
     */
    public function __construct(private readonly SecretGuard $guard)
    {
    }

    /**
     * List every allowed environment setting; secret values are always null.
     *
     * @return list<array{key: string, env_name: string, group: string|null, label: string, title: string|null,
     *     secret: bool, has_value: bool, value: string|null, in_env_file: bool}>
     */
    public function list(): array
    {
        return $this->allowedSettings()
            ->map(fn (Setting $setting): array => $this->present($setting))
            ->values()
            ->all();
    }

    /**
     * Present one allowed row (used by the API after a change).
     *
     * @param string $key Setting key.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When the key is not an allowed environment setting.
     */
    public function item(string $key): array
    {
        return $this->present($this->findAllowed($key));
    }

    /**
     * Store a new value for an allowed environment setting and write it to the environment file.
     *
     * @param string $key    Setting key (e.g. `email.MAIL_HOST`).
     * @param string $value  New value; use clear() to empty it.
     * @param int    $userId Acting user (audit).
     *
     * @throws InvalidArgumentException When the key is not allowed or the value is empty or invalid.
     * @throws RuntimeException         When saving fails (message names the key only).
     */
    public function set(string $key, string $value, int $userId): void
    {
        $setting = $this->findAllowed($key);
        self::assertValidValue($value);

        $this->write($setting, $value, 'config.env.update', $userId);
    }

    /**
     * Empty an allowed environment setting in the database and the environment file.
     *
     * @param string $key    Setting key.
     * @param int    $userId Acting user (audit).
     *
     * @throws InvalidArgumentException When the key is not allowed.
     * @throws RuntimeException         When saving fails (message names the key only).
     */
    public function clear(string $key, int $userId): void
    {
        $this->write($this->findAllowed($key), null, 'config.env.clear', $userId);
    }

    /**
     * Reject empty values, over-long values and control characters other than newline and tab.
     *
     * Newlines are accepted because `prepare_env` writes them as an escaped `\n` inside double quotes; a carriage
     * return would be silently dropped by `prepare_env`, so it is refused instead. Messages never include the value.
     *
     * @param string $value Candidate value.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValidValue(string $value): void
    {
        if ($value === '') {
            throw new InvalidArgumentException('The value is empty; clear the setting instead.');
        }
        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException('The value is longer than ' . self::MAX_VALUE_LENGTH . ' characters.');
        }
        if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $value) === 1 || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('The value contains control characters.');
        }
    }

    /**
     * Tell whether a setting key is an allowed environment setting.
     *
     * @param string $key Setting key.
     */
    public function isAllowed(string $key): bool
    {
        return $this->allowedSettings()->has($key);
    }

    /**
     * Save, sync, audit. The value never leaves this method except into the row and the environment file.
     */
    private function write(Setting $setting, ?string $value, string $action, int $userId): void
    {
        $key = (string) $setting->setting_key;
        $secret = $this->isSecret($setting);

        try {
            $setting->value = $value;
            $setting->save();

            if ($this->envName($setting)['in_env_file']) {
                $setting->value = $value ?? '';
                env_update(collect([$setting]), $this->cacheEnableValue());
            }
        } catch (Throwable $e) {
            // The original exception may carry the value (SQL bindings), so neither it nor its message is kept.
            CMSLogger::error([
                'message' => 'Environment setting write failed',
                'key' => $key,
                'exception_class' => $e::class,
            ]);

            throw new RuntimeException("Saving the environment setting {$key} failed.");
        }

        $this->synchronize($key);
        $this->audit($action, $key, $secret, (int) $setting->id, $userId);
    }

    /**
     * Drop the same caches as the settings save (SettingController::synchronizeSettingFile) after `.env` changed.
     */
    private function synchronize(string $key): void
    {
        try {
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
            RedisHelper::getInstance()->del(CacheConstants::CACHE_SETTINGS);
            app(CMSSettingRuntime::class)->forget();

            foreach (self::QUEUE_RESTART_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    Artisan::call('queue:restart');
                    break;
                }
            }
        } catch (Throwable $e) {
            CMSLogger::error([
                'message' => 'Environment setting sync failed',
                'key' => $key,
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * Dispatch the audit event with the key and the secret flag only; a failing listener never undoes the change.
     */
    private function audit(string $action, string $key, bool $secret, int $settingId, int $userId): void
    {
        try {
            event(new AuditLogEvent($action, ['key' => $key, 'secret' => $secret], Setting::class, $settingId, null, null, $userId));
        } catch (Throwable $e) {
            CMSLogger::error([
                'message' => 'Environment setting audit failed',
                'key' => $key,
                'action' => $action,
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * Allowed rows keyed by setting key.
     *
     * @return \Illuminate\Support\Collection<string, Setting>
     */
    private function allowedSettings(): \Illuminate\Support\Collection
    {
        return Setting::query()
            ->orderBy('group_name')
            ->orderBy('setting_key')
            ->get()
            ->filter(fn (Setting $s): bool => $this->isEnvBound($s))
            ->keyBy('setting_key');
    }

    /**
     * @throws InvalidArgumentException
     */
    private function findAllowed(string $key): Setting
    {
        $setting = $this->allowedSettings()->get($key);
        if (!$setting instanceof Setting) {
            throw new InvalidArgumentException('This key is not an environment setting that can be changed here.');
        }

        return $setting;
    }

    /**
     * Same rule as GenerateSettingCommand::shouldSynchronizeToEnvironment.
     */
    private function isEnvBound(Setting $setting): bool
    {
        $input = $this->inputSetting($setting);

        return ($input['isEnv'] ?? false) === true
            || in_array((string) $setting->setting_key, self::MAIL_ENV_SETTING_KEYS, true);
    }

    /**
     * Secret (write-only) when the settings list masks the row too: the same SecretGuard::isMaskedSetting() policy,
     * so a plain env value such as email.MAIL_USERNAME is readable here as it is in the settings list. The export
     * path keeps the broader isSecretSetting().
     */
    private function isSecret(Setting $setting): bool
    {
        return $this->guard->isMaskedSetting([
            'type' => $setting->type,
            'setting_key' => (string) $setting->setting_key,
            'name' => $setting->name,
            'input_setting' => $this->inputSetting($setting),
        ]);
    }

    /**
     * Environment name as env_update() derives it, and whether env_update() writes the row at all.
     *
     * @return array{name: string, in_env_file: bool}
     */
    private function envName(Setting $setting): array
    {
        $count = 0;
        $name = str_replace(['email.', 'advanced.', 'api.'], '', (string) $setting->setting_key, $count);

        if ($setting->name === 'CACHE_DRIVER') {
            return ['name' => str_replace('cache.', '', (string) $setting->setting_key), 'in_env_file' => true];
        }
        if ($setting->name === 'ANALYTICS_VIEW_ID') {
            return ['name' => str_replace('tracking.', '', (string) $setting->setting_key), 'in_env_file' => true];
        }
        if ($count > 0) {
            return ['name' => $name, 'in_env_file' => true];
        }

        // Not written by env_update (e.g. notify.*): show the last key segment as its name.
        $parts = explode('.', (string) $setting->setting_key);

        return ['name' => (string) end($parts), 'in_env_file' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Setting $setting): array
    {
        $secret = $this->isSecret($setting);
        $raw = $setting->getRawOriginal('value');
        $hasValue = $raw !== null && (string) $raw !== '';
        $input = $this->inputSetting($setting);
        $env = $this->envName($setting);

        $value = null;
        if (!$secret && $hasValue) {
            $plain = $setting->value;
            $value = is_bool($plain) ? ($plain ? 'true' : 'false') : (is_scalar($plain) ? (string) $plain : null);
        }

        return [
            'key' => (string) $setting->setting_key,
            'env_name' => $env['name'],
            'group' => $setting->group_name,
            'label' => (string) ($input['label'] ?? $setting->name ?? $setting->setting_key),
            // Translation key of the operator-facing title (the same one the settings screens translate).
            'title' => isset($input['title']) && is_string($input['title']) && $input['title'] !== '' ? $input['title'] : null,
            'secret' => $secret,
            'has_value' => $hasValue,
            'value' => $value,
            'in_env_file' => $env['in_env_file'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inputSetting(Setting $setting): array
    {
        $input = $setting->input_setting;
        for ($i = 0; $i < 2 && is_string($input); $i++) {
            $input = json_decode($input, true);
        }

        return is_array($input) ? $input : [];
    }

    /**
     * CACHE_ENABLE value passed to env_update() the way GenerateSettingCommand does.
     */
    private function cacheEnableValue(): string
    {
        $value = Setting::query()->where('name', 'CACHE_ENABLE')->first()?->value;

        return is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? 'false');
    }
}
