<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Platform;

use Cms\Core\Models\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Connects a fresh site to the platform hub from the pre-login connect screen (, spec 12.2 steps 3-4), or from
 * the admin for a signed-in user with `hub.connect` (connectAsAdmin, no setup code; spec 2026-11-22).
 *
 * Order, so a wrong setup code can never store or send anything:
 * 1. the setup code is checked (HubCredentials::checkSetupCode, hash_equals) before anything else (the admin path
 *    skips only this step);
 * 2. the submitted pair is held only in an encrypted cache window while the hub is called, so the `.well-known`
 *    answer route can prove the secret to the hub's domain check; the window closes when the call returns;
 * 3. a signed hub answer (connected or pending) proves the pair is right, so it is stored encrypted in the database
 *    (HubCredentials::store, never `.env`); a connected answer also ends the setup code. A refusal, silence or an
 *    answer not signed with the submitted secret stores nothing.
 *
 * Nothing here logs or returns the secret, the setup code or the hub address.
 */
final class SiteConnectService
{
    public const CONNECT_PATH = '/api/platform/v1/site/connect';

    /** How long the domain check may be answered after the hub call starts; longer than the hub's own 5 s check. */
    public const WINDOW_SECONDS = 45;

    public const OUTCOME_CONNECTED = 'connected';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_INVALID_INPUT = 'invalid_input';

    public const OUTCOME_BAD_SETUP_CODE = 'setup_code_invalid';

    public const OUTCOME_MANAGED_BY_ENV = 'managed_by_env';

    public const OUTCOME_REFUSED = 'credentials_refused';

    public const OUTCOME_HUB_UNREACHABLE = 'hub_unreachable';

    /** Admin connect only: stored credentials exist and the request did not ask to replace them. */
    public const OUTCOME_ALREADY_CONNECTED = 'already_connected';

    public const AUDIT_CONNECTED = 'hub.connected';

    public const AUDIT_RECONNECTED = 'hub.reconnected';

    /** Admin connect stored a pair, but the operator-set `.env` pair keeps winning. */
    public const WARNING_ENV_WINS = 'text.site_connect.env_wins';

    /** Reasons the hub may give for a pending site; each has a plain sentence on the connect screen. */
    private const PENDING_REASONS = [
        'not_resolved', 'private_address', 'unreachable', 'redirect', 'no_answer', 'wrong_answer',
        'secret_expired', 'too_many_attempts', 'managed',
    ];

    private const WINDOW_KEY = 'core:platform:connect-window';

    /**
     * Create the service.
     */
    public function __construct(
        private readonly HubCredentials $credentials,
        private readonly SiteStatusService $status,
    ) {
    }

    /**
     * Run the connect from the pre-login screen: the setup code is checked before anything else.
     *
     * @return array{outcome: string, message_key: string} outcome is one of the OUTCOME_* constants.
     */
    public function connect(string $siteId, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $setupCode): array
    {
        $domain = $this->validPair($siteId, $secret);
        if ($domain === null || trim($setupCode) === '' || strlen($setupCode) > 128) {
            return $this->outcome(self::OUTCOME_INVALID_INPUT);
        }
        if (! $this->credentials->checkSetupCode($setupCode)) {
            return $this->outcome(self::OUTCOME_BAD_SETUP_CODE);
        }
        if ($this->credentials->source() === HubCredentials::SOURCE_ENV) {
            return $this->outcome(self::OUTCOME_MANAGED_BY_ENV);
        }

        return $this->connectPair($domain, $secret);
    }

    /**
     * Run the connect for a signed-in user allowed to connect (spec 2026-11-22): the same pipeline without the setup
     * code check, since the admin sign-in is the proof of authority. Stored credentials are kept unless `$replace`;
     * an operator-set `.env` pair keeps winning, so the pair is stored but `source` says `env` with a warning key.
     * A stored pair (connected or pending) is audited as `hub.connected` / `hub.reconnected` with the actor id.
     *
     * @return array{outcome: string, message_key: string, source?: string, warning_key?: ?string}
     */
    public function connectAsAdmin(string $siteId, #[\SensitiveParameter] string $secret, int $actorUserId, bool $replace): array
    {
        $domain = $this->validPair($siteId, $secret);
        if ($domain === null) {
            return $this->outcome(self::OUTCOME_INVALID_INPUT);
        }
        $hadStored = $this->credentials->hasStoredPair();
        if ($hadStored && ! $replace) {
            return $this->outcome(self::OUTCOME_ALREADY_CONNECTED);
        }

        $result = $this->connectPair($domain, $secret);
        if (! in_array($result['outcome'], [self::OUTCOME_CONNECTED, self::OUTCOME_PENDING], true)) {
            return $result;
        }

        $this->audit($hadStored ? self::AUDIT_RECONNECTED : self::AUDIT_CONNECTED, $actorUserId, $domain, $result['outcome']);
        $envWins = $this->credentials->source() === HubCredentials::SOURCE_ENV;

        return $result + ['source' => $envWins ? 'env' : 'stored', 'warning_key' => $envWins ? self::WARNING_ENV_WINS : null];
    }

    /**
     * Let the `.well-known` route answer the hub's domain check for this secret until the window closes.
     */
    public function openCallbackWindow(#[\SensitiveParameter] string $secret): void
    {
        Cache::put(self::WINDOW_KEY, Crypt::encryptString($secret), self::WINDOW_SECONDS);
    }

    /**
     * Stop answering domain checks.
     */
    public function closeCallbackWindow(): void
    {
        Cache::forget(self::WINDOW_KEY);
    }

    /**
     * The proof for the hub's domain check of one nonce, or null when no connect call is outstanding or the nonce does
     * not have the shape the hub makes (64 lowercase hex characters).
     */
    public function callbackProof(string $nonce): ?string
    {
        if (preg_match(PlatformSignature::CALLBACK_NONCE_PATTERN, $nonce) !== 1) {
            return null;
        }
        $held = Cache::get(self::WINDOW_KEY);
        if (! is_string($held)) {
            return null;
        }

        try {
            $secret = Crypt::decryptString($held);
        } catch (\Throwable) {
            return null;
        }

        return PlatformSignature::callback($secret, $nonce);
    }

    /**
     * The shared pipeline once the caller is trusted: hold the secret for the domain check while the hub is called,
     * store the pair only on a signed answer, and end the setup code when the hub says connected.
     *
     * @return array{outcome: string, message_key: string}
     */
    private function connectPair(string $domain, #[\SensitiveParameter] string $secret): array
    {
        $this->openCallbackWindow($secret);
        try {
            $answer = $this->callHub($domain, $secret);
        } finally {
            $this->closeCallbackWindow();
        }
        if (is_string($answer)) {
            return $this->outcome($answer);
        }

        $this->credentials->store($domain, $secret);
        $this->status->forget();
        if ($answer['status'] === self::OUTCOME_CONNECTED) {
            $this->credentials->invalidateSetupCode();

            return $this->outcome(self::OUTCOME_CONNECTED);
        }

        return $this->outcome(self::OUTCOME_PENDING, in_array($answer['reason'], self::PENDING_REASONS, true) ? $answer['reason'] : null);
    }

    /**
     * The normalized site id when the site id and secret have an acceptable shape, otherwise null.
     */
    private function validPair(string $siteId, #[\SensitiveParameter] string $secret): ?string
    {
        $domain = $this->normalizeDomain($siteId);

        return $domain === null || strlen($secret) < 8 || strlen($secret) > 256 ? null : $domain;
    }

    /**
     * Audit row for an admin connect: actor, site id and hub answer only (never the secret or the hub address).
     */
    private function audit(string $action, int $actorUserId, string $domain, string $outcome): void
    {
        $request = app()->runningInConsole() ? null : request();

        AuditLog::query()->create([
            'action' => $action,
            'target_type' => 'hub',
            'user_id' => $actorUserId,
            'options' => ['site_id' => $domain, 'status' => $outcome],
            'ip_address' => $request?->ip(),
            'agent' => $request?->userAgent(),
            'url' => $request === null ? null : mb_substr($request->fullUrl(), 0, 1000),
        ]);
    }

    /**
     * Call the signed hub connect endpoint with the submitted pair.
     *
     * @return array{status: string, reason: ?string}|string The hub answer, or an OUTCOME_* failure.
     */
    private function callHub(string $domain, #[\SensitiveParameter] string $secret): array|string
    {
        $payload = [];
        $version = config('ispa-cms.version');
        if (is_string($version) && $version !== '') {
            $payload['cms_version'] = $version;
        }

        try {
            $client = new PlatformHubClient($domain, $secret, null, 3, 20);
            $answer = $client->post(self::CONNECT_PATH, $payload);
        } catch (PlatformHubException $e) {
            return $e->reason() === PlatformHubException::HTTP_STATUS && in_array($e->status(), [401, 403], true)
                ? self::OUTCOME_REFUSED
                : self::OUTCOME_HUB_UNREACHABLE;
        }

        $status = $answer['status'] ?? null;
        if ($status !== self::OUTCOME_CONNECTED && $status !== self::OUTCOME_PENDING) {
            return self::OUTCOME_HUB_UNREACHABLE;
        }

        return ['status' => $status, 'reason' => is_string($answer['reason'] ?? null) ? $answer['reason'] : null];
    }

    /**
     * A bare lowercase host from what the Webmaster typed (a pasted address is trimmed to its host), or null.
     */
    private function normalizeDomain(string $siteId): ?string
    {
        $value = strtolower(trim($siteId));
        $value = preg_replace('#^https?://#', '', $value) ?? '';
        $value = rtrim($value, '/');

        return preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * One result row with the plain-sentence key the connect screen shows.
     *
     * @return array{outcome: string, message_key: string}
     */
    private function outcome(string $outcome, ?string $reason = null): array
    {
        $key = match (true) {
            $outcome === self::OUTCOME_PENDING && $reason !== null => 'text.site_connect.reason.' . $reason,
            default => 'text.site_connect.' . $outcome,
        };

        return ['outcome' => $outcome, 'message_key' => $key];
    }
}
