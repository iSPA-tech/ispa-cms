<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Platform;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * One-tap hub connect from the admin (plan 2026-11-22-hub-one-tap-connect, authorization code + PKCE).
 *
 * start: a random state and PKCE verifier are kept server-side (state as sha256, verifier encrypted), bound to the
 * admin, single use, 10 minutes; the browser is sent to the hub portal `/portal/connect-site` with the S256 challenge and a
 * return URL on this site's own host.
 *
 * finish: the state is checked (known, unexpired, unused, same admin) before anything reaches the hub; the attempt is
 * then marked used whatever happens next. The code is exchanged server to server with the verifier for the site id and
 * secret (an unsigned call: the site has no secret yet; same https rule as PlatformHubClient, no redirects), and the
 * pair runs SiteConnectService::connectAsAdmin, so the signed connect, the `.well-known` proof, the encrypted store and
 * the audit happen exactly as for the manual form.
 *
 * Credentials (review 2026-11-22, I2/I3): a site whose credentials come from `.env` is refused in start and finish
 * (SiteConnectService::OUTCOME_MANAGED_BY_ENV): only the server operator can change them. A site with credentials
 * asks before going to the hub (`replace`, kept on the attempt), and again in finish when credentials appeared meanwhile.
 * The domain sent to the hub and compared with its `site_id` is SiteDomain::bare() of the APP_URL host; the return URL
 * keeps the APP_URL host itself.
 *
 * Nothing here logs or returns the code, the verifier, the state or the secret.
 */
final class HubOneTapConnect
{
    public const TABLE = 'core_hub_connect_attempts';

    public const TTL_MINUTES = 10;

    public const PORTAL_PATH = '/portal/connect-site';

    public const TOKEN_PATH = '/api/v2/public/platform/site-connect/token';

    public const CALLBACK_PATH = '/iPanel/hub-connect/callback';

    public const OUTCOME_STARTED = 'started';

    /** This site's own host (APP_URL) is missing or not a valid host name. */
    public const OUTCOME_SITE_UNKNOWN = 'site_unknown';

    public const OUTCOME_STATE_INVALID = 'state_invalid';

    public const OUTCOME_DENIED = 'denied';

    public const OUTCOME_DOMAIN_TAKEN = 'domain_taken';

    /** The hub row for this domain was turned off in the owning account (hub 409 HUB_CONNECT_SITE_DISABLED). */
    public const OUTCOME_SITE_DISABLED = 'site_disabled';

    public const OUTCOME_EXCHANGE_FAILED = 'exchange_failed';

    /** Hosts that may use plain http (local development only). */
    private const LOCAL_HOST_PATTERN = '/(^localhost$|\.localhost$|\.test$)/';

    /**
     * Create the service.
     */
    public function __construct(
        private readonly SiteConnectService $connect,
        private readonly HubCredentials $credentials,
    ) {
    }

    /**
     * Start an attempt for this admin and return where to send the browser.
     *
     * @param bool $replace The admin confirmed replacing the credentials this site already has.
     * @return array{outcome: string, redirect_url?: string} outcome is OUTCOME_STARTED, OUTCOME_SITE_UNKNOWN,
     *         SiteConnectService::OUTCOME_MANAGED_BY_ENV, SiteConnectService::OUTCOME_ALREADY_CONNECTED (credentials
     *         exist and `$replace` is false) or SiteConnectService::OUTCOME_HUB_UNREACHABLE (hub address not https).
     */
    public function start(Authenticatable $user, bool $replace = false): array
    {
        $refusal = $this->credentialRefusal($replace);
        if ($refusal !== null) {
            return ['outcome' => $refusal];
        }
        $site = $this->siteOrigin();
        if ($site === null) {
            return ['outcome' => self::OUTCOME_SITE_UNKNOWN];
        }
        try {
            $portal = $this->portalUrl();
        } catch (PlatformHubException) {
            return ['outcome' => SiteConnectService::OUTCOME_HUB_UNREACHABLE];
        }

        $state = $this->randomToken();
        $verifier = $this->randomToken();
        $now = Carbon::now();

        $this->prune();
        DB::table(self::TABLE)->insert([
            'state_hash' => hash('sha256', $state),
            'verifier' => Crypt::encryptString($verifier),
            'user_id' => (int) $user->getAuthIdentifier(),
            'replace_confirmed' => $replace,
            'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
            'used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $query = http_build_query([
            'domain' => $site['domain'],
            'state' => $state,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'return_url' => $site['origin'] . self::CALLBACK_PATH,
        ], '', '&', PHP_QUERY_RFC3986);

        return ['outcome' => self::OUTCOME_STARTED, 'redirect_url' => $portal . self::PORTAL_PATH . '?' . $query];
    }

    /**
     * Finish an attempt with what the portal sent back (`code` and `state`, or `error` and `state`).
     *
     * `.env` credentials answer SiteConnectService::OUTCOME_MANAGED_BY_ENV (the attempt is used). Existing credentials
     * without `$replace` (or the answer kept from start) answer SiteConnectService::OUTCOME_ALREADY_CONNECTED before the
     * attempt is used or the code exchanged, so the admin can confirm and resend within the code's lifetime.
     *
     * @return array{outcome: string, message_key?: string, source?: string, warning_key?: ?string} outcome is one of the
     *         OUTCOME_* constants here or the SiteConnectService outcomes of connectAsAdmin.
     */
    public function finish(Authenticatable $user, mixed $code, mixed $state, bool $replace, mixed $error = null): array
    {
        $attempt = $this->openAttempt($state);
        if ($attempt === null) {
            return ['outcome' => self::OUTCOME_STATE_INVALID];
        }
        if ((int) $attempt->user_id !== (int) $user->getAuthIdentifier()) {
            $this->markUsed((int) $attempt->id);

            return ['outcome' => self::OUTCOME_STATE_INVALID];
        }
        if ($error !== null && $error !== '') {
            $this->markUsed((int) $attempt->id);

            return ['outcome' => self::OUTCOME_DENIED];
        }
        if (! is_string($code) || $code === '' || strlen($code) > 512) {
            $this->markUsed((int) $attempt->id);

            return ['outcome' => self::OUTCOME_EXCHANGE_FAILED];
        }
        $replace = $replace || (bool) ($attempt->replace_confirmed ?? false);
        $refusal = $this->credentialRefusal($replace);
        if ($refusal === SiteConnectService::OUTCOME_MANAGED_BY_ENV) {
            $this->markUsed((int) $attempt->id);
        }
        if ($refusal !== null) {
            return ['outcome' => $refusal];
        }
        if (! $this->markUsed((int) $attempt->id)) {
            return ['outcome' => self::OUTCOME_STATE_INVALID];
        }

        $site = $this->siteOrigin();
        try {
            $verifier = Crypt::decryptString((string) $attempt->verifier);
        } catch (\Throwable) {
            return ['outcome' => self::OUTCOME_EXCHANGE_FAILED];
        }
        if ($site === null) {
            return ['outcome' => self::OUTCOME_SITE_UNKNOWN];
        }

        $pair = $this->exchange($code, $verifier, $site['domain']);
        if (is_string($pair)) {
            return ['outcome' => $pair];
        }

        $result = $this->connect->connectAsAdmin($pair['site_id'], $pair['secret'], (int) $user->getAuthIdentifier(), $replace);

        return $result['outcome'] === SiteConnectService::OUTCOME_INVALID_INPUT
            ? ['outcome' => self::OUTCOME_EXCHANGE_FAILED]
            : $result;
    }

    /**
     * Delete attempts that ended (expired) more than a day ago; run daily by the scheduler and on each start.
     *
     * @return int Rows deleted.
     */
    public function prune(): int
    {
        return DB::table(self::TABLE)->where('expires_at', '<', Carbon::now()->subDay())->delete();
    }

    /**
     * Why credentials already in place stop the one-tap flow: `.env` credentials (operator-managed) always, any other
     * credentials unless the admin confirmed replacing them; null when the flow may go on.
     */
    private function credentialRefusal(bool $replace): ?string
    {
        if ($this->credentials->source() === HubCredentials::SOURCE_ENV) {
            return SiteConnectService::OUTCOME_MANAGED_BY_ENV;
        }

        return ! $replace && ($this->credentials->isConfigured() || $this->credentials->hasStoredPair())
            ? SiteConnectService::OUTCOME_ALREADY_CONNECTED
            : null;
    }

    /**
     * The open attempt for a state: known, unused and unexpired, or null.
     */
    private function openAttempt(mixed $state): ?object
    {
        if (! is_string($state) || preg_match('/^[A-Za-z0-9_-]{32,128}$/', $state) !== 1) {
            return null;
        }
        $row = DB::table(self::TABLE)->where('state_hash', hash('sha256', $state))->first();
        if ($row === null || $row->used_at !== null || ! Carbon::parse($row->expires_at)->isFuture()) {
            return null;
        }

        return $row;
    }

    /**
     * Mark an attempt used; false when another request used it first.
     */
    private function markUsed(int $id): bool
    {
        $now = Carbon::now();

        return DB::table(self::TABLE)->where('id', $id)->whereNull('used_at')
            ->update(['used_at' => $now, 'updated_at' => $now]) === 1;
    }

    /**
     * Exchange the code and verifier for the site id and secret.
     *
     * @return array{site_id: string, secret: string}|string The pair, or OUTCOME_EXCHANGE_FAILED, OUTCOME_DOMAIN_TAKEN or OUTCOME_SITE_DISABLED.
     */
    private function exchange(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier, string $domain): array|string
    {
        try {
            $base = rtrim((string) config('ispa-cms.platform_hub_url', 'https://ispa.tech'), '/');
            PlatformHubClient::assertSecureUrl($base);
            $response = Http::withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(20)
                ->acceptJson()
                ->asJson()
                ->post($base . self::TOKEN_PATH, ['code' => $code, 'code_verifier' => $verifier, 'domain' => $domain]);
        } catch (\Throwable) {
            return self::OUTCOME_EXCHANGE_FAILED;
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if (! $response->successful()) {
            return match ($json['error_code'] ?? null) {
                'HUB_CONNECT_DOMAIN_TAKEN' => self::OUTCOME_DOMAIN_TAKEN,
                'HUB_CONNECT_SITE_DISABLED' => self::OUTCOME_SITE_DISABLED,
                default => self::OUTCOME_EXCHANGE_FAILED,
            };
        }

        $pair = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $siteId = $pair['site_id'] ?? null;
        $secret = $pair['secret'] ?? null;
        if (! is_string($siteId) || SiteDomain::bare($siteId) !== $domain || ! is_string($secret) || $secret === '') {
            return self::OUTCOME_EXCHANGE_FAILED;
        }

        return ['site_id' => $domain, 'secret' => $secret];
    }

    /**
     * This site's id on the hub (SiteDomain::bare() of the APP_URL host) and the origin the portal returns to: the
     * APP_URL host itself, https, except plain http (and a port) kept for local development hosts.
     *
     * @return array{domain: string, origin: string}|null
     */
    private function siteOrigin(): ?array
    {
        $url = (string) config('app.url', '');
        $host = rtrim(strtolower((string) parse_url($url, PHP_URL_HOST)), '.');
        if (preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
            return null;
        }
        $local = preg_match(self::LOCAL_HOST_PATTERN, $host) === 1;
        $scheme = $local && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 'http' : 'https';
        $port = parse_url($url, PHP_URL_PORT);

        return ['domain' => SiteDomain::bare($host), 'origin' => $scheme . '://' . $host . ($local && is_int($port) ? ':' . $port : '')];
    }

    /**
     * The hub portal base: `core.hub.portal_url`, or the scheme and host of the hub address.
     *
     * @throws PlatformHubException When the address is not https.
     */
    private function portalUrl(): string
    {
        $configured = trim((string) config('core.hub.portal_url', ''));
        if ($configured === '') {
            $hub = (string) config('ispa-cms.platform_hub_url', 'https://ispa.tech');
            $port = parse_url($hub, PHP_URL_PORT);
            $configured = parse_url($hub, PHP_URL_SCHEME) . '://' . parse_url($hub, PHP_URL_HOST) . (is_int($port) ? ':' . $port : '');
        }
        $configured = rtrim($configured, '/');
        PlatformHubClient::assertSecureUrl($configured);

        return $configured;
    }

    /**
     * 32 random bytes, base64url without padding (43 characters).
     */
    private function randomToken(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
