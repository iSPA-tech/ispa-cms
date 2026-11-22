<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Security;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Models\AuthChallenge;
use Cms\Core\Models\UserMfaFactor;
use Cms\Core\Models\UserMfaRecoveryCode;

/**
 * Multi-factor authentication service for admin users.
 *
 * Manages MFA modes (off, email-only, TOTP-only, email+TOTP),
 * challenge creation and verification, email code delivery,
 * TOTP authenticator enrollment/disabling, and recovery code
 * generation. Uses HMAC-SHA256 for code hashing and supports
 * configurable attempt limits and TTLs.
 *
 * @see TotpService
 * @see AuthChallenge
 * @see UserMfaFactor
 * @package Cms\Core\Services\Security
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class AdminMfaService
{
    public const METHOD_EMAIL_CODE = 'email_code';
    public const METHOD_TOTP = 'totp';
    public const METHOD_RECOVERY_CODE = 'recovery_code';

    public const MODE_OFF = 'off';
    public const MODE_EMAIL_ONLY = 'email_only';
    public const MODE_TOTP_ONLY = 'totp_only';
    public const MODE_EMAIL_PLUS_TOTP = 'email_plus_totp';

    private const CHALLENGE_TTL_MINUTES = 10;
    private const EMAIL_CODE_TTL_MINUTES = 5;
    private const MAX_CHALLENGE_ATTEMPTS = 8;
    private const RECOVERY_CODES_COUNT = 8;

    /**
     * Challenge ids whose current verification failed on a wrong code (counted after the rollback).
     *
     * @var array<int, bool>
     */
    private array $failedAttemptChallengeIds = [];

    /**
     * Initialize the MFA service with the TOTP provider.
     *
     * @param TotpService $totpService TOTP authenticator service for code generation and verification.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected TotpService $totpService
    ) {}

    /**
     * Get the available MFA mode options for UI display.
     *
     * @return array<int, array{value: string, name: string}>
     */
    public function modeOptions(): array
    {
        return [
            [
                'value' => self::MODE_OFF,
                'name' => __('text.mfa.mode-off'),
            ],
            [
                'value' => self::MODE_EMAIL_ONLY,
                'name' => __('text.mfa.mode-email-only'),
            ],
            [
                'value' => self::MODE_TOTP_ONLY,
                'name' => __('text.mfa.mode-totp-only'),
            ],
            [
                'value' => self::MODE_EMAIL_PLUS_TOTP,
                'name' => __('text.mfa.mode-email-plus-totp'),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function validModes(): array
    {
        return [
            self::MODE_OFF,
            self::MODE_EMAIL_ONLY,
            self::MODE_TOTP_ONLY,
            self::MODE_EMAIL_PLUS_TOTP,
        ];
    }

    /**
     * Resolve the effective MFA mode for a user from settings.
     *
     * @param User $user The admin user.
     * @return string One of the MODE_* constants.
     */
    public function resolveMfaMode(User $user): string
    {
        $mode = (string)$this->getSecuritySetting($user, 'mfa_mode', '');

        if ($mode !== '' && in_array($mode, self::validModes(), true)) {
            return $mode;
        }

        return $this->getSecuritySetting($user, 'two_fa', false)
            ? self::MODE_EMAIL_ONLY
            : self::MODE_OFF;
    }

    /**
     * Determine the required MFA methods for a user based on their mode.
     *
     * @param User $user The admin user.
     * @return array<int, string> Required method constants.
     */
    public function resolveRequiredMethods(User $user): array
    {
        $mode = $this->resolveMfaMode($user);
        $enabledFactor = $this->getEnabledTotpFactor($user);

        return match ($mode) {
            self::MODE_OFF => [],
            self::MODE_EMAIL_ONLY => $this->resolveEmailRequirement($user),
            self::MODE_TOTP_ONLY => $this->resolveTotpRequirement($enabledFactor),
            self::MODE_EMAIL_PLUS_TOTP => [
                ...$this->resolveEmailRequirement($user),
                ...$this->resolveTotpRequirement($enabledFactor),
            ],
            default => [],
        };
    }

    /**
     * Create an MFA challenge for a user login attempt.
     *
     * @param User        $user      The user being challenged.
     * @param string      $ipAddress The client IP address.
     * @param string|null $userAgent The client user agent.
     * @param bool        $remember  Whether "remember me" was requested.
     * @param array<int, string>|null $requiredMethods Optional required methods override.
     * @return AuthChallenge|null The created challenge, or null when no methods are required.
     *
     * @throws RuntimeException When the user's email is unavailable for email-based MFA.
     */
    public function createChallenge(
        User $user,
        string $ipAddress,
        ?string $userAgent = null,
        bool $remember = false,
        array $context = [],
        ?array $requiredMethods = null,
    ): ?AuthChallenge
    {
        $requiredMethods ??= $this->resolveRequiredMethods($user);

        if ($requiredMethods === []) {
            return null;
        }

        $challenge = AuthChallenge::query()->create([
            'uuid' => (string)Str::uuid(),
            'user_id' => $user->id,
            'status' => 'pending',
            'required_methods' => $requiredMethods,
            'completed_methods' => [],
            'expires_at' => now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'remember' => $remember,
            'context' => array_merge($context, [
                'mode' => $this->resolveMfaMode($user),
            ]),
        ]);

        if ($this->currentMethod($challenge) === self::METHOD_EMAIL_CODE) {
            $this->sendEmailCode($challenge, true);
        }

        return $this->loadChallengeContext($challenge);
    }

    /**
     * Find a challenge by UUID (any status).
     *
     * @param string $uuid The challenge UUID.
     * @return AuthChallenge|null
     */
    public function findPendingChallengeByUuid(string $uuid): ?AuthChallenge
    {
        $challenge = AuthChallenge::query()
            ->where('uuid', $uuid)
            ->latest('id')
            ->first();

        return $challenge ? $this->loadChallengeContext($challenge) : null;
    }

    /**
     * Find the latest pending, non-expired challenge by user email.
     *
     * @param string $email The user's email address.
     * @return AuthChallenge|null
     */
    public function findPendingChallengeByEmail(string $email): ?AuthChallenge
    {
        $challenge = AuthChallenge::query()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->whereHas('user', static function ($query) use ($email): void {
                $query->where('email', $email);
            })
            ->latest('id')
            ->first();

        return $challenge ? $this->loadChallengeContext($challenge) : null;
    }

    /**
     * Verify a code against the current challenge method.
     *
     * Returns an auth payload on completion, or a challenge payload
     * if more methods remain. Supports recovery code fallback.
     *
     * @param AuthChallenge $challenge The active challenge.
     * @param string        $code      The verification code.
     * @param string|null   $method    Optional method override (e.g., 'recovery_code').
     * @return array{type: string, payload: array<string, mixed>}
     *
     * @throws RuntimeException When the challenge is not found, expired, blocked, or the code is invalid.
     */
    public function verifyChallenge(
        AuthChallenge $challenge,
        string $code,
        ?string $method = null,
        ?Closure $onCompleted = null,
    ): array
    {
        unset($this->failedAttemptChallengeIds[$challenge->id]);

        try {
            return $this->verifyChallengeInTransaction($challenge, $code, $method, $onCompleted);
        } catch (\Throwable $e) {
            $this->persistFailedAttempt((int) $challenge->id);

            throw $e;
        }
    }

    /**
     * Run the verification inside one locking transaction.
     *
     * A failed code throws, which rolls this transaction back, so the failed attempt is counted afterwards by
     * {@see persistFailedAttempt()} outside the transaction.
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function verifyChallengeInTransaction(
        AuthChallenge $challenge,
        string $code,
        ?string $method,
        ?Closure $onCompleted,
    ): array
    {
        return DB::transaction(function () use ($challenge, $code, $method, $onCompleted): array {
            /** @var AuthChallenge|null $locked */
            $locked = AuthChallenge::query()
                ->whereKey($challenge->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new RuntimeException(__('mgs.auth.challenge_not_found'));
            }

            $locked = $this->loadChallengeContext($locked);
            $this->assertChallengeActive($locked);

            if (($locked->attempt_count ?? 0) >= self::MAX_CHALLENGE_ATTEMPTS) {
                $locked->status = 'failed';
                $locked->save();

                throw new RuntimeException(__('mgs.auth.challenge_blocked'));
            }

            $currentMethod = $this->currentMethod($locked);

            if ($currentMethod === null) {
                throw new RuntimeException(__('mgs.auth.challenge_not_found'));
            }

            $verificationMethod = $method === self::METHOD_RECOVERY_CODE
                ? self::METHOD_RECOVERY_CODE
                : $currentMethod;

            $matchedRequiredMethod = match ($verificationMethod) {
                self::METHOD_EMAIL_CODE => $this->verifyEmailCode($locked, $code),
                self::METHOD_TOTP => $this->verifyTotpCode($locked, $code),
                self::METHOD_RECOVERY_CODE => $this->verifyRecoveryCode($locked, $code, $currentMethod),
                default => throw new RuntimeException(__('mgs.auth.invalid_method')),
            };

            $completedMethods = collect($locked->completed_methods ?? [])
                ->push($matchedRequiredMethod)
                ->unique()
                ->values()
                ->all();

            $locked->completed_methods = $completedMethods;
            $locked->attempt_count = 0;
            $locked->save();

            $nextMethod = $this->currentMethod($locked);

            if ($nextMethod === null) {
                $locked->status = 'completed';
                $locked->completed_at = now();
                $locked->save();

                return [
                    'type' => 'auth',
                    'payload' => $onCompleted
                        ? $onCompleted($locked->user)
                        : $this->buildAuthPayload($locked->user),
                ];
            }

            if ($nextMethod === self::METHOD_EMAIL_CODE) {
                $this->sendEmailCode($locked, true);
            }

            return [
                'type' => 'challenge',
                'payload' => $this->buildChallengePayload($locked->fresh()),
            ];
        });
    }

    /**
     * Resend the email verification code for an active challenge.
     *
     * @param AuthChallenge $challenge The active challenge.
     * @return array<string, mixed> The updated challenge payload.
     *
     * @throws RuntimeException When the current challenge method is not email_code, or the challenge is inactive.
     */
    public function resendEmailCode(AuthChallenge $challenge): array
    {
        $challenge = $this->loadChallengeContext($challenge);
        $this->assertChallengeActive($challenge);

        if ($this->currentMethod($challenge) !== self::METHOD_EMAIL_CODE) {
            throw new RuntimeException(__('mgs.auth.invalid_method'));
        }

        $this->sendEmailCode($challenge, true);

        return $this->buildChallengePayload($challenge->fresh());
    }

    /**
     * Start TOTP authenticator enrollment for a user.
     *
     * @param User $user The user to enroll.
     * @return array<string, mixed> Enrollment payload with secret and OTP URI.
     *
     * @throws RuntimeException When the user already has an enabled authenticator.
     */
    public function startAuthenticatorEnrollment(User $user): array
    {
        return $this->withAuthenticatorLock(
            $user,
            fn (): array => $this->startAuthenticatorEnrollmentUnlocked($user),
        );
    }

    private function startAuthenticatorEnrollmentUnlocked(User $user): array
    {
        if ($this->getEnabledTotpFactor($user)) {
            throw new RuntimeException(__('mgs.auth.authenticator_already_enabled'));
        }

        $pendingFactor = $this->getPendingTotpFactor($user);

        if (!$pendingFactor) {
            $pendingFactor = UserMfaFactor::query()->create([
                'uuid' => (string)Str::uuid(),
                'user_id' => $user->id,
                'provider' => UserMfaFactor::PROVIDER_TOTP,
                'label' => $this->defaultAuthenticatorLabel($user),
                'issuer' => $this->defaultIssuer(),
                'secret' => $this->totpService->generateSecret(),
                'metadata' => [
                    'pending_enrollment' => true,
                ],
            ]);
        }

        $pendingFactor->refresh();

        return $this->buildEnrollmentPayload($user, $pendingFactor);
    }

    /**
     * Confirm TOTP authenticator enrollment with a verification code.
     *
     * @param User   $user The user confirming enrollment.
     * @param string $code The TOTP code from the authenticator app.
     * @return array<string, mixed> Factor summary and generated recovery codes.
     *
     * @throws RuntimeException When no pending enrollment exists or the code is invalid.
     */
    public function confirmAuthenticatorEnrollment(User $user, string $code): array
    {
        return $this->withAuthenticatorLock(
            $user,
            fn (): array => $this->confirmAuthenticatorEnrollmentUnlocked($user, $code),
        );
    }

    private function confirmAuthenticatorEnrollmentUnlocked(User $user, string $code): array
    {
        $factor = $this->getPendingTotpFactor($user);

        if (!$factor) {
            throw new RuntimeException(__('mgs.auth.authenticator_not_pending'));
        }

        if (!$this->totpService->verify((string)$factor->secret, $code)) {
            throw new RuntimeException(__('mgs.auth.invalid_code'));
        }

        DB::transaction(function () use ($user, $factor): void {
            UserMfaFactor::query()
                ->where('user_id', $user->id)
                ->where('provider', UserMfaFactor::PROVIDER_TOTP)
                ->update([
                    'is_primary' => false,
                ]);

            $factor->verified_at = now();
            $factor->enabled_at = now();
            $factor->disabled_at = null;
            $factor->is_primary = true;
            $factor->metadata = array_merge($factor->metadata ?? [], [
                'pending_enrollment' => false,
            ]);
            $factor->save();
        });

        $factor->refresh();
        $recoveryCodes = $this->replaceRecoveryCodes($factor);

        return [
            'factor' => $this->buildFactorSummary($factor),
            'recovery_codes' => $recoveryCodes,
            'summary' => $this->buildAccountSummary($user->fresh('settings', 'mfaFactors.recoveryCodes')),
        ];
    }

    /**
     * Disable the TOTP authenticator for a user (requires current password).
     *
     * @param User   $user            The user.
     * @param string $currentPassword The user's current password for confirmation.
     * @return array<string, mixed> Updated account summary.
     *
     * @throws RuntimeException When the password is incorrect or no authenticator is enabled.
     */
    public function disableAuthenticator(User $user, string $currentPassword): array
    {
        return $this->withAuthenticatorLock(
            $user,
            function () use ($user, $currentPassword): array {
                $this->assertCurrentPassword($user, $currentPassword);

                return $this->disableAuthenticatorUnlocked($user);
            },
        );
    }

    public function disableAuthenticatorAfterReauthentication(User $user): array
    {
        return $this->withAuthenticatorLock(
            $user,
            fn (): array => $this->disableAuthenticatorUnlocked($user),
        );
    }

    private function disableAuthenticatorUnlocked(User $user): array
    {
        $factor = $this->getEnabledTotpFactor($user);

        if (!$factor) {
            throw new RuntimeException(__('mgs.auth.authenticator_not_enabled'));
        }

        DB::transaction(function () use ($user, $factor): void {
            $factor->disabled_at = now();
            $factor->is_primary = false;
            $factor->save();

            foreach ($factor->recoveryCodes as $recoveryCode) {
                $recoveryCode->used_at ??= now();
                $recoveryCode->save();
            }

            $nextMode = match ($this->resolveMfaMode($user)) {
                self::MODE_TOTP_ONLY => self::MODE_OFF,
                self::MODE_EMAIL_PLUS_TOTP => self::MODE_EMAIL_ONLY,
                default => $this->resolveMfaMode($user),
            };

            $this->persistMfaMode($user, $nextMode);
        });

        return $this->buildAccountSummary($user->fresh('settings', 'mfaFactors.recoveryCodes'));
    }

    /**
     * Regenerate recovery codes for a user (requires current password).
     *
     * @param User   $user            The user.
     * @param string $currentPassword The user's current password for confirmation.
     * @return array<string, mixed> Factor summary and new recovery codes.
     *
     * @throws RuntimeException When the password is incorrect or no authenticator is enabled.
     */
    public function regenerateRecoveryCodes(User $user, string $currentPassword): array
    {
        return $this->withAuthenticatorLock(
            $user,
            function () use ($user, $currentPassword): array {
                $this->assertCurrentPassword($user, $currentPassword);

                return $this->regenerateRecoveryCodesUnlocked($user);
            },
        );
    }

    public function regenerateRecoveryCodesAfterReauthentication(User $user): array
    {
        return $this->withAuthenticatorLock(
            $user,
            fn (): array => $this->regenerateRecoveryCodesUnlocked($user),
        );
    }

    private function regenerateRecoveryCodesUnlocked(User $user): array
    {
        $factor = $this->getEnabledTotpFactor($user);

        if (!$factor) {
            throw new RuntimeException(__('mgs.auth.authenticator_not_enabled'));
        }

        $codes = $this->replaceRecoveryCodes($factor);

        return [
            'factor' => $this->buildFactorSummary($factor->fresh()),
            'recovery_codes' => $codes,
            'summary' => $this->buildAccountSummary($user->fresh('settings', 'mfaFactors.recoveryCodes')),
        ];
    }

    private function assertCurrentPassword(User $user, string $currentPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new RuntimeException(__('auth.password'));
        }
    }

    /**
     * Build the API challenge payload for frontend rendering.
     *
     * @param AuthChallenge $challenge The challenge to serialize.
     * @return array<string, mixed>
     */
    public function buildChallengePayload(AuthChallenge $challenge): array
    {
        $challenge = $this->loadChallengeContext($challenge);
        $factor = $this->getEnabledTotpFactor($challenge->user);

        return [
            'type' => 'mfa_challenge',
            'challenge_id' => $challenge->uuid,
            'email' => $challenge->user?->email,
            'required_methods' => $challenge->required_methods ?? [],
            'completed_methods' => $challenge->completed_methods ?? [],
            'current_method' => $this->currentMethod($challenge),
            'expires_at' => $challenge->expires_at?->toIso8601String(),
            'email_code_sent_at' => $challenge->email_code_sent_at?->toIso8601String(),
            'email_code_expires_at' => $challenge->email_code_expires_at?->toIso8601String(),
            'can_resend' => $this->currentMethod($challenge) === self::METHOD_EMAIL_CODE,
            'can_use_recovery_code' => $this->currentMethod($challenge) === self::METHOD_TOTP
                && $factor?->recoveryCodes()->whereNull('used_at')->exists(),
            'recovery_codes_remaining' => $factor
                ? $factor->recoveryCodes()->whereNull('used_at')->count()
                : 0,
        ];
    }

    /**
     * Build the authentication payload after successful MFA completion.
     *
     * The token is issued through ApiTokenIssuer with the admin audience (device record and lifetime from the policy);
     * the abilities stay the account permissions.
     *
     * @param User $user The authenticated user.
     * @param Request|null $request Request the token is issued for; the current request when null.
     * @return array<string, mixed> Token, user data, and locale.
     */
    public function buildAuthPayload(User $user, ?Request $request = null): array
    {
        $user->loadMissing('avatar', 'roles.permissions', 'settings');
        $user->setAttribute('avatar', $user->avatar ? $user->avatar->toArray() : null);
        $token = app(ApiTokenIssuer::class)->issue(
            $user,
            TokenAudience::ADMIN,
            'authToken',
            $user->permissions(),
            $request ?? request(),
            null,
            'admin.sign-in'
        )->plainTextToken;
        $locale = (string)$this->getSecurityAgnosticSetting($user, 'language', config('ispa-cms.primary_language'));

        return [
            'type' => 'auth',
            'token' => $token,
            'user' => collect($user)->toCamel(),
            'locale' => $locale,
        ];
    }

    /**
     * Build the MFA account summary for the user's settings page.
     *
     * @param User $user The user.
     * @return array<string, mixed> Mode, authenticator status, and recovery codes remaining.
     */
    public function buildAccountSummary(User $user): array
    {
        $user->loadMissing('settings', 'mfaFactors.recoveryCodes');
        $enabledFactor = $this->getEnabledTotpFactor($user);
        $pendingFactor = $this->getPendingTotpFactor($user);

        return [
            'mode' => $this->resolveMfaMode($user),
            'mode_options' => $this->modeOptions(),
            'email_channel_available' => filled($user->email),
            'email_step_enabled' => in_array($this->resolveMfaMode($user), [
                self::MODE_EMAIL_ONLY,
                self::MODE_EMAIL_PLUS_TOTP,
            ], true),
            'authenticator' => $enabledFactor ? $this->buildFactorSummary($enabledFactor) : [
                'enabled' => false,
                'pending' => (bool)$pendingFactor,
            ],
            'recovery_codes_remaining' => $enabledFactor
                ? $enabledFactor->recoveryCodes()->whereNull('used_at')->count()
                : 0,
        ];
    }

    /**
     * Persist the MFA mode in the user's security settings.
     *
     * @param User   $user The user.
     * @param string $mode The MFA mode constant.
     * @return void
     */
    public function persistMfaMode(User $user, string $mode): void
    {
        if (! in_array($mode, self::validModes(), true)) {
            throw new InvalidArgumentException('Invalid admin MFA mode.');
        }

        if (in_array($mode, [self::MODE_TOTP_ONLY, self::MODE_EMAIL_PLUS_TOTP], true)
            && ! $this->getEnabledTotpFactor($user)) {
            throw new RuntimeException(__('mgs.auth.authenticator_not_enabled'));
        }

        $this->upsertSecuritySetting($user, 'mfa_mode', $mode, 'select');
        $this->upsertSecuritySetting(
            $user,
            'two_fa',
            in_array($mode, [self::MODE_EMAIL_ONLY, self::MODE_EMAIL_PLUS_TOTP], true),
            'boolean'
        );
    }

    private function resolveEmailRequirement(User $user): array
    {
        if (!$user->email) {
            throw new RuntimeException(__('mgs.auth.email_not_available'));
        }

        return [self::METHOD_EMAIL_CODE];
    }

    private function resolveTotpRequirement(?UserMfaFactor $factor): array
    {
        if (!$factor) {
            throw new RuntimeException(__('mgs.auth.authenticator_not_enabled'));
        }

        return [self::METHOD_TOTP];
    }

    private function assertChallengeActive(AuthChallenge $challenge): void
    {
        if ($challenge->status !== 'pending') {
            throw new RuntimeException(__('mgs.auth.challenge_not_found'));
        }

        if (optional($challenge->expires_at)->isPast()) {
            $challenge->status = 'expired';
            $challenge->save();

            throw new RuntimeException(__('mgs.auth.challenge_expired'));
        }
    }

    private function verifyEmailCode(AuthChallenge $challenge, string $code): string
    {
        $normalizedCode = preg_replace('/\D+/', '', $code);

        if (!is_string($normalizedCode) || strlen($normalizedCode) !== 6) {
            $this->registerFailedAttempt($challenge);
            throw new RuntimeException(__('mgs.auth.invalid_code'));
        }

        if (!$challenge->email_code_hash || optional($challenge->email_code_expires_at)->isPast()) {
            $this->registerFailedAttempt($challenge);
            throw new RuntimeException(__('mgs.auth.challenge_expired'));
        }

        $hash = $this->hashEmailCode($challenge, $normalizedCode);

        if (!hash_equals($challenge->email_code_hash, $hash)) {
            $this->registerFailedAttempt($challenge);
            throw new RuntimeException(__('mgs.auth.invalid_code'));
        }

        return self::METHOD_EMAIL_CODE;
    }

    private function verifyTotpCode(AuthChallenge $challenge, string $code): string
    {
        $factor = $this->getEnabledTotpFactor($challenge->user);

        if (!$factor || !$this->totpService->verify((string)$factor->secret, $code)) {
            $this->registerFailedAttempt($challenge);
            throw new RuntimeException(__('mgs.auth.invalid_code'));
        }

        $factor->last_used_at = now();
        $factor->last_challenged_at = now();
        $factor->save();

        return self::METHOD_TOTP;
    }

    private function verifyRecoveryCode(AuthChallenge $challenge, string $code, string $currentMethod): string
    {
        if ($currentMethod !== self::METHOD_TOTP) {
            $this->registerFailedAttempt($challenge);
            throw new RuntimeException(__('mgs.auth.invalid_method'));
        }

        $factor = $this->getEnabledTotpFactor($challenge->user);

        if (!$factor) {
            $this->registerFailedAttempt($challenge);
            throw new RuntimeException(__('mgs.auth.authenticator_not_enabled'));
        }

        $hash = $this->hashRecoveryCode($code);
        $recoveryCode = $factor->recoveryCodes()
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->first();

        if (!$recoveryCode) {
            $this->registerFailedAttempt($challenge);
            throw new RuntimeException(__('mgs.auth.invalid_code'));
        }

        $recoveryCode->used_at = now();
        $recoveryCode->save();

        $factor->last_used_at = now();
        $factor->last_challenged_at = now();
        $factor->save();

        return self::METHOD_TOTP;
    }

    private function registerFailedAttempt(AuthChallenge $challenge): void
    {
        $this->failedAttemptChallengeIds[$challenge->id] = true;

        $challenge->attempt_count = (int)$challenge->attempt_count + 1;

        if ($challenge->attempt_count >= self::MAX_CHALLENGE_ATTEMPTS) {
            $challenge->status = 'failed';
        }

        $challenge->save();
    }

    /**
     * Count a wrong code after the failing transaction rolled back.
     *
     * The increment is one atomic UPDATE outside the transaction, so it survives the failed request and cannot
     * lose a concurrent guess; the challenge is closed once the limit is reached.
     */
    private function persistFailedAttempt(int $challengeId): void
    {
        if (empty($this->failedAttemptChallengeIds[$challengeId])) {
            return;
        }

        unset($this->failedAttemptChallengeIds[$challengeId]);

        AuthChallenge::query()->whereKey($challengeId)->increment('attempt_count');
        AuthChallenge::query()
            ->whereKey($challengeId)
            ->where('attempt_count', '>=', self::MAX_CHALLENGE_ATTEMPTS)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);
    }

    private function sendEmailCode(AuthChallenge $challenge, bool $force = false): void
    {
        $challenge = $this->loadChallengeContext($challenge);

        if (!$force && $challenge->email_code_sent_at && $challenge->email_code_sent_at->gt(now()->subSeconds(30))) {
            return;
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge->email_code_hash = $this->hashEmailCode($challenge, $code);
        $challenge->email_code_sent_at = now();
        $challenge->email_code_expires_at = now()->addMinutes(self::EMAIL_CODE_TTL_MINUTES);
        $challenge->save();

        event(new SendCodeVerify($challenge->user, $code, 'two_fa'));
    }

    private function loadChallengeContext(AuthChallenge $challenge): AuthChallenge
    {
        $challenge->loadMissing('user.settings', 'user.avatar', 'user.roles.permissions', 'user.mfaFactors.recoveryCodes');

        return $challenge;
    }

    private function currentMethod(AuthChallenge $challenge): ?string
    {
        $required = $challenge->required_methods ?? [];
        $completed = $challenge->completed_methods ?? [];

        foreach ($required as $method) {
            if (!in_array($method, $completed, true)) {
                return $method;
            }
        }

        return null;
    }

    private function getEnabledTotpFactor(User $user): ?UserMfaFactor
    {
        $user->loadMissing('mfaFactors.recoveryCodes');

        return $user->mfaFactors
            ->where('provider', UserMfaFactor::PROVIDER_TOTP)
            ->first(static fn(UserMfaFactor $factor) => $factor->verified_at !== null
                && $factor->enabled_at !== null
                && $factor->disabled_at === null
                && $factor->is_primary);
    }

    private function getPendingTotpFactor(User $user): ?UserMfaFactor
    {
        $user->loadMissing('mfaFactors');

        return $user->mfaFactors
            ->where('provider', UserMfaFactor::PROVIDER_TOTP)
            ->sortByDesc('id')
            ->first(static fn(UserMfaFactor $factor) => $factor->verified_at === null && $factor->disabled_at === null);
    }

    private function getSecuritySetting(User $user, string $key, mixed $default = null): mixed
    {
        $user->loadMissing('settings');
        $setting = $user->settings->first(static fn($item) => $item->setting_key === $key);

        return data_get($setting?->setting_value, 'value', $default);
    }

    private function getSecurityAgnosticSetting(User $user, string $key, mixed $default = null): mixed
    {
        $user->loadMissing('settings');
        $setting = $user->settings->first(static fn($item) => $item->setting_key === $key);

        return data_get($setting?->setting_value, 'value', $default);
    }

    private function upsertSecuritySetting(User $user, string $key, mixed $value, string $type): void
    {
        $user->settings()->updateOrCreate(
            [
                'user_id' => $user->id,
                'setting_group' => 'security',
                'setting_key' => $key,
            ],
            [
                'setting_value' => [
                    'value' => $value,
                    'type' => $type,
                    'options' => $key === 'mfa_mode' ? $this->modeOptions() : null,
                ],
            ]
        );
    }

    private function buildEnrollmentPayload(User $user, UserMfaFactor $factor): array
    {
        $issuer = (string)($factor->issuer ?: $this->defaultIssuer());
        $label = (string)($factor->label ?: $this->defaultAuthenticatorLabel($user));
        $secret = (string)$factor->secret;
        $otpAuthUri = $this->totpService->generateOtpAuthUri($issuer, $label, $secret);

        return [
            'issuer' => $issuer,
            'label' => $label,
            'secret' => $secret,
            'otpauth_uri' => $otpAuthUri,
        ];
    }

    private function replaceRecoveryCodes(UserMfaFactor $factor): array
    {
        return DB::transaction(function () use ($factor): array {
            $factor->recoveryCodes()->delete();
            $codes = [];

            while (count($codes) < self::RECOVERY_CODES_COUNT) {
                $code = $this->generateRecoveryCode();
                $hash = $this->hashRecoveryCode($code);

                if (UserMfaRecoveryCode::query()->where('code_hash', $hash)->exists()) {
                    continue;
                }

                $factor->recoveryCodes()->create([
                    'user_id' => $factor->user_id,
                    'code_hash' => $hash,
                    'code_hint' => substr($code, -4),
                ]);

                $codes[] = $code;
            }

            return $codes;
        });
    }

    private function withAuthenticatorLock(User $user, callable $action): mixed
    {
        $lock = Cache::lock('account:mfa:authenticator:' . $user->getKey(), 10);
        if (! $lock->get()) {
            throw new RuntimeException(__('mgs.auth.failed'));
        }

        try {
            return $action();
        } finally {
            $lock->release();
        }
    }

    private function generateRecoveryCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $segments = [];

        for ($segment = 0; $segment < 3; $segment++) {
            $value = '';

            for ($i = 0; $i < 4; $i++) {
                $value .= $chars[random_int(0, strlen($chars) - 1)];
            }

            $segments[] = $value;
        }

        return implode('-', $segments);
    }

    private function hashEmailCode(AuthChallenge $challenge, string $code): string
    {
        return hash_hmac('sha256', $challenge->uuid . '|' . $code, (string)config('app.key'));
    }

    private function hashRecoveryCode(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');

        return hash_hmac('sha256', $normalized, (string)config('app.key'));
    }

    private function defaultIssuer(): string
    {
        $brand = trim((string)(iSeo('brand') ?: config('app.name', 'iSPA Admin')));

        return $brand !== '' ? $brand : 'iSPA Admin';
    }

    private function defaultAuthenticatorLabel(User $user): string
    {
        return (string)($user->email ?: $user->username ?: ('admin-' . $user->id));
    }

    private function buildFactorSummary(UserMfaFactor $factor): array
    {
        return [
            'enabled' => $factor->verified_at !== null && $factor->enabled_at !== null && $factor->disabled_at === null,
            'pending' => $factor->verified_at === null,
            'provider' => $factor->provider,
            'label' => $factor->label,
            'issuer' => $factor->issuer,
            'verified_at' => $factor->verified_at?->toIso8601String(),
            'enabled_at' => $factor->enabled_at?->toIso8601String(),
            'disabled_at' => $factor->disabled_at?->toIso8601String(),
            'last_used_at' => $factor->last_used_at?->toIso8601String(),
            'is_primary' => (bool)$factor->is_primary,
        ];
    }
}
