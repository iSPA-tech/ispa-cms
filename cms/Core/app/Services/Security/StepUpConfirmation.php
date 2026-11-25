<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Models\UserMfaFactor;
use Cms\Core\Models\UserMfaRecoveryCode;
use Cms\Core\Notifications\AdminNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Throwable;

/**
 * Step-up confirmation before a token is revoked.
 *
 * The caller proves who they are again: the account password, then each second factor the account has (authenticator
 * code, e-mail code, or a recovery code in place of the authenticator), or only an e-mail code when the account has no
 * password (social-first accounts). The result is a grant: an opaque 40-character value, stored only as a SHA-256 hash in
 * the cache, bound to the user, to the id of the token that made the request and to a purpose, valid for
 * `core.token_security.stepup.minutes`. The revoke routes read it from the `X-Step-Up-Grant` header through
 * {@see \Cms\Core\Http\Middleware\RequireStepUpGrant}; bulk routes consume it.
 *
 * Why no `AuthChallenge` row: the admin sign-in endpoints (`auth/two-fa-verify`, `auth/challenge`,
 * `auth/recovery-code`) accept any pending challenge by uuid, or the latest one by e-mail address, and mint an admin
 * token when it completes. A step-up challenge stored there would become a second sign-in path that skips the
 * account-wide counter. The pending second-factor state therefore lives in the cache, bound to the user and the token,
 * and only the verification rules are shared: the second-factor mode from {@see AdminMfaService::resolveRequiredMethods()},
 * authenticator codes through {@see TotpService}, recovery codes through the hashes {@see AdminMfaService} stores, and
 * e-mail codes through the same {@see SendCodeVerify} mail.
 *
 * Guessing is stopped by {@see StepUpThrottle} (account-wide counter, lock and escalation, e-mail limit). A wrong password
 * and a wrong code answer the same 422. No password, code, grant or hash ever reaches a response other than the grant
 * in the response that issues it, a log or an audit row; audit events carry counts and the user uuid only.
 *
 * @package Cms\Core\Services\Security
 */
final class StepUpConfirmation
{
    public const PURPOSE_REVOKE_TOKENS = 'revoke_tokens';
    /** Adding, changing or deleting a workflow credential (guardrails B6). */
    public const PURPOSE_WORKFLOW_CREDENTIAL = 'workflow_credential';
    /** Publishing or approving a sensitive workflow change (guardrails B3). */
    public const PURPOSE_WORKFLOW_PUBLISH = 'workflow_publish';
    public const PURPOSES = [self::PURPOSE_REVOKE_TOKENS, self::PURPOSE_WORKFLOW_CREDENTIAL, self::PURPOSE_WORKFLOW_PUBLISH];
    public const HEADER = 'X-Step-Up-Grant';

    public const METHOD_PASSWORD = 'password';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SECOND_FACTOR = 'second_factor_required';

    private const CHALLENGE_PREFIX = 'security:stepup:challenge:';
    private const GRANT_PREFIX = 'security:stepup:grant:';
    private const USED_PREFIX = 'security:stepup:grant-used:';

    /**
     * @param AdminMfaService $mfa Second-factor mode of an account.
     * @param TotpService $totp Authenticator code check.
     * @param StepUpThrottle $throttle Account-wide counters.
     */
    public function __construct(
        private readonly AdminMfaService $mfa,
        private readonly TotpService $totp,
        private readonly StepUpThrottle $throttle,
    ) {
    }

    /**
     * What the account needs to confirm, and whether it is locked. Reveals no secret and no e-mail address.
     *
     * @param User $user Signed-in account.
     * @param HasAbilities|null $token Token of the request.
     * @return array<string, mixed>
     * @throws StepUpException Cache failure (503).
     */
    public function status(User $user, ?HasAbilities $token): array
    {
        $possible = $this->tokenIdOrNull($token) !== null;
        $methods = [];
        if ($possible) {
            try {
                $methods = $this->requiredSecondFactors($user);
            } catch (StepUpException) {
                $possible = false;
            }
        }
        $retryAfter = $this->throttle->retryAfter($user);

        return [
            'possible' => $possible,
            'password_required' => $this->hasPassword($user),
            'second_factor' => $methods,
            'can_use_recovery_code' => in_array(AdminMfaService::METHOD_TOTP, $methods, true) && $this->hasUnusedRecoveryCode($user),
            'locked' => $retryAfter !== null,
            'retry_after' => $retryAfter,
            'grant_minutes' => $this->grantMinutes(),
        ];
    }

    /**
     * Start a confirmation: check the password (accounts that have one), then either issue the grant or open the
     * second-factor step. An account without a password goes straight to an e-mail code.
     *
     * @param User $user Signed-in account.
     * @param HasAbilities|null $token Token of the request.
     * @param string|null $password Password as typed.
     * @param string $purpose Grant purpose.
     * @return array<string, mixed> Grant payload or second-factor payload.
     * @throws StepUpException
     */
    public function start(User $user, ?HasAbilities $token, ?string $password, string $purpose = self::PURPOSE_REVOKE_TOKENS): array
    {
        $tokenId = $this->tokenId($token);
        $purpose = $this->purpose($purpose);
        $this->throttle->assertNotLocked($user);
        $methods = $this->requiredSecondFactors($user);

        if ($this->hasPassword($user)) {
            if ($password === null || $password === '') {
                throw StepUpException::passwordRequired();
            }
            $this->throttle->begin($user);
            if (! $this->passwordMatches($user, $password)) {
                $this->fail($user, self::METHOD_PASSWORD);
            }
            $this->throttle->succeeded($user);
        }

        if ($methods === []) {
            return $this->confirm($user, $tokenId, $purpose);
        }

        return $this->openChallenge($user, $tokenId, $purpose, $methods);
    }

    /**
     * Send or resend an e-mail code. With a challenge id: a new code for that confirmation when its current step is the
     * e-mail code. Without one: start the confirmation of an account that has no password.
     *
     * @param User $user Signed-in account.
     * @param HasAbilities|null $token Token of the request.
     * @param string|null $challengeId Open confirmation.
     * @param string $purpose Grant purpose (only used when starting).
     * @return array<string, mixed> Second-factor payload.
     * @throws StepUpException
     */
    public function sendEmailCode(User $user, ?HasAbilities $token, ?string $challengeId, string $purpose = self::PURPOSE_REVOKE_TOKENS): array
    {
        $tokenId = $this->tokenId($token);
        $this->throttle->assertNotLocked($user);

        if ($challengeId === null || $challengeId === '') {
            if ($this->hasPassword($user)) {
                throw StepUpException::passwordRequired();
            }

            return $this->start($user, $token, null, $purpose);
        }

        $record = $this->loadChallenge($user, $tokenId, $challengeId);
        if ($record === null || $this->currentMethod($record) !== AdminMfaService::METHOD_EMAIL_CODE) {
            throw StepUpException::failed();
        }
        $this->sendCode($user, $challengeId, $record);
        $this->saveChallenge($challengeId, $record);

        return $this->challengePayload($user, $challengeId, $record);
    }

    /**
     * Check the code of the current second-factor step (or a recovery code in place of the authenticator). Completes
     * the confirmation with a grant, or moves to the next step.
     *
     * @param User $user Signed-in account.
     * @param HasAbilities|null $token Token of the request.
     * @param string $challengeId Open confirmation.
     * @param string $code Code as typed.
     * @param string|null $method `recovery_code` to use a recovery code; otherwise the current step's method.
     * @return array<string, mixed> Grant payload or the next second-factor payload.
     * @throws StepUpException
     */
    public function verifySecondFactor(User $user, ?HasAbilities $token, string $challengeId, string $code, ?string $method = null): array
    {
        $tokenId = $this->tokenId($token);
        $this->throttle->begin($user);

        $record = $this->loadChallenge($user, $tokenId, $challengeId);
        $current = $record !== null ? $this->currentMethod($record) : null;
        if ($record === null || $current === null) {
            $this->fail($user, 'second_factor');
        }

        $using = $method === AdminMfaService::METHOD_RECOVERY_CODE ? AdminMfaService::METHOD_RECOVERY_CODE : $current;
        $ok = match ($using) {
            AdminMfaService::METHOD_EMAIL_CODE => $this->checkEmailCode($challengeId, $record, $code),
            AdminMfaService::METHOD_TOTP => $this->checkTotp($user, $code),
            AdminMfaService::METHOD_RECOVERY_CODE => $current === AdminMfaService::METHOD_TOTP && $this->useRecoveryCode($user, $code),
            default => false,
        };
        if (! $ok) {
            if ($using === AdminMfaService::METHOD_EMAIL_CODE) {
                $this->saveChallenge($challengeId, $record);
            }
            $this->fail($user, 'second_factor');
        }
        $this->throttle->succeeded($user);

        $record['completed'][] = $current;
        $next = $this->currentMethod($record);
        if ($next === null) {
            $this->forgetChallenge($challengeId);

            return $this->confirm($user, $tokenId, (string) $record['purpose']);
        }
        if ($next === AdminMfaService::METHOD_EMAIL_CODE) {
            $this->sendCode($user, $challengeId, $record);
        }
        $this->saveChallenge($challengeId, $record);

        return $this->challengePayload($user, $challengeId, $record);
    }

    /**
     * Whether the grant is valid for this user, this token and this purpose; with `$consume` it is used up atomically
     * (a second use fails even when two requests race).
     *
     * @param User $user Signed-in account.
     * @param HasAbilities|null $token Token of the request.
     * @param string|null $grant Grant value from the header.
     * @param string $purpose Purpose the route needs.
     * @param bool $consume Use the grant up.
     * @return bool
     * @throws StepUpException Cache failure (503).
     */
    public function checkGrant(User $user, ?HasAbilities $token, ?string $grant, string $purpose, bool $consume): bool
    {
        $tokenId = $this->tokenIdOrNull($token);
        if ($tokenId === null || $grant === null || strlen($grant) !== 40 || ! in_array($purpose, self::PURPOSES, true)) {
            return false;
        }

        $hash = hash('sha256', $grant);

        return $this->cache(function ($cache) use ($hash, $user, $tokenId, $purpose, $consume): bool {
            $record = $cache->get(self::GRANT_PREFIX . $hash);
            if (! is_array($record)
                || ($record['user_id'] ?? null) !== (int) $user->getKey()
                || ($record['token_id'] ?? null) !== $tokenId
                || ($record['purpose'] ?? null) !== $purpose
                || (int) ($record['expires_at'] ?? 0) <= Carbon::now()->getTimestamp()
                || $cache->has(self::USED_PREFIX . $hash)) {
                return false;
            }
            if (! $consume) {
                return true;
            }
            $ttl = max(1, (int) $record['expires_at'] - Carbon::now()->getTimestamp());
            if (! $cache->add(self::USED_PREFIX . $hash, 1, $ttl)) {
                return false;
            }
            $cache->forget(self::GRANT_PREFIX . $hash);

            return true;
        });
    }

    /**
     * Issue the grant and clear the failure counter.
     *
     * @param User $user Account.
     * @param int $tokenId Token the grant is bound to.
     * @param string $purpose Grant purpose.
     * @return array<string, mixed>
     * @throws StepUpException Cache failure (503).
     */
    private function confirm(User $user, int $tokenId, string $purpose): array
    {
        $grant = Str::random(40);
        $seconds = $this->grantMinutes() * 60;
        $expiresAt = Carbon::now()->addSeconds($seconds);
        $this->cache(fn ($cache): bool => $cache->put(self::GRANT_PREFIX . hash('sha256', $grant), [
            'user_id' => (int) $user->getKey(),
            'token_id' => $tokenId,
            'purpose' => $purpose,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $seconds));
        $this->throttle->clear($user);
        $this->audit('security.stepup.succeeded', $user, ['purpose' => $purpose], 'success');

        return [
            'status' => self::STATUS_CONFIRMED,
            'grant' => $grant,
            'header' => self::HEADER,
            'purpose' => $purpose,
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in' => $seconds,
        ];
    }

    /**
     * Open the second-factor step, sending the first e-mail code when that is the first method.
     *
     * @param User $user Account.
     * @param int $tokenId Token of the request.
     * @param string $purpose Grant purpose.
     * @param list<string> $methods Second-factor methods in order.
     * @return array<string, mixed>
     * @throws StepUpException
     */
    private function openChallenge(User $user, int $tokenId, string $purpose, array $methods): array
    {
        $id = (string) Str::uuid();
        $record = [
            'user_id' => (int) $user->getKey(),
            'token_id' => $tokenId,
            'purpose' => $purpose,
            'methods' => $methods,
            'completed' => [],
            'email_hash' => null,
            'email_expires_at' => null,
            'email_attempts' => 0,
            'expires_at' => Carbon::now()->addMinutes($this->setting('challenge_minutes', 10))->getTimestamp(),
        ];
        if ($this->currentMethod($record) === AdminMfaService::METHOD_EMAIL_CODE) {
            $this->sendCode($user, $id, $record);
        }
        $this->saveChallenge($id, $record);

        return $this->challengePayload($user, $id, $record);
    }

    /**
     * Send a new e-mail code within the hourly limit; the code is kept only as a keyed hash.
     *
     * @param User $user Account.
     * @param string $id Challenge id.
     * @param array<string, mixed> $record Challenge state (updated).
     * @return void
     * @throws StepUpException E-mail limit (429) or cache failure (503).
     */
    private function sendCode(User $user, string $id, array &$record): void
    {
        $this->throttle->reserveEmailSend($user);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $record['email_hash'] = $this->emailCodeHash($id, $code);
        $record['email_expires_at'] = Carbon::now()->addMinutes($this->setting('email_code_minutes', 10))->getTimestamp();
        $record['email_attempts'] = 0;

        event(new SendCodeVerify($user, $code, 'two_fa'));
    }

    /**
     * Check an e-mail code; counts the attempt on the code (the code dies after the configured number of attempts).
     *
     * @param string $id Challenge id.
     * @param array<string, mixed> $record Challenge state (updated).
     * @param string $code Code as typed.
     * @return bool
     */
    private function checkEmailCode(string $id, array &$record, string $code): bool
    {
        $record['email_attempts'] = (int) ($record['email_attempts'] ?? 0) + 1;
        $normalized = (string) preg_replace('/\D+/', '', $code);

        if (! is_string($record['email_hash'] ?? null)
            || (int) ($record['email_expires_at'] ?? 0) <= Carbon::now()->getTimestamp()
            || $record['email_attempts'] > $this->setting('email_code_attempts', 5)
            || strlen($normalized) !== 6) {
            return false;
        }

        if (! hash_equals($record['email_hash'], $this->emailCodeHash($id, $normalized))) {
            return false;
        }
        $record['email_hash'] = null;

        return true;
    }

    /**
     * Check an authenticator code against the enabled authenticator of the account.
     *
     * @param User $user Account.
     * @param string $code Code as typed.
     * @return bool
     */
    private function checkTotp(User $user, string $code): bool
    {
        $factor = $this->enabledTotpFactor($user);
        $counter = $factor !== null ? $this->totp->matchingCounter((string) $factor->secret, $code) : null;
        if ($counter === null) {
            return false;
        }

        // A code is accepted once: the factor's last use must lie before the time step of this code, and the
        // check and the write are one conditional update, so a replay (also of a code used at sign-in) or two
        // parallel requests with the same code get one success at most.
        $stepStart = Carbon::now()->setTimestamp($counter * 30);
        $now = Carbon::now();
        $claimed = UserMfaFactor::query()
            ->whereKey($factor->getKey())
            ->where(static fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $stepStart))
            ->update(['last_used_at' => $now, 'last_challenged_at' => $now]);

        return $claimed === 1;
    }

    /**
     * Use an unused recovery code of the enabled authenticator (same hash as {@see AdminMfaService}); it is spent.
     *
     * @param User $user Account.
     * @param string $code Code as typed.
     * @return bool
     */
    private function useRecoveryCode(User $user, string $code): bool
    {
        $factor = $this->enabledTotpFactor($user);
        if ($factor === null) {
            return false;
        }
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        if ($normalized === '') {
            return false;
        }

        $spent = UserMfaRecoveryCode::query()
            ->where('factor_id', $factor->getKey())
            ->where('code_hash', hash_hmac('sha256', $normalized, (string) config('app.key')))
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);
        if ($spent < 1) {
            return false;
        }
        $factor->forceFill(['last_used_at' => Carbon::now(), 'last_challenged_at' => Carbon::now()])->save();

        return true;
    }

    /**
     * Count a failed attempt, write the audit events, lock and notify when the limit is reached. Never returns.
     *
     * @param User $user Account.
     * @param string $step `password` or `second_factor`.
     * @return never
     * @throws StepUpException Failed (422) or locked (429).
     */
    private function fail(User $user, string $step): never
    {
        $result = $this->throttle->failed($user);
        $this->audit('security.stepup.failed', $user, ['step' => $step, 'failures' => $result['failures']], 'warning');

        if ($result['locked']) {
            if ($result['created']) {
                $minutes = (int) ceil(((int) $result['retry_after']) / 60);
                $this->audit('security.stepup.locked', $user, ['minutes' => $minutes, 'lockouts' => $result['lockouts']], 'danger');
                $this->notifyLocked($user, $minutes);
            }

            throw StepUpException::locked((int) $result['retry_after']);
        }

        throw StepUpException::failed();
    }

    /**
     * One in-app notice to the account owner that the confirmation is locked. A delivery failure never undoes the lock.
     *
     * @param User $user Account.
     * @param int $minutes Lock length.
     * @return void
     */
    private function notifyLocked(User $user, int $minutes): void
    {
        try {
            $user->notify(new AdminNotification('security.stepup.locked', ['minutes' => $minutes]));
        } catch (Throwable) {
            CMSLogger::warning(['message' => 'Step-up lock notice not delivered', 'type' => 'security.stepup.locked']);
        }
    }

    /**
     * Second-factor methods the account must complete after the password, in order. An account without a password
     * confirms with an e-mail code only. An account with an enabled authenticator always needs it, even when its
     * sign-in mode does not ask for it.
     *
     * @param User $user Account.
     * @return list<string>
     * @throws StepUpException Not possible (422) when the account cannot provide what its mode requires.
     */
    private function requiredSecondFactors(User $user): array
    {
        if (! $this->hasPassword($user)) {
            if (blank($user->email)) {
                throw StepUpException::notPossible();
            }

            return [AdminMfaService::METHOD_EMAIL_CODE];
        }

        try {
            $methods = $this->mfa->resolveRequiredMethods($user);
        } catch (RuntimeException) {
            throw StepUpException::notPossible();
        }
        if (! in_array(AdminMfaService::METHOD_TOTP, $methods, true) && $this->enabledTotpFactor($user) !== null) {
            $methods[] = AdminMfaService::METHOD_TOTP;
        }

        return array_values(array_unique($methods));
    }

    /**
     * Second-factor payload for the client (no code, no hash, no e-mail address).
     *
     * @param User $user Account.
     * @param string $id Challenge id.
     * @param array<string, mixed> $record Challenge state.
     * @return array<string, mixed>
     */
    private function challengePayload(User $user, string $id, array $record): array
    {
        $current = $this->currentMethod($record);

        return [
            'status' => self::STATUS_SECOND_FACTOR,
            'challenge_id' => $id,
            'method' => $current,
            'methods' => $record['methods'],
            'completed' => $record['completed'],
            'can_use_recovery_code' => $current === AdminMfaService::METHOD_TOTP && $this->hasUnusedRecoveryCode($user),
            'email_code_expires_at' => $current === AdminMfaService::METHOD_EMAIL_CODE && $record['email_expires_at']
                ? Carbon::createFromTimestamp((int) $record['email_expires_at'])->toIso8601String()
                : null,
            'expires_at' => Carbon::createFromTimestamp((int) $record['expires_at'])->toIso8601String(),
        ];
    }

    /**
     * Load an open confirmation of this user and this token, or null.
     *
     * @param User $user Account.
     * @param int $tokenId Token of the request.
     * @param string $id Challenge id.
     * @return array<string, mixed>|null
     * @throws StepUpException Cache failure (503).
     */
    private function loadChallenge(User $user, int $tokenId, string $id): ?array
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        $record = $this->cache(fn ($cache): mixed => $cache->get(self::CHALLENGE_PREFIX . hash('sha256', $id)));
        if (! is_array($record)
            || ($record['user_id'] ?? null) !== (int) $user->getKey()
            || ($record['token_id'] ?? null) !== $tokenId
            || (int) ($record['expires_at'] ?? 0) <= Carbon::now()->getTimestamp()) {
            return null;
        }

        return $record;
    }

    /**
     * Store a confirmation until it expires.
     *
     * @param string $id Challenge id.
     * @param array<string, mixed> $record Challenge state.
     * @return void
     * @throws StepUpException Cache failure (503).
     */
    private function saveChallenge(string $id, array $record): void
    {
        $ttl = max(1, (int) $record['expires_at'] - Carbon::now()->getTimestamp());
        $this->cache(fn ($cache): bool => $cache->put(self::CHALLENGE_PREFIX . hash('sha256', $id), $record, $ttl));
    }

    /**
     * Drop a completed confirmation.
     *
     * @param string $id Challenge id.
     * @return void
     * @throws StepUpException Cache failure (503).
     */
    private function forgetChallenge(string $id): void
    {
        $this->cache(fn ($cache): bool => $cache->forget(self::CHALLENGE_PREFIX . hash('sha256', $id)));
    }

    /**
     * First method of the confirmation that is not completed yet.
     *
     * @param array<string, mixed> $record Challenge state.
     * @return string|null
     */
    private function currentMethod(array $record): ?string
    {
        foreach ($record['methods'] ?? [] as $method) {
            if (! in_array($method, $record['completed'] ?? [], true)) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Enabled primary authenticator of the account, read fresh from the database.
     *
     * @param User $user Account.
     * @return UserMfaFactor|null
     */
    private function enabledTotpFactor(User $user): ?UserMfaFactor
    {
        return UserMfaFactor::query()
            ->where('user_id', $user->getKey())
            ->where('provider', UserMfaFactor::PROVIDER_TOTP)
            ->whereNotNull('verified_at')
            ->whereNotNull('enabled_at')
            ->whereNull('disabled_at')
            ->where('is_primary', true)
            ->latest('id')
            ->first();
    }

    /**
     * Whether the enabled authenticator has an unused recovery code.
     *
     * @param User $user Account.
     * @return bool
     */
    private function hasUnusedRecoveryCode(User $user): bool
    {
        $factor = $this->enabledTotpFactor($user);

        return $factor !== null && $factor->recoveryCodes()->whereNull('used_at')->exists();
    }

    /**
     * Whether the account has a password (social-first accounts start without one).
     *
     * @param User $user Account.
     * @return bool
     */
    private function hasPassword(User $user): bool
    {
        $password = $user->getAttributes()['password'] ?? null;

        return is_string($password) && $password !== '';
    }

    /**
     * Check the password; a hash the hasher cannot read counts as a mismatch.
     *
     * @param User $user Account.
     * @param string $password Password as typed.
     * @return bool
     */
    private function passwordMatches(User $user, string $password): bool
    {
        try {
            return Hash::check($password, (string) $user->getAttributes()['password']);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Id of the personal access token of the request; the confirmation needs one.
     *
     * @param HasAbilities|null $token Token of the request.
     * @return int
     * @throws StepUpException Not possible (422).
     */
    private function tokenId(?HasAbilities $token): int
    {
        return $this->tokenIdOrNull($token) ?? throw StepUpException::notPossible();
    }

    /**
     * Id of the personal access token of the request, or null (no token, or a transient session token).
     *
     * @param HasAbilities|null $token Token of the request.
     * @return int|null
     */
    private function tokenIdOrNull(?HasAbilities $token): ?int
    {
        if (! $token instanceof PersonalAccessToken || ! $token->exists || (int) $token->getKey() < 1) {
            return null;
        }

        return (int) $token->getKey();
    }

    /**
     * A known purpose; anything else is refused.
     *
     * @param string $purpose Requested purpose.
     * @return string
     * @throws StepUpException Not possible (422).
     */
    private function purpose(string $purpose): string
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw StepUpException::notPossible();
        }

        return $purpose;
    }

    /**
     * Keyed hash of an e-mail code, bound to its challenge.
     *
     * @param string $id Challenge id.
     * @param string $code Six digits.
     * @return string
     */
    private function emailCodeHash(string $id, string $code): string
    {
        return hash_hmac('sha256', 'stepup|' . $id . '|' . $code, (string) config('app.key'));
    }

    /**
     * Grant lifetime in minutes.
     *
     * @return int
     */
    private function grantMinutes(): int
    {
        return $this->setting('minutes', 5);
    }

    /**
     * A positive integer setting of `core.token_security.stepup`.
     *
     * @param string $key Setting name.
     * @param int $default Default value.
     * @return int
     */
    private function setting(string $key, int $default): int
    {
        return max(1, (int) config('core.token_security.stepup.' . $key, $default));
    }

    /**
     * Audit event with the user uuid and counts only.
     *
     * @param string $action Audit action.
     * @param User $user Account.
     * @param array<string, int|string> $counts Counts and labels without secrets.
     * @param string $color Audit colour.
     * @return void
     */
    private function audit(string $action, User $user, array $counts, string $color): void
    {
        event(new AuditLogEvent($action, ['user' => (string) $user->uuid] + $counts, User::class, (int) $user->getKey(), 'fa fa-shield', $color, (int) $user->getKey()));
    }

    /**
     * Run a cache operation on the step-up store; any failure fails closed.
     *
     * @template T
     * @param \Closure(\Illuminate\Contracts\Cache\Repository): T $operation
     * @return T
     * @throws StepUpException Cache failure (503).
     */
    private function cache(\Closure $operation): mixed
    {
        try {
            return $operation(StepUpThrottle::store());
        } catch (StepUpException $e) {
            throw $e;
        } catch (Throwable) {
            throw StepUpException::unavailable();
        }
    }
}
