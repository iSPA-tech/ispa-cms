<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Platform;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Whether this site is connected to the platform hub, asked by the site backend only (, spec 12.4).
 *
 * - The hub is asked through the signed `POST /api/platform/v1/site/status` (PlatformHubClient); the answer is cached
 *   for 15 minutes (encrypted, it carries the domain token).
 * - The last good answer (connected or pending) is also kept encrypted in `core_hub_credentials`; while the hub cannot
 *   be reached it is served for 7 days from when it was received. A hub that cannot be reached is asked again after
 *   one minute at the earliest.
 * - A hub that refuses the site (401/403) ends the connection at once: the last good answer is forgotten.
 * - A hub without the status endpoint (404/405) is asked through the old `GET /api/v2/public/verify` instead, by this
 *   backend; the browser never calls the hub.
 * - A site without hub credentials is not turned away at once (, rule "existing sites keep working with no
 *   action"): the old unsigned verify is asked server-side with the site domain, cached and kept for the grace like any
 *   other answer, and flagged legacy (isLegacy()) so the hub-side count can see it. Only a site the hub does not know at
 *   all gets not_connected, which the admin shows as the connect screen.
 *
 * - The answer also carries `module_install` (allowed|forbidden, spec 2026-11-22 module install from the admin): whether
 *   an iSPA hub admin forbids module install on this site. It is cached and kept for the grace like the rest of the
 *   answer; a hub that never sent it (older hub) or a site without an answer gives null, so the admin switch decides.
 *
 * current() is safe to return to a browser: status, message key and expiry date only. The domain token is read only
 * through domainToken(), for the hub-run services (App\Clients\CmsClient). Nothing here logs the token or the secret.
 */
final class SiteStatusService
{
    public const CONNECTED = 'connected';

    public const PENDING = 'pending';

    public const NOT_CONNECTED = 'not_connected';

    public const MESSAGE_CONNECTED = 'text.site_status.connected';

    public const MESSAGE_PENDING = 'text.site_status.pending';

    public const MESSAGE_NOT_CONNECTED = 'text.site_status.not_connected';

    public const MESSAGE_NOT_CONFIGURED = 'text.site_status.not_configured';

    public const MESSAGE_HUB_UNREACHABLE = 'text.site_status.hub_unreachable';

    public const MESSAGE_HUB_UNREACHABLE_GRACE = 'text.site_status.hub_unreachable_grace';

    public const STATUS_PATH = '/api/platform/v1/site/status';

    public const LEGACY_VERIFY_PATH = '/api/v2/public/verify';

    public const FRESH_SECONDS = 15 * 60;

    public const GRACE_SECONDS = 7 * 24 * 3600;

    public const RETRY_SECONDS = 60;

    public const MODULE_INSTALL_ALLOWED = 'allowed';

    public const MODULE_INSTALL_FORBIDDEN = 'forbidden';

    /** Default of `CMS_DOMAIN`: the hub itself, never a valid site id for a fresh install. */
    private const HUB_DEFAULT_DOMAIN = 'cms.ispa.io';

    private const CACHE_PREFIX = 'core:platform:site-status:';

    /**
     * Create the service.
     */
    public function __construct(private readonly HubCredentials $credentials)
    {
    }

    /**
     * The answer for the admin: `{status, message_key, expires_at}`. No token, secret or hub address.
     *
     * @return array{status: string, message_key: string, expires_at: ?string}
     */
    public function current(): array
    {
        $answer = $this->answer();

        return [
            'status' => $answer['status'],
            'message_key' => $answer['message_key'],
            'expires_at' => $answer['expires_at'],
        ];
    }

    /**
     * The domain token for the hub-run services, or null when the site is not connected. Server-side only.
     */
    public function domainToken(): ?string
    {
        $answer = $this->answer();

        return $answer['status'] === self::CONNECTED && is_string($answer['token']) && $answer['token'] !== ''
            ? $answer['token']
            : null;
    }

    /**
     * Whether the hub allows module install on this site: `allowed`, `forbidden`, or null when no answer (fresh,
     * cached or within the grace) says so. Server-side only.
     */
    public function moduleInstall(): ?string
    {
        return $this->answer()['module_install'];
    }

    /**
     * The module install word already known, without asking the hub: the cached answer while fresh, otherwise the
     * last good answer within the 7 day grace, otherwise null. For the install path, which must never wait on the hub;
     * the value is refreshed whenever the verification asks the hub (admin site status, hub-run services).
     */
    public function knownModuleInstall(): ?string
    {
        if (! $this->credentials->isConfigured() && $this->siteDomain() === '') {
            return null;
        }

        $cached = $this->readCache();
        if ($cached !== null) {
            return $cached['module_install'];
        }

        $last = $this->credentials->lastStatus();
        $receivedAt = is_int($last['received_at'] ?? null) ? $last['received_at'] : null;
        if ($last === null || $receivedAt === null || ($last['domain'] ?? null) !== $this->siteDomain()
            || Carbon::now()->getTimestamp() - $receivedAt > self::GRACE_SECONDS
            || ! in_array($last['status'] ?? null, [self::CONNECTED, self::PENDING], true)) {
            return null;
        }

        return $this->moduleInstallWord($last['module_install'] ?? null);
    }

    /**
     * Drop the cached answer so the next call asks the hub again (after new credentials are stored, for example).
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Whether the current answer came from the old unsigned verify endpoint (a site without hub credentials, or a hub
     * without the status endpoint). Server-side only; never part of current().
     */
    public function isLegacy(): bool
    {
        return $this->answer()['legacy'];
    }

    /**
     * The cached answer, or a fresh one from the hub.
     *
     * @return array{status: string, message_key: string, expires_at: ?string, token: ?string, legacy: bool, module_install: ?string}
     */
    private function answer(): array
    {
        if (! $this->credentials->isConfigured() && $this->siteDomain() === '') {
            return $this->result(self::NOT_CONNECTED, self::MESSAGE_NOT_CONFIGURED);
        }

        $cached = $this->readCache();
        if ($cached !== null) {
            return $cached;
        }

        [$answer, $ttl] = $this->ask();
        $this->writeCache($answer, $ttl);

        return $answer;
    }

    /**
     * Ask the hub and turn its answer, refusal or silence into a result plus how long to cache it.
     *
     * @return array{0: array{status: string, message_key: string, expires_at: ?string, token: ?string, legacy: bool, module_install: ?string}, 1: int}
     */
    private function ask(): array
    {
        $configured = $this->credentials->isConfigured();
        if (! $configured) {
            $good = $this->fromLegacyVerify();
        } else {
            try {
                $client = new PlatformHubClient(connectTimeout: 3, timeout: 8);
                $good = $this->fromStatusEndpoint($client->post(self::STATUS_PATH));
            } catch (PlatformHubException $e) {
                $good = match (true) {
                    $e->reason() === PlatformHubException::HTTP_STATUS && in_array($e->status(), [404, 405], true) => $this->fromLegacyVerify(),
                    $e->reason() === PlatformHubException::HTTP_STATUS && in_array($e->status(), [401, 403], true) => false,
                    default => null,
                };
            }
        }

        if ($good === false) {
            $this->credentials->forgetLastStatus();

            return [
                $this->result(self::NOT_CONNECTED, $configured ? self::MESSAGE_NOT_CONNECTED : self::MESSAGE_NOT_CONFIGURED),
                self::FRESH_SECONDS,
            ];
        }

        if ($good === null) {
            return [$this->grace(), self::RETRY_SECONDS];
        }

        $this->remember($good);

        return [$good, self::FRESH_SECONDS];
    }

    /**
     * Read the signed status answer: a result, or null when its shape is wrong (treated as unreachable).
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{status: string, message_key: string, expires_at: ?string, token: ?string, legacy: bool, module_install: ?string}|null
     */
    private function fromStatusEndpoint(array $payload): ?array
    {
        $status = $payload['status'] ?? null;
        if ($status === self::CONNECTED) {
            $token = is_string($payload['token'] ?? null) && $payload['token'] !== '' ? $payload['token'] : null;

            return $token === null ? null : $this->result(self::CONNECTED, self::MESSAGE_CONNECTED, $this->date($payload['expires_at'] ?? null), $token, moduleInstall: $this->moduleInstallWord($payload['module_install'] ?? null));
        }
        if ($status === self::PENDING) {
            return $this->result(self::PENDING, self::MESSAGE_PENDING, $this->date($payload['expires_at'] ?? null), moduleInstall: $this->moduleInstallWord($payload['module_install'] ?? null));
        }

        return null;
    }

    /**
     * Old hub without the status endpoint: ask its unsigned `GET /api/v2/public/verify` with `x-domain`, as the
     * browser did before. A result, false when the hub does not know the domain, null when it cannot be read.
     *
     * @return array{status: string, message_key: string, expires_at: ?string, token: ?string, legacy: bool, module_install: ?string}|false|null
     */
    private function fromLegacyVerify(): array|false|null
    {
        $url = rtrim((string) config('ispa-cms.platform_hub_url', 'https://ispa.tech'), '/') . self::LEGACY_VERIFY_PATH;

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(8)
                ->acceptJson()
                ->withHeaders(['x-domain' => $this->siteDomain()])
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }

        $json = $response->json();
        if ($response->status() === 200 && is_array($json) && ($json['status'] ?? null) === 'success') {
            $token = $json['data']['token'] ?? null;

            return is_string($token) && $token !== ''
                ? $this->result(self::CONNECTED, self::MESSAGE_CONNECTED, $this->date($json['data']['expired_at'] ?? null), $token, true, $this->moduleInstallWord($json['data']['module_install'] ?? null))
                : null;
        }
        if ($response->status() === 400 && is_array($json) && ($json['status'] ?? null) === 'error') {
            return false;
        }

        return null;
    }

    /**
     * The hub cannot be reached: the last good answer while it is younger than 7 days, otherwise not connected.
     *
     * @return array{status: string, message_key: string, expires_at: ?string, token: ?string, legacy: bool, module_install: ?string}
     */
    private function grace(): array
    {
        $last = $this->credentials->lastStatus();
        $receivedAt = is_int($last['received_at'] ?? null) ? $last['received_at'] : null;
        $sameSite = ($last['domain'] ?? null) === $this->siteDomain();

        if ($last === null || $receivedAt === null || ! $sameSite || Carbon::now()->getTimestamp() - $receivedAt > self::GRACE_SECONDS) {
            return $this->result(self::NOT_CONNECTED, self::MESSAGE_HUB_UNREACHABLE);
        }

        $status = in_array($last['status'] ?? null, [self::CONNECTED, self::PENDING], true) ? $last['status'] : self::NOT_CONNECTED;
        if ($status === self::NOT_CONNECTED) {
            return $this->result(self::NOT_CONNECTED, self::MESSAGE_HUB_UNREACHABLE);
        }

        return $this->result(
            $status,
            self::MESSAGE_HUB_UNREACHABLE_GRACE,
            is_string($last['expires_at'] ?? null) ? $last['expires_at'] : null,
            is_string($last['token'] ?? null) ? $last['token'] : null,
            ($last['legacy'] ?? false) === true,
            $this->moduleInstallWord($last['module_install'] ?? null),
        );
    }

    /**
     * Keep a good answer for the grace period. A storage failure only costs the grace, never the answer.
     *
     * @param  array{status: string, message_key: string, expires_at: ?string, token: ?string, legacy: bool, module_install: ?string}  $good
     */
    private function remember(array $good): void
    {
        try {
            $this->credentials->storeLastStatus([
                'domain' => $this->siteDomain(),
                'status' => $good['status'],
                'expires_at' => $good['expires_at'],
                'token' => $good['token'],
                'legacy' => $good['legacy'],
                'module_install' => $good['module_install'],
                'received_at' => Carbon::now()->getTimestamp(),
            ]);
        } catch (\Throwable) {
            Log::warning('The last platform hub status answer could not be stored; the hub outage grace is not available.');
        }
    }

    /**
     * The cached answer for the current site and hub, or null when absent, stale or unreadable.
     *
     * @return array{status: string, message_key: string, expires_at: ?string, token: ?string, legacy: bool, module_install: ?string}|null
     */
    private function readCache(): ?array
    {
        $entry = Cache::get($this->cacheKey());
        if (! is_array($entry) || ! is_string($entry['payload'] ?? null) || ! is_int($entry['until'] ?? null)) {
            return null;
        }
        // The deadline travels with the entry, so a cache driver that keeps entries longer cannot serve a stale answer.
        if (Carbon::now()->getTimestamp() >= $entry['until']) {
            return null;
        }

        try {
            $answer = json_decode(Crypt::decryptString($entry['payload']), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($answer) && isset($answer['status'], $answer['message_key']) ? $this->result(
            (string) $answer['status'],
            (string) $answer['message_key'],
            is_string($answer['expires_at'] ?? null) ? $answer['expires_at'] : null,
            is_string($answer['token'] ?? null) ? $answer['token'] : null,
            ($answer['legacy'] ?? false) === true,
            $this->moduleInstallWord($answer['module_install'] ?? null),
        ) : null;
    }

    /**
     * Cache an answer, encrypted because it may carry the domain token.
     *
     * @param  array{status: string, message_key: string, expires_at: ?string, token: ?string, legacy: bool, module_install: ?string}  $answer
     */
    private function writeCache(array $answer, int $seconds): void
    {
        Cache::put($this->cacheKey(), [
            'payload' => Crypt::encryptString(json_encode($answer, JSON_THROW_ON_ERROR)),
            'until' => Carbon::now()->getTimestamp() + $seconds,
        ], $seconds);
    }

    /**
     * Cache key bound to the site id and the hub address, so new credentials or another hub never reuse an answer.
     */
    private function cacheKey(): string
    {
        return self::CACHE_PREFIX . sha1($this->siteDomain() . '|' . (string) config('ispa-cms.platform_hub_url', ''));
    }

    /**
     * An ISO 8601 date from the hub's expiry value, or null.
     */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * One result row.
     *
     * @return array{status: string, message_key: string, expires_at: ?string, token: ?string, legacy: bool, module_install: ?string}
     */
    private function result(string $status, string $messageKey, ?string $expiresAt = null, ?string $token = null, bool $legacy = false, ?string $moduleInstall = null): array
    {
        return ['status' => $status, 'message_key' => $messageKey, 'expires_at' => $expiresAt, 'token' => $token, 'legacy' => $legacy, 'module_install' => $moduleInstall];
    }

    /**
     * The hub's module install word, or null for anything else (an older hub sends none).
     */
    private function moduleInstallWord(mixed $value): ?string
    {
        return in_array($value, [self::MODULE_INSTALL_ALLOWED, self::MODULE_INSTALL_FORBIDDEN], true) ? $value : null;
    }

    /**
     * The site id asked about: the credentials' domain, or for a site without credentials the domain the old verify
     * used. That is `CMS_DOMAIN` when the operator changed it from the hub default, otherwise the host of APP_URL, so a
     * fresh install that never set `CMS_DOMAIN` is not answered with the hub's own token. "" when neither is known.
     */
    private function siteDomain(): string
    {
        if ($this->credentials->isConfigured()) {
            return $this->credentials->domain();
        }

        $configured = strtolower(trim((string) config('ispa-cms.cms_domain', '')));
        if ($configured !== '' && $configured !== self::HUB_DEFAULT_DOMAIN) {
            return $configured;
        }

        $host = parse_url((string) config('app.url', ''), PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
