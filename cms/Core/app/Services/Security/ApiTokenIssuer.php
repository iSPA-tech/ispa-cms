<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Notifications\NewDeviceSignInNotification;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Jenssegers\Agent\Agent;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

/**
 * The one place that issues API tokens.
 *
 * A token gets an audience from the closed list, a coarse device record taken from the request it is issued for (IPv4
 * /24 or IPv6 /64 network and a short browser or device label, decision), and an expiry from TokenPolicy. Per
 * account and audience at most TokenPolicy::maxLiveTokens() live tokens are kept: a device signing in again replaces
 * its own token, and beyond the cap the oldest live tokens are revoked first. The plain token is returned once and is
 * never logged, audited or cached here.
 *
 * The request is an argument, never read from the container, so queue jobs and console commands can issue tokens.
 *
 * @package Cms\Core\Services\Security
 */
class ApiTokenIssuer
{
    /** Longest device label kept; the column holds 255 characters. */
    private const LABEL_MAX = 80;

    /** Longest issuing-flow value; the column holds 64 characters. */
    private const ISSUED_VIA_MAX = 64;

    /**
     * @param TokenPolicy $policy Lifetime and cap per audience.
     */
    public function __construct(
        private readonly TokenPolicy $policy,
        private readonly ?TokenSettings $settings = null,
        private readonly ?NewDeviceDetector $detector = null,
    ) {
    }

    /**
     * Issue a token for an account.
     *
     * @param Model $user Token owner; must use Laravel\Sanctum\HasApiTokens.
     * @param string $audience One of TokenAudience.
     * @param string $name Token name (usually the device name the client sent).
     * @param array<int, string> $abilities Sanctum abilities, `['*']` for all.
     * @param Request|null $request Request the token is issued for; null in jobs and commands (no device record).
     * @param DateTimeInterface|null $expiresAt Expiry the caller asks for; the audience lifetime caps it.
     * @param string|null $issuedVia Issuing flow, for example `admin.mfa` or `account.login` (at most 64 characters).
     * @return NewAccessToken The stored token and the plain text token (`<id>|<secret>`), shown once.
     * @throws InvalidArgumentException When the audience is unknown or the owner cannot hold API tokens.
     */
    public function issue(
        Model $user,
        string $audience,
        string $name,
        array $abilities = ['*'],
        ?Request $request = null,
        ?DateTimeInterface $expiresAt = null,
        ?string $issuedVia = null,
    ): NewAccessToken {
        TokenAudience::assertValid($audience);
        if (! method_exists($user, 'tokens') || ! method_exists($user, 'generateTokenString')) {
            throw new InvalidArgumentException('TOKEN_OWNER_HAS_NO_API_TOKENS');
        }

        $plainTextToken = $user->generateTokenString();
        $attributes = [
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $this->policy->expiresAt($audience, $expiresAt),
        ];

        $tracked = ApiAccessToken::hasAudienceColumns();
        $device = null;
        if ($tracked) {
            $device = [
                'ip_address' => $request !== null ? self::networkLabel($request->ip()) : null,
                'user_agent' => $request !== null ? self::deviceLabel($request->userAgent()) : null,
            ];
            $attributes += $device + [
                'audience' => $audience,
                'issued_via' => $issuedVia !== null ? mb_substr($issuedVia, 0, self::ISSUED_VIA_MAX) : null,
            ];
        }

        $isNewDevice = $device !== null && $this->isNewDevice($user, $device);

        /** @var ApiAccessToken $token */
        $token = DB::connection((new ApiAccessToken())->getConnectionName())->transaction(
            function () use ($user, $audience, $name, $attributes, $device): ApiAccessToken {
                if ($device !== null) {
                    $this->replaceSameDevice($user, $audience, $name, $device);
                    $this->enforceCap($user, $audience);
                }

                return $user->tokens()->create($attributes);
            }
        );

        if ($isNewDevice) {
            $this->notifyNewDevice($user, $device['user_agent']);
        }

        return new NewAccessToken($token, $token->getKey() . '|' . $plainTextToken);
    }

    /**
     * Whether the setting is on and the device record is new for the account (decided before the token is stored).
     *
     * @param Model $user Token owner.
     * @param array{ip_address: string|null, user_agent: string|null} $device Device record of the new token.
     * @return bool
     */
    private function isNewDevice(Model $user, array $device): bool
    {
        try {
            return ($this->settings ?? new TokenSettings())->notifyNewDevice()
                && ($this->detector ?? new NewDeviceDetector())->isNewDevice($user, $device['ip_address'], $device['user_agent']);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Queue the new-device notice; a failure never breaks the sign-in. The notice carries the device label and the
     * time only.
     *
     * @param Model $user Token owner.
     * @param string|null $deviceLabel Short device label.
     * @return void
     */
    private function notifyNewDevice(Model $user, ?string $deviceLabel): void
    {
        try {
            if (method_exists($user, 'notify')) {
                $user->notify(new NewDeviceSignInNotification($deviceLabel, Carbon::now()->utc()->toIso8601String()));
            }
        } catch (Throwable) {
            // The notice is optional; never fail a sign-in because of it.
        }
    }

    /**
     * Coarse network label of an address: IPv4 /24 or IPv6 /64 (an IPv4-mapped IPv6 address counts as IPv4).
     *
     * @param string|null $ip Address as given by the request.
     * @return string|null For example `203.0.113.0/24` or `2001:db8:1:2::/64`; null when missing or invalid.
     */
    public static function networkLabel(?string $ip): ?string
    {
        $packed = $ip !== null && $ip !== '' ? @inet_pton($ip) : false;
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        if (strlen($packed) === 4) {
            return inet_ntop(substr($packed, 0, 3) . "\0") . '/24';
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * Short browser or device label of a user agent: "<browser> on <platform>" when recognised, otherwise the product
     * name the client sends first (for example an app name). The raw header is never returned.
     *
     * @param string|null $userAgent Raw User-Agent header.
     * @return string|null At most 80 characters; null when nothing usable is sent.
     */
    public static function deviceLabel(?string $userAgent): ?string
    {
        $userAgent = trim((string) $userAgent);
        if ($userAgent === '') {
            return null;
        }
        $userAgent = mb_substr($userAgent, 0, 1024);

        $agent = new Agent();
        $agent->setUserAgent($userAgent);
        $browser = self::cleanLabel((string) ($agent->browser() ?: ''));
        $platform = self::cleanLabel((string) ($agent->platform() ?: ''));

        $label = match (true) {
            $browser !== '' && $platform !== '' => $browser . ' on ' . $platform,
            $browser !== '' => $browser,
            $platform !== '' => $platform,
            default => self::cleanLabel((string) strtok($userAgent, '/ ;(')),
        };

        return $label !== '' ? mb_substr($label, 0, self::LABEL_MAX) : null;
    }

    /**
     * Keep letters, digits, spaces, dots, underscores and dashes, at most 40 characters.
     *
     * @param string $value Raw fragment.
     * @return string
     */
    private static function cleanLabel(string $value): string
    {
        return trim(mb_substr((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $value), 0, 40));
    }

    /**
     * Revoke the account's tokens of the same audience issued to the same device (same name, device label and
     * network). Tokens without a device label never match each other.
     *
     * @param Model $user Token owner.
     * @param string $audience Audience of the new token.
     * @param string $name Name of the new token.
     * @param array{ip_address: string|null, user_agent: string|null} $device Device record of the new token.
     * @return void
     */
    private function replaceSameDevice(Model $user, string $audience, string $name, array $device): void
    {
        if ($device['user_agent'] === null) {
            return;
        }

        $this->ownedTokens($user, $audience)
            ->where('name', $name)
            ->where('user_agent', $device['user_agent'])
            ->when(
                $device['ip_address'] === null,
                static fn (Builder $query) => $query->whereNull('ip_address'),
                static fn (Builder $query) => $query->where('ip_address', $device['ip_address']),
            )
            ->delete();
    }

    /**
     * Keep room for one more live token: revoke the oldest live tokens of the account and audience beyond the cap.
     * Expired tokens do not count and are left to the cleanup.
     *
     * @param Model $user Token owner.
     * @param string $audience Audience of the new token.
     * @return void
     */
    private function enforceCap(Model $user, string $audience): void
    {
        $keep = $this->policy->maxLiveTokens($audience) - 1;
        $live = $this->ownedTokens($user, $audience)
            ->where(static fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));

        $excess = (clone $live)->count() - $keep;
        if ($excess <= 0) {
            return;
        }

        $oldest = (clone $live)->orderBy('created_at')->orderBy('id')->limit($excess)->pluck('id')->all();
        ApiAccessToken::query()->whereKey($oldest)->delete();
    }

    /**
     * Query of the account's tokens of one audience.
     *
     * @param Model $user Token owner.
     * @param string $audience Audience to match.
     * @return Builder<ApiAccessToken>
     */
    private function ownedTokens(Model $user, string $audience): Builder
    {
        return ApiAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->where('audience', $audience);
    }
}
