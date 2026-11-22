<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use App\Support\TokenGate;
use Cms\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Site settings of the API token policy, stored as one JSON value in the `settings` row
 * `security.token_settings` (private, never part of the public config snapshot, not a secret).
 *
 * Defaults reproduce today's behaviour exactly: no lifetime for any audience, no idle timeout, at most 50 live tokens
 * per account and audience, enforcement mode `log`, no cleanup of unused tokens. Lifetimes are chosen from a fixed
 * option list per audience; "never" is an option that needs an explicit confirmation at the API. A read never fails:
 * a missing table, row or damaged value yields the defaults.
 *
 * @package Cms\Core\Services\Security
 */
class TokenSettings
{
    /** Settings row key. */
    public const SETTING_KEY = 'security.token_settings';

    /** Settings row key of the new-device notice switch (default off). */
    public const NOTIFY_NEW_DEVICE_KEY = 'security.tokens.notify_new_device';

    /** Lifetime option keys and their minutes; null means "never expires". */
    public const LIFETIME_OPTIONS = [
        '1h' => 60,
        '6h' => 360,
        '12h' => 720,
        '1d' => 1440,
        '7d' => 10080,
        '30d' => 43200,
        '90d' => 129600,
        'never' => null,
    ];

    /** Lifetime key meaning "never expires". */
    public const NEVER = 'never';

    /** Allowed idle timeouts in days; 0 = off. */
    public const IDLE_DAY_OPTIONS = [0, 1, 7, 30];

    /** Allowed cleanup ages of unused tokens in days; 0 = off. */
    public const UNUSED_DAY_OPTIONS = [0, 30, 90, 180];

    /** Enforcement modes of the audience check. */
    public const MODES = ['off', 'log', 'enforce'];

    /** Lowest and highest cap of live tokens per account and audience. */
    public const MIN_LIVE_TOKENS = 1;
    public const MAX_LIVE_TOKENS = 100;

    /** Hint shown next to the admin audience; never applied automatically. */
    public const RECOMMENDED_LIFETIME = [TokenAudience::ADMIN => '7d'];

    /** @var array<string, mixed>|null Settings read once per instance. */
    private ?array $loaded = null;

    /**
     * Defaults that reproduce today's behaviour.
     *
     * @return array{lifetime: array<string, string>, idle_days: array<string, int>, max_live_tokens: int, mode: string, unused_days: int}
     */
    public static function defaults(): array
    {
        $audiences = TokenAudience::userAudiences();

        return [
            'lifetime' => array_fill_keys($audiences, self::NEVER),
            'idle_days' => array_fill_keys($audiences, 0),
            'max_live_tokens' => TokenPolicy::DEFAULT_MAX_LIVE_TOKENS,
            'mode' => 'log',
            'unused_days' => 0,
        ];
    }

    /**
     * All current settings (stored values merged over the defaults, invalid stored values replaced by defaults).
     *
     * @return array{lifetime: array<string, string>, idle_days: array<string, int>, max_live_tokens: int, mode: string, unused_days: int}
     */
    public function all(): array
    {
        if ($this->loaded === null) {
            $this->loaded = $this->normalize($this->readStored());
        }

        return $this->loaded;
    }

    /**
     * Lifetime option key of an audience.
     *
     * @param string $audience One of TokenAudience::userAudiences().
     * @return string Key of LIFETIME_OPTIONS.
     */
    public function lifetimeKey(string $audience): string
    {
        return $this->all()['lifetime'][$audience] ?? self::NEVER;
    }

    /**
     * Lifetime of new tokens of an audience in minutes.
     *
     * @param string $audience Audience.
     * @return int|null Null when tokens never expire.
     */
    public function lifetimeMinutes(string $audience): ?int
    {
        return self::LIFETIME_OPTIONS[$this->lifetimeKey($audience)] ?? null;
    }

    /**
     * Idle timeout of an audience in days.
     *
     * @param string $audience Audience.
     * @return int 0 when no idle timeout applies.
     */
    public function idleDays(string $audience): int
    {
        return (int) ($this->all()['idle_days'][$audience] ?? 0);
    }

    /**
     * Cap of live tokens per account and audience.
     *
     * @return int
     */
    public function maxLiveTokens(): int
    {
        return $this->all()['max_live_tokens'];
    }

    /**
     * Enforcement mode of the audience check.
     *
     * @return string One of MODES.
     */
    public function mode(): string
    {
        return $this->all()['mode'];
    }

    /**
     * Days after which an unused token is cleaned up.
     *
     * @return int 0 when the cleanup is off.
     */
    public function unusedDays(): int
    {
        return $this->all()['unused_days'];
    }

    /**
     * Whether a sign-in from a new device or network sends the account a notice. Off by default; stored in
     * its own private row so the token policy JSON and its API are unchanged. A read never fails.
     *
     * @return bool
     */
    public function notifyNewDevice(): bool
    {
        try {
            $value = Setting::query()->where('setting_key', self::NOTIFY_NEW_DEVICE_KEY)->first()?->value;
        } catch (Throwable) {
            return false;
        }

        return in_array($value, [true, 1, '1', 'true'], true);
    }

    /**
     * Switch the new-device notice on or off for the site.
     *
     * @param bool $enabled New value.
     * @return void
     */
    public function setNotifyNewDevice(bool $enabled): void
    {
        $setting = Setting::withTrashed()->where('setting_key', self::NOTIFY_NEW_DEVICE_KEY)->first() ?? new Setting();
        if ($setting->trashed()) {
            $setting->restore();
        }
        $setting->fill([
            'setting_key' => self::NOTIFY_NEW_DEVICE_KEY,
            'type' => 'text',
            'group_name' => 'security',
            'name' => 'tokens.notify_new_device',
            'input_setting' => ['isPublic' => false, 'isEnv' => false, 'title' => 'New-device sign-in notice'],
            'value' => $enabled ? '1' : '0',
        ])->save();
    }

    /**
     * Merge a validated partial change into the stored settings and save the result.
     *
     * @param array<string, mixed> $changes Keys: lifetime (audience => option), idle_days (audience => days),
     *                                      max_live_tokens, mode, unused_days. Values must already be validated.
     * @return list<string> Changed setting keys (for example `lifetime.admin`), never values.
     */
    public function update(array $changes): array
    {
        $before = $this->all();
        $after = $this->normalize($this->merge($before, $changes));
        $changed = $this->diffKeys($before, $after);

        $setting = Setting::withTrashed()->where('setting_key', self::SETTING_KEY)->first() ?? new Setting();
        if ($setting->trashed()) {
            $setting->restore();
        }
        $setting->fill([
            'setting_key' => self::SETTING_KEY,
            'type' => 'textarea',
            'group_name' => 'security',
            'name' => 'token_settings',
            'input_setting' => ['isPublic' => false, 'isEnv' => false, 'title' => 'API token settings'],
            'value' => json_encode($after, JSON_THROW_ON_ERROR),
        ])->save();

        $this->loaded = $after;
        // The token gate keeps a short-lived snapshot of these settings; drop it so the change applies at once.
        Cache::forget(TokenGate::SETTINGS_CACHE_KEY);

        return $changed;
    }

    /**
     * Audiences whose lifetime is "never".
     *
     * @param array<string, mixed>|null $settings Settings to inspect; null for the current ones.
     * @return list<string>
     */
    public function neverAudiences(?array $settings = null): array
    {
        $lifetime = ($settings ?? $this->all())['lifetime'];

        return array_values(array_filter(array_keys($lifetime), static fn (string $a): bool => $lifetime[$a] === self::NEVER));
    }

    /**
     * Normalize a settings array: keep only known audiences and values, fill defaults.
     *
     * @param array<string, mixed> $raw Stored or merged settings.
     * @return array{lifetime: array<string, string>, idle_days: array<string, int>, max_live_tokens: int, mode: string, unused_days: int}
     */
    public function normalize(array $raw): array
    {
        $out = self::defaults();
        foreach (TokenAudience::userAudiences() as $audience) {
            $life = $raw['lifetime'][$audience] ?? null;
            if (is_string($life) && array_key_exists($life, self::LIFETIME_OPTIONS)) {
                $out['lifetime'][$audience] = $life;
            }
            $idle = $raw['idle_days'][$audience] ?? null;
            if (is_int($idle) && in_array($idle, self::IDLE_DAY_OPTIONS, true)) {
                $out['idle_days'][$audience] = $idle;
            }
        }
        $cap = $raw['max_live_tokens'] ?? null;
        if (is_int($cap) && $cap >= self::MIN_LIVE_TOKENS && $cap <= self::MAX_LIVE_TOKENS) {
            $out['max_live_tokens'] = $cap;
        }
        if (is_string($raw['mode'] ?? null) && in_array($raw['mode'], self::MODES, true)) {
            $out['mode'] = $raw['mode'];
        }
        $unused = $raw['unused_days'] ?? null;
        if (is_int($unused) && in_array($unused, self::UNUSED_DAY_OPTIONS, true)) {
            $out['unused_days'] = $unused;
        }

        return $out;
    }

    /**
     * Apply a partial change over a full settings array.
     *
     * @param array<string, mixed> $base Current settings.
     * @param array<string, mixed> $changes Partial change.
     * @return array<string, mixed>
     */
    public function merge(array $base, array $changes): array
    {
        foreach (['lifetime', 'idle_days'] as $group) {
            foreach ((array) ($changes[$group] ?? []) as $audience => $value) {
                $base[$group][$audience] = $value;
            }
        }
        foreach (['max_live_tokens', 'mode', 'unused_days'] as $key) {
            if (array_key_exists($key, $changes)) {
                $base[$key] = $changes[$key];
            }
        }

        return $base;
    }

    /**
     * Names of the settings that differ between two normalized arrays.
     *
     * @param array<string, mixed> $before Old settings.
     * @param array<string, mixed> $after New settings.
     * @return list<string>
     */
    private function diffKeys(array $before, array $after): array
    {
        $changed = [];
        foreach (['lifetime', 'idle_days'] as $group) {
            foreach ($after[$group] as $audience => $value) {
                if (($before[$group][$audience] ?? null) !== $value) {
                    $changed[] = $group . '.' . $audience;
                }
            }
        }
        foreach (['max_live_tokens', 'mode', 'unused_days'] as $key) {
            if ($before[$key] !== $after[$key]) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Read the stored JSON; any failure yields an empty array so the defaults apply.
     *
     * @return array<string, mixed>
     */
    private function readStored(): array
    {
        try {
            // Read through the model: private rows are encrypted at rest and the cast decrypts them.
            $value = Setting::query()->where('setting_key', self::SETTING_KEY)->first()?->value;
            $decoded = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }
}
