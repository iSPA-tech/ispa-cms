<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Throwable;

/**
 * The one decision on an API credential: the token was found and is not expired (checked by
 * {@see SanctumRequestUserResolver::bearerToken()} in every mode), its owner is active, it was issued for one of the
 * audiences the route accepts, and it was used within the idle timeout of its audience.
 *
 * The outcome depends on the site's enforcement mode (`security.token_settings.mode`,):
 * - `off`: today's behaviour, every token that authenticates is allowed and nothing is counted;
 * - `log` (default): a token that breaks a rule is allowed, counted per day and reason and logged at most once an hour per
 *   token and reason;
 * - `enforce`: such a token is refused.
 *
 * A token without audience (issued before tokens carried an audience) is "legacy": counted in `log`, refused in `enforce`. A request without a
 * bearer token (browser session, Sanctum's transient token) counts as the pseudo audience {@see self::SESSION}, which a route
 * accepts only when it lists it. Nothing here stores, logs or caches a plain token or its hash: counters carry the context and
 * the reason, the log line the token id and its audience.
 *
 * Cost: the settings are cached for {@see self::SETTINGS_TTL} seconds (no query per request; when the cache store fails they
 * are read from the database, so an outage never weakens the mode); the token and its owner are the rows Sanctum already
 * loaded. A counter write happens only for a token that breaks a rule, in the cache store.
 *
 * @package App\Support
 * @(2026-11-11)
 */
final class TokenGate
{
    /** Outcome: the credential passes every rule. */
    public const ALLOW = 'allow';

    /** Outcome: the credential breaks a rule and the site is in `log` mode, so it is allowed and counted. */
    public const LOG = 'log';

    /** Outcome: the credential is refused. */
    public const DENY = 'deny';

    /** Pseudo audience of a request authenticated by a browser session instead of a bearer token. */
    public const SESSION = 'session';

    /** Reason: no usable token or owner; refused in every mode. */
    public const REASON_MISSING = 'missing';

    /** Reason: the owner account is locked, inactive or otherwise not active. */
    public const REASON_INACTIVE_OWNER = 'inactive_owner';

    /** Reason: the token was issued for another API family. */
    public const REASON_AUDIENCE = 'audience';

    /** Reason: the token carries no audience (issued before tokens carried an audience). */
    public const REASON_LEGACY = 'legacy';

    /** Reason: the token was not used within the idle timeout of its audience. */
    public const REASON_IDLE = 'idle';

    /** Reason: a browser session reached a route that accepts bearer tokens only. */
    public const REASON_SESSION = 'session';

    /** Every reason a counter exists for. */
    public const REASONS = [
        self::REASON_MISSING,
        self::REASON_INACTIVE_OWNER,
        self::REASON_AUDIENCE,
        self::REASON_LEGACY,
        self::REASON_IDLE,
        self::REASON_SESSION,
    ];

    /** Callers that ask the gate, used as the counter context. */
    public const CONTEXTS = ['api.admin', 'api.audience', 'resolver'];

    /** Cache key of the settings snapshot the gate reads; {@see TokenSettings::update()} forgets it. */
    public const SETTINGS_CACHE_KEY = 'token-gate:settings';

    /** Seconds the settings snapshot is kept. */
    public const SETTINGS_TTL = 60;

    /** Days a daily counter is kept. */
    private const COUNTER_DAYS = 35;

    /** Statuses of an active account: unset (older staff rows) or the active enum key. */
    private const ACTIVE_STATUSES = ['', 'active'];

    /**
     * Decide on the credential of a request.
     *
     * With a bearer token the token is the credential. Without one, or when
     * `$followGuard` is set and Sanctum authenticated the request by a browser session, the session user is the credential.
     *
     * @param Request $request The incoming request.
     * @param list<string> $audiences Audiences the route accepts, optionally including {@see self::SESSION}.
     * @param string $context One of {@see self::CONTEXTS}, used for the counters and the log line.
     * @param bool $followGuard Use the identity Sanctum's guard already chose for the request (route middleware after
     *                          `auth:sanctum`).
     * @return array{outcome: string, reason: ?string, owner: mixed, token: ?PersonalAccessToken}
     */
    public function forRequest(Request $request, array $audiences, string $context, bool $followGuard = false): array
    {
        if ($followGuard) {
            $user = $request->user();
            $current = $user !== null && method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
            if ($current instanceof PersonalAccessToken && $current->exists) {
                return $this->check($user, $current, $audiences, $context);
            }
            if ($user !== null && ($current === null || $current instanceof TransientToken || ! $request->bearerToken())) {
                return $this->check($user, null, $audiences, $context);
            }
        }

        if ($request->bearerToken()) {
            $token = SanctumRequestUserResolver::bearerToken($request);

            return $token === null
                ? $this->result(self::DENY, self::REASON_MISSING, null, null)
                : $this->check($token->tokenable, $token, $audiences, $context);
        }

        return $this->check(SanctumRequestUserResolver::sessionUser($request), null, $audiences, $context);
    }

    /**
     * Decide on a known owner and token (null token = browser session).
     *
     * @param mixed $owner The token owner or the session user.
     * @param PersonalAccessToken|null $token The token, already found and checked for expiry.
     * @param list<string> $audiences Audiences the caller accepts, optionally including {@see self::SESSION}.
     * @param string $context One of {@see self::CONTEXTS}.
     * @return array{outcome: string, reason: ?string, owner: mixed, token: ?PersonalAccessToken}
     */
    public function check(mixed $owner, ?PersonalAccessToken $token, array $audiences, string $context): array
    {
        if ($owner === null) {
            return $this->result(self::DENY, self::REASON_MISSING, null, $token);
        }

        $mode = $this->mode();
        if ($mode === 'off') {
            return $this->result(self::ALLOW, null, $owner, $token);
        }

        $reason = $this->violation($owner, $token, $audiences);
        if ($reason === null) {
            return $this->result(self::ALLOW, null, $owner, $token);
        }

        $this->record($context, $reason, $owner, $token, $audiences, $mode);

        return $this->result($mode === 'enforce' ? self::DENY : self::LOG, $reason, $owner, $token);
    }

    /**
     * The enforcement mode of the site: off, log or enforce.
     */
    public function mode(): string
    {
        $mode = $this->settings()['mode'] ?? 'log';

        return in_array($mode, TokenSettings::MODES, true) ? $mode : 'log';
    }

    /**
     * The error code a refused request carries, so a client can tell "sign in again" from "wrong API".
     *
     * @param string|null $reason One of {@see self::REASONS}.
     */
    public static function errorCode(?string $reason): string
    {
        return 'TOKEN_' . strtoupper($reason ?? self::REASON_MISSING);
    }

    /**
     * Counters of one day: context => reason => hits. Only non-zero entries are listed.
     *
     * @param string $date Day as `Y-m-d`.
     * @return array<string, array<string, int>>
     */
    public static function hits(string $date): array
    {
        $out = [];
        foreach (self::CONTEXTS as $context) {
            foreach (self::REASONS as $reason) {
                $count = (int) Cache::get(self::counterKey($date, $context, $reason), 0);
                if ($count > 0) {
                    $out[$context][$reason] = $count;
                }
            }
        }

        return $out;
    }

    /**
     * Whether an account may still use its credentials: its status is unset or active. Owners that are not platform users
     * (game players) are judged by their own module.
     */
    public static function ownerIsActive(mixed $owner): bool
    {
        if (! $owner instanceof User) {
            return true;
        }

        $status = (string) ($owner->getAttribute('status') ?? '');

        return in_array($status, [...self::ACTIVE_STATUSES, UserStatusEnum::USER_ACTIVE->value], true);
    }

    /**
     * The first rule the credential breaks, or null.
     *
     * @param list<string> $audiences
     */
    private function violation(mixed $owner, ?PersonalAccessToken $token, array $audiences): ?string
    {
        if (! self::ownerIsActive($owner)) {
            return self::REASON_INACTIVE_OWNER;
        }

        if ($token === null) {
            return in_array(self::SESSION, $audiences, true) ? null : self::REASON_SESSION;
        }

        $audience = $token->getAttribute('audience');
        if ($audience === null || $audience === '') {
            return self::REASON_LEGACY;
        }

        if (! in_array($audience, $audiences, true)) {
            return self::REASON_AUDIENCE;
        }

        return $this->isIdle($token, (string) $audience) ? self::REASON_IDLE : null;
    }

    /**
     * Whether the token was last used (before this request) longer ago than the idle timeout of its audience. Sanctum's
     * guard has already written `last_used_at` for this request, so the value before that write is read from the model's
     * previous attributes.
     */
    private function isIdle(PersonalAccessToken $token, string $audience): bool
    {
        $days = (int) ($this->settings()['idle_days'][$audience] ?? 0);
        if ($days <= 0) {
            return false;
        }

        $previous = $token->getPrevious();
        $lastUsed = array_key_exists('last_used_at', $previous) ? $previous['last_used_at'] : $token->getAttribute('last_used_at');
        $reference = $lastUsed ?? $token->getAttribute('created_at');
        if ($reference === null) {
            return false;
        }

        return Carbon::parse($reference)->lt(now()->subDays($days));
    }

    /**
     * Count the hit and log it at most once an hour per token (or session user) and reason. Never fails the request.
     *
     * @param list<string> $audiences
     */
    private function record(string $context, string $reason, mixed $owner, ?PersonalAccessToken $token, array $audiences, string $mode): void
    {
        try {
            $key = self::counterKey(now()->format('Y-m-d'), $context, $reason);
            Cache::add($key, 0, now()->addDays(self::COUNTER_DAYS));
            Cache::increment($key);

            $subject = $token !== null ? 'token:' . $token->getKey() : 'session:' . (is_object($owner) && method_exists($owner, 'getKey') ? $owner->getKey() : '?');
            if (Cache::add('token-gate:logged:' . $reason . ':' . $subject, 1, 3600)) {
                Log::notice('API token gate: ' . $reason, [
                    'mode' => $mode,
                    'context' => $context,
                    'reason' => $reason,
                    'token_id' => $token?->getKey(),
                    'token_audience' => $token?->getAttribute('audience'),
                    'accepted' => implode(',', $audiences),
                    'owner_id' => is_object($owner) && method_exists($owner, 'getKey') ? $owner->getKey() : null,
                ]);
            }
        } catch (Throwable) {
            // Counting is best effort; the decision stands.
        }
    }

    /**
     * The settings the gate needs, cached for {@see self::SETTINGS_TTL} seconds.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        try {
            $settings = Cache::remember(self::SETTINGS_CACHE_KEY, self::SETTINGS_TTL, static fn (): array => app(TokenSettings::class)->all());
        } catch (Throwable) {
            // A cache outage must not turn `enforce` into the default `log`: read the stored settings directly.
            try {
                $settings = app(TokenSettings::class)->all();
            } catch (Throwable) {
                $settings = null;
            }
        }

        return is_array($settings) ? $settings : TokenSettings::defaults();
    }

    /**
     * Cache key of one daily counter.
     */
    private static function counterKey(string $date, string $context, string $reason): string
    {
        return 'token-gate:hits:' . $date . ':' . $context . ':' . $reason;
    }

    /**
     * @return array{outcome: string, reason: ?string, owner: mixed, token: ?PersonalAccessToken}
     */
    private function result(string $outcome, ?string $reason, mixed $owner, ?PersonalAccessToken $token): array
    {
        return ['outcome' => $outcome, 'reason' => $reason, 'owner' => $owner, 'token' => $token];
    }
}
