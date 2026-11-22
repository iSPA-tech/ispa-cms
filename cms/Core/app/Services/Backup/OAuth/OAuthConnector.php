<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup\OAuth;

use App\Models\User;
use Cms\Core\Models\Backup\BackupDestination;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Connects a Google Drive or OneDrive backup destination through OAuth 2.0 (authorization code + PKCE S256)
 * and hands out short-lived access tokens to the destination drivers.
 *
 * Client credentials are set by the operator on the destination: the OAuth client id in `config['client_id']`
 * and the client secret (optional for public clients) in the encrypted `credentials['client_secret']`.
 * The only token kept at rest is `credentials['refresh_token']`; access tokens live encrypted in the cache with a
 * lifetime below the provider's `expires_in` and are never written to the database.
 *
 * The callback carries no admin token, so the `state` value authenticates it: 32 random bytes, cached for
 * {@see STATE_TTL} seconds with the issuing user id, the destination uuid and driver and the PKCE verifier, usable once.
 * Error codes are thrown as {@see RuntimeException} messages (`BACKUP_OAUTH_*`).
 */
class OAuthConnector
{
    /** Lifetime of an issued state, in seconds. */
    public const STATE_TTL = 600;

    /** Error code set on the destination when the stored grant no longer works. */
    public const RECONNECT = 'BACKUP_OAUTH_RECONNECT';

    /** Seconds subtracted from `expires_in` before an access token leaves the cache. */
    private const ACCESS_TOKEN_MARGIN = 60;

    /** @var array<string, OAuthProvider> */
    private array $providers = [];

    /**
     * Create a connector with the given providers (defaults: Google Drive and OneDrive).
     *
     * @param array<int, OAuthProvider>|null $providers
     */
    public function __construct(?array $providers = null)
    {
        foreach ($providers ?? [new GoogleOAuthProvider(), new MicrosoftOAuthProvider()] as $provider) {
            $this->providers[$provider->driver()] = $provider;
        }
    }

    /**
     * Whether the destination driver connects through OAuth.
     */
    public function supports(BackupDestination $destination): bool
    {
        return isset($this->providers[(string) $destination->driver]);
    }

    /**
     * Absolute callback URL registered with the providers.
     */
    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v2/admin/backups/oauth/callback';
    }

    /**
     * Issue a single-use state for the user and destination and return the provider authorization URL.
     *
     * @throws RuntimeException BACKUP_OAUTH_DRIVER_UNSUPPORTED or BACKUP_OAUTH_CLIENT_MISSING
     */
    public function start(BackupDestination $destination, User $user): string
    {
        $provider = $this->provider($destination);
        $clientId = $this->clientId($destination);

        $state = self::base64Url(random_bytes(32));
        $verifier = self::base64Url(random_bytes(32));

        Cache::put(self::stateKey($state), [
            'user_id' => (int) $user->getKey(),
            'destination_uuid' => (string) $destination->uuid,
            'driver' => (string) $destination->driver,
            'verifier' => Crypt::encryptString($verifier),
        ], self::STATE_TTL);

        return $provider->authorizationUrl(
            $clientId,
            $this->redirectUri(),
            $state,
            self::base64Url(hash('sha256', $verifier, true)),
        );
    }

    /**
     * Finish a connection from the provider callback; true when a refresh token was stored.
     *
     * The state is consumed first. The token endpoint is called only when the state is fresh, unused, issued to a
     * user who still exists and is still a Webmaster, and the destination still exists with the same driver.
     * A provider `error` on the callback burns the state without any outbound call. Nothing from the provider
     * is returned or logged beyond an HTTP status.
     */
    public function complete(
        ?string $state,
        #[\SensitiveParameter] ?string $code,
        bool $providerError = false,
    ): bool {
        $pending = $this->consumeState($state);
        if ($pending === null) {
            return $this->fail('BACKUP_OAUTH_STATE_INVALID');
        }

        $destination = BackupDestination::query()->where('uuid', $pending['destination_uuid'])->first();
        $context = ['destination' => $pending['destination_uuid']];

        if ($providerError || $code === null || $code === '') {
            return $this->fail('BACKUP_OAUTH_DENIED', $context);
        }

        $user = User::query()->find($pending['user_id']);
        if (!$user instanceof User || !$user->isWebmaster()) {
            return $this->fail('BACKUP_OAUTH_USER_NOT_ALLOWED', $context);
        }

        if ($destination === null || (string) $destination->driver !== $pending['driver'] || !$this->supports($destination)) {
            return $this->fail('BACKUP_OAUTH_DESTINATION_CHANGED', $context);
        }

        try {
            $provider = $this->provider($destination);
            $form = array_merge($this->clientParams($destination), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'code_verifier' => Crypt::decryptString((string) $pending['verifier']),
            ]);
            $response = $this->post($provider->tokenUrl(), $form);
        } catch (Throwable) {
            return $this->fail('BACKUP_OAUTH_EXCHANGE_FAILED', $context);
        }

        $refreshToken = $response->successful() ? $response->json('refresh_token') : null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            return $this->fail('BACKUP_OAUTH_EXCHANGE_FAILED', $context + ['status' => $response->status()]);
        }

        $this->storeRefreshToken($destination, $refreshToken);
        if ($destination->last_error_code === self::RECONNECT) {
            $destination->forceFill(['last_error_code' => null])->save();
        }
        $this->cacheAccessToken($destination, $response);

        return true;
    }

    /**
     * Return a valid access token for the destination, refreshing it with the stored refresh token when needed.
     *
     * @throws RuntimeException BACKUP_OAUTH_RECONNECT when no grant is stored or the provider answers
     *                          `invalid_grant` (the destination's `last_error_code` is set), or
     *                          BACKUP_OAUTH_REFRESH_FAILED on any other refresh failure
     */
    public function accessToken(BackupDestination $destination): string
    {
        $cached = Cache::get(self::accessKey($destination));
        if (is_string($cached) && $cached !== '') {
            try {
                return Crypt::decryptString($cached);
            } catch (Throwable) {
                $this->forgetAccessToken($destination);
            }
        }

        $provider = $this->provider($destination);
        $refreshToken = $destination->credentials['refresh_token'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            $this->markReconnect($destination);
            throw new RuntimeException(self::RECONNECT);
        }

        try {
            $response = $this->post($provider->tokenUrl(), array_merge($this->clientParams($destination), $provider->refreshParams(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]));
        } catch (Throwable) {
            Log::warning('Backup OAuth token refresh failed.', ['destination' => (string) $destination->uuid, 'reason' => 'connection']);
            throw new RuntimeException('BACKUP_OAUTH_REFRESH_FAILED');
        }

        if ($response->json('error') === 'invalid_grant') {
            $this->markReconnect($destination);
            Log::warning('Backup OAuth grant was revoked or expired.', ['destination' => (string) $destination->uuid]);
            throw new RuntimeException(self::RECONNECT);
        }

        $accessToken = $response->successful() ? $response->json('access_token') : null;
        if (!is_string($accessToken) || $accessToken === '') {
            Log::warning('Backup OAuth token refresh failed.', ['destination' => (string) $destination->uuid, 'status' => $response->status()]);
            throw new RuntimeException('BACKUP_OAUTH_REFRESH_FAILED');
        }

        // Providers may rotate the refresh token (Microsoft does); keep the newest one.
        $rotated = $response->json('refresh_token');
        if (is_string($rotated) && $rotated !== '' && $rotated !== $refreshToken) {
            $this->storeRefreshToken($destination, $rotated);
        }
        $this->cacheAccessToken($destination, $response);

        return $accessToken;
    }

    /**
     * Drop the cached access token so the next {@see accessToken()} call refreshes it (for example after a 401).
     */
    public function forgetAccessToken(BackupDestination $destination): void
    {
        Cache::forget(self::accessKey($destination));
    }

    /**
     * Pull the pending state from the cache; null when it is malformed, unknown, expired or already used.
     *
     * @return array{user_id: int, destination_uuid: string, driver: string, verifier: string}|null
     */
    private function consumeState(?string $state): ?array
    {
        if ($state === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $state) !== 1) {
            return null;
        }

        $key = self::stateKey($state);
        $pending = Cache::pull($key);
        // A second marker makes the state single use even when two callbacks race between get and forget.
        if (!is_array($pending) || !Cache::add($key . ':used', 1, self::STATE_TTL)) {
            return null;
        }

        if (!isset($pending['user_id'], $pending['destination_uuid'], $pending['driver'], $pending['verifier'])) {
            return null;
        }

        return $pending;
    }

    /**
     * Merge the refresh token into the destination credentials, keeping the other keys (client secret).
     */
    private function storeRefreshToken(BackupDestination $destination, #[\SensitiveParameter] string $refreshToken): void
    {
        $credentials = is_array($destination->credentials) ? $destination->credentials : [];
        unset($credentials['access_token']);
        $credentials['refresh_token'] = $refreshToken;
        $destination->credentials = $credentials;
        $destination->save();
    }

    /**
     * Cache the access token of a token response (encrypted) for less than its lifetime.
     */
    private function cacheAccessToken(BackupDestination $destination, Response $response): void
    {
        $accessToken = $response->json('access_token');
        $ttl = (int) ($response->json('expires_in') ?? 3600) - self::ACCESS_TOKEN_MARGIN;
        if (!is_string($accessToken) || $accessToken === '' || $ttl <= 0) {
            return;
        }

        Cache::put(self::accessKey($destination), Crypt::encryptString($accessToken), $ttl);
    }

    /**
     * Record that the destination must be connected again.
     */
    private function markReconnect(BackupDestination $destination): void
    {
        $this->forgetAccessToken($destination);
        $destination->forceFill(['last_error_code' => self::RECONNECT])->save();
    }

    /**
     * POST a form to a token endpoint.
     *
     * @param array<string, string> $form
     *
     * @throws ConnectionException
     */
    private function post(string $url, #[\SensitiveParameter] array $form): Response
    {
        return Http::asForm()->acceptJson()->timeout(20)->post($url, $form);
    }

    /**
     * Client id and (when set) client secret form fields of the destination.
     *
     * @return array<string, string>
     */
    private function clientParams(BackupDestination $destination): array
    {
        $params = ['client_id' => $this->clientId($destination)];
        $secret = $destination->credentials['client_secret'] ?? null;
        if (is_string($secret) && $secret !== '') {
            $params['client_secret'] = $secret;
        }

        return $params;
    }

    /**
     * OAuth client id set by the operator in the destination config.
     *
     * @throws RuntimeException BACKUP_OAUTH_CLIENT_MISSING
     */
    private function clientId(BackupDestination $destination): string
    {
        $clientId = $destination->config['client_id'] ?? null;
        if (!is_string($clientId) || trim($clientId) === '') {
            throw new RuntimeException('BACKUP_OAUTH_CLIENT_MISSING');
        }

        return trim($clientId);
    }

    /**
     * Provider for the destination driver.
     *
     * @throws RuntimeException BACKUP_OAUTH_DRIVER_UNSUPPORTED
     */
    private function provider(BackupDestination $destination): OAuthProvider
    {
        return $this->providers[(string) $destination->driver]
            ?? throw new RuntimeException('BACKUP_OAUTH_DRIVER_UNSUPPORTED');
    }

    /**
     * Log a failed callback with its reason code only and report failure.
     *
     * @param array<string, mixed> $context
     */
    private function fail(string $reason, array $context = []): bool
    {
        Log::warning('Backup OAuth connection failed.', ['reason' => $reason] + $context);

        return false;
    }

    /**
     * Cache key of a pending state (the raw state never appears in a key).
     */
    private static function stateKey(#[\SensitiveParameter] string $state): string
    {
        return 'core:backup:oauth:state:' . hash('sha256', $state);
    }

    /**
     * Cache key of a destination access token.
     */
    private static function accessKey(BackupDestination $destination): string
    {
        return 'core:backup:oauth:access:' . $destination->uuid;
    }

    /**
     * base64url encoding without padding (RFC 7636).
     */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
