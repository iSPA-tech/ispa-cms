<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Platform;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Site credentials for the platform hub (site id = registered domain, plus the site secret) and the install setup code.
 *
 * Read order: an operator-set `.env` pair (`CMS_DOMAIN` + `CMS_DOMAIN_SECRET`, read through config so it survives
 * `config:cache`) wins whenever its secret is set; otherwise the pair stored encrypted (APP_KEY) in
 * `core_hub_credentials` is used. This service writes only that table and never writes `.env`.
 *
 * Setup code: 8 groups of 4 base32 characters, shown once to whoever runs the install. Only its sha256 is stored. It is
 * checked with hash_equals and stops working after invalidateSetupCode() (a successful connect), after 7 days, or
 * when a new code is issued.
 *
 * Nothing here logs, returns to a browser or puts in an exception message the secret or the setup code.
 */
final class HubCredentials
{
    public const TABLE = 'core_hub_credentials';

    public const SOURCE_ENV = 'env';

    public const SOURCE_DATABASE = 'database';

    public const SETUP_CODE_TTL_DAYS = 7;

    private const ROW_SITE = 'site';

    private const ROW_SETUP_CODE = 'setup_code';

    private const ROW_STATUS = 'site_status';

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * The credentials in use, or null when neither `.env` nor the database holds a complete pair.
     *
     * @return array{domain: string, secret: string, source: string}|null
     */
    public function credentials(): ?array
    {
        $envSecret = (string) config('ispa-cms.cms_domain_secret', '');
        if ($envSecret !== '') {
            return [
                'domain' => trim((string) config('ispa-cms.cms_domain', '')),
                'secret' => $envSecret,
                'source' => self::SOURCE_ENV,
            ];
        }

        $stored = $this->storedPair();

        return $stored === null ? null : $stored + ['source' => self::SOURCE_DATABASE];
    }

    /**
     * Whether a complete domain + secret pair is available.
     */
    public function isConfigured(): bool
    {
        $credentials = $this->credentials();

        return $credentials !== null && $credentials['domain'] !== '' && $credentials['secret'] !== '';
    }

    /**
     * Whether a complete pair is stored in the database (an operator-set `.env` pair is not counted).
     */
    public function hasStoredPair(): bool
    {
        return $this->storedPair() !== null;
    }

    /**
     * Where the credentials in use come from (SOURCE_ENV or SOURCE_DATABASE), or null when there are none.
     */
    public function source(): ?string
    {
        return $this->credentials()['source'] ?? null;
    }

    /**
     * Site id (registered domain) in use. Falls back to config `ispa-cms.cms_domain` when no pair is stored.
     */
    public function domain(): string
    {
        return $this->credentials()['domain'] ?? trim((string) config('ispa-cms.cms_domain', ''));
    }

    /**
     * Site secret in use, or "" when none is configured. Server-side only: never log or return it.
     */
    public function secret(): string
    {
        return $this->credentials()['secret'] ?? '';
    }

    /**
     * Store the site id and secret encrypted in the database, replacing any stored pair. Never writes `.env`; an
     * operator-set `.env` secret keeps winning over this value.
     */
    public function store(string $domain, #[\SensitiveParameter] string $secret): void
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || $secret === '') {
            throw new \InvalidArgumentException('Platform hub domain and secret are required.');
        }

        $payload = Crypt::encryptString(json_encode(['domain' => $domain, 'secret' => $secret], JSON_THROW_ON_ERROR));
        $now = Carbon::now();

        DB::table(self::TABLE)->updateOrInsert(
            ['name' => self::ROW_SITE],
            ['value' => $payload, 'expires_at' => null, 'used_at' => null, 'created_at' => $now, 'updated_at' => $now],
        );
    }

    /**
     * Issue a new setup code (replacing any earlier one), store only its hash and return the code for display.
     *
     * @return string Code formatted as 8 dash-separated groups of 4 base32 characters.
     */
    public function issueSetupCode(): string
    {
        $code = $this->base32(random_bytes(20));
        $now = Carbon::now();

        DB::table(self::TABLE)->updateOrInsert(
            ['name' => self::ROW_SETUP_CODE],
            [
                'value' => hash('sha256', $code),
                'expires_at' => $now->copy()->addDays(self::SETUP_CODE_TTL_DAYS),
                'used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return implode('-', str_split($code, 4));
    }

    /**
     * Whether the given setup code matches the live one. Spaces, dashes and case are ignored. Does not consume it:
     * call invalidateSetupCode() once the connect succeeded.
     */
    public function checkSetupCode(#[\SensitiveParameter] string $code): bool
    {
        $normalized = preg_replace('/[^A-Z2-7]/', '', strtoupper($code)) ?? '';
        if (strlen($normalized) !== 32) {
            return false;
        }

        $row = $this->row(self::ROW_SETUP_CODE);
        if ($row === null || $row->used_at !== null || $row->expires_at === null || ! is_string($row->value)) {
            return false;
        }
        if (Carbon::parse($row->expires_at)->lessThanOrEqualTo(Carbon::now())) {
            return false;
        }

        return hash_equals($row->value, hash('sha256', $normalized));
    }

    /**
     * Stop the current setup code from working (called after a successful connect).
     */
    public function invalidateSetupCode(): void
    {
        DB::table(self::TABLE)
            ->where('name', self::ROW_SETUP_CODE)
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /**
     * First-boot hook: issue and return a setup code only when the site has no credentials and no setup code was ever
     * issued, so the code is shown once. Returns null otherwise (or when the table does not exist yet).
     */
    public function announceSetupCodeOnce(): ?string
    {
        return $this->firstSetupCodeDue() ? $this->issueSetupCode() : null;
    }

    /**
     * Whether the first-boot setup code is still due: the site has no credentials and no setup code was ever issued
     * (false when the table does not exist yet). Issues nothing.
     */
    public function firstSetupCodeDue(): bool
    {
        try {
            return ! $this->isConfigured() && ! DB::table(self::TABLE)->where('name', self::ROW_SETUP_CODE)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the console output is an interactive terminal: a setup code is printed only there, never into
     * a CI, cron or piped log. Tests bind `cms.console.terminal` to choose.
     */
    public static function consoleIsTerminal(): bool
    {
        if (app()->bound('cms.console.terminal')) {
            return (bool) app('cms.console.terminal');
        }

        return defined('STDOUT') && @stream_isatty(STDOUT);
    }

    /**
     * Store the last good hub status answer (it holds the domain token) encrypted with APP_KEY, replacing the previous
     * one. Kept in the database rather than the cache so a `cache:clear` during a hub outage does not lose it.
     *
     * @param  array<string, mixed>  $answer
     */
    public function storeLastStatus(array $answer): void
    {
        $now = Carbon::now();

        DB::table(self::TABLE)->updateOrInsert(
            ['name' => self::ROW_STATUS],
            [
                'value' => Crypt::encryptString(json_encode($answer, JSON_THROW_ON_ERROR)),
                'expires_at' => null,
                'used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    /**
     * The last good hub status answer, or null when none is stored or it cannot be decrypted.
     *
     * @return array<string, mixed>|null
     */
    public function lastStatus(): ?array
    {
        $row = $this->row(self::ROW_STATUS);
        if ($row === null || ! is_string($row->value) || $row->value === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($row->value), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Forget the last good hub status answer (the hub refused the site).
     */
    public function forgetLastStatus(): void
    {
        try {
            DB::table(self::TABLE)->where('name', self::ROW_STATUS)->delete();
        } catch (\Throwable) {
            // Table not migrated yet: nothing stored.
        }
    }

    /**
     * Decrypt the stored pair; null when absent, incomplete or unreadable (for example after an APP_KEY change).
     *
     * @return array{domain: string, secret: string}|null
     */
    private function storedPair(): ?array
    {
        $row = $this->row(self::ROW_SITE);
        if ($row === null || ! is_string($row->value) || $row->value === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($row->value), true);
        } catch (DecryptException) {
            // The dashboard reads this on every request: warn once a day, not on every read.
            if (Cache::add('platform-hub:credentials-undecryptable-warned', true, now()->addDay())) {
                Log::warning('Platform hub credentials stored in the database cannot be decrypted; APP_KEY may have changed.');
            }

            return null;
        }

        $domain = is_array($decoded) ? trim((string) ($decoded['domain'] ?? '')) : '';
        $secret = is_array($decoded) ? (string) ($decoded['secret'] ?? '') : '';

        return $domain !== '' && $secret !== '' ? ['domain' => $domain, 'secret' => $secret] : null;
    }

    /**
     * One row of the credentials table, or null when absent or the table does not exist yet.
     */
    private function row(string $name): ?object
    {
        try {
            return DB::table(self::TABLE)->where('name', $name)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * RFC 4648 base32 without padding (20 bytes give exactly 32 characters).
     */
    private function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }
}
