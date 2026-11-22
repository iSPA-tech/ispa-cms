<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Lifetime and per-account cap of API tokens, per audience.
 *
 * Values come from `TokenSettings`. With nothing saved they are today's behaviour: no lifetime (a token
 * lives as long as the caller asked, "never" when it asked nothing) and the default cap of 50 live tokens per account
 * and audience. Constructor arguments override the saved settings (used by tests).
 *
 * @package Cms\Core\Services\Security
 */
class TokenPolicy
{
    /** Plan default: at most this many live tokens per account and audience. */
    public const DEFAULT_MAX_LIVE_TOKENS = 50;

    /**
     * @param array<string, int|null>|null $lifetimeMinutes Override: lifetime in minutes per audience; a missing or
     *                                                      null entry means "never expires". Null reads the settings.
     * @param int|null $maxLiveTokens Override: live tokens kept per account and audience. Null reads the settings.
     * @param TokenSettings|null $settings Saved settings (resolved lazily when omitted).
     */
    public function __construct(
        private readonly ?array $lifetimeMinutes = null,
        private readonly ?int $maxLiveTokens = null,
        private ?TokenSettings $settings = null,
    ) {
    }

    /**
     * Saved settings, created on first use.
     *
     * @return TokenSettings
     */
    private function settings(): TokenSettings
    {
        return $this->settings ??= new TokenSettings();
    }

    /**
     * Idle timeout of an audience in days (the check itself belongs to the enforcement tasks).
     *
     * @param string $audience One of TokenAudience.
     * @return int 0 when no idle timeout applies.
     */
    public function idleDays(string $audience): int
    {
        return $this->settings()->idleDays($audience);
    }

    /**
     * Lifetime of new tokens of an audience.
     *
     * @param string $audience One of TokenAudience.
     * @return int|null Minutes, or null when tokens of this audience never expire.
     */
    public function lifetimeMinutes(string $audience): ?int
    {
        $minutes = $this->lifetimeMinutes !== null
            ? ($this->lifetimeMinutes[$audience] ?? null)
            : $this->settings()->lifetimeMinutes($audience);

        return $minutes !== null && $minutes > 0 ? (int) $minutes : null;
    }

    /**
     * Maximum number of live tokens one account may hold in an audience.
     *
     * @param string $audience One of TokenAudience.
     * @return int At least 1.
     */
    public function maxLiveTokens(string $audience): int
    {
        return max(1, $this->maxLiveTokens ?? $this->settings()->maxLiveTokens());
    }

    /**
     * Expiry of a token issued now: the earlier of the caller's request and the audience lifetime.
     *
     * @param string $audience One of TokenAudience.
     * @param DateTimeInterface|null $requested Expiry the caller asked for, or null for none.
     * @return Carbon|null Null when neither the caller nor the policy sets an expiry.
     */
    public function expiresAt(string $audience, ?DateTimeInterface $requested): ?Carbon
    {
        $requested = $requested !== null ? Carbon::instance($requested) : null;
        $minutes = $this->lifetimeMinutes($audience);
        if ($minutes === null) {
            return $requested;
        }

        $ceiling = Carbon::now()->addMinutes($minutes);

        return $requested !== null && $requested->lessThan($ceiling) ? $requested : $ceiling;
    }
}
