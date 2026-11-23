<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Exception;
use RuntimeException;
use App\Models\User;
use App\Constants\UserType;
use App\Constants\Headers;
use App\Enums\UserStatusEnum;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Events\SendCodeVerify;
use Cms\Core\Interfaces\UserInterface;
use Illuminate\Support\Str;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use Cms\Core\Http\Requests\Auth\SignInRequest;
use Cms\Core\Events\PasswordChangeNotification;
use Cms\Core\Http\Requests\Auth\RequestCodeRequest;
use Cms\Core\Http\Requests\Auth\VerifyAuthChallengeRequest;
use Cms\Core\Http\Requests\Auth\ResendAuthChallengeRequest;
use Cms\Core\Http\Requests\Auth\SetNewPasswordRequest;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;
use Cms\Core\Http\Requests\Auth\TwoFactorVerifyRequest;
use Cms\Core\Http\Requests\PasskeyLoginRequest;
use Cms\Core\Http\Requests\PasskeyRegistrationRequest;
use Cms\Core\Repositories\Interfaces\PasswordResetInterface;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\PasskeyService;
use Cms\Core\Services\Security\TokenRevoker;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;

/**
 * Authentication flows for Admin v2: password login with optional 2FA, password reset
 * (request code → verify code → set new password), and token issuance.
 *
 * Also handles audit logging for sign-in attempts, login rate-limiting headers, and
 * integration with email delivery for verification codes.
 *
 * @see AdminMfaService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-15)
 * @last_updated 8.0.0 - 2025-09-06, 10:15 (+07:00)
 */
class AuthController extends Controller
{
    /**
     * AuthController constructor.
     *
     * @param UserInterface $model User repository for lookups and credential validation.
     * @param EmailLogInterface $log Email log repository used to throttle reset emails.
     * @param PasswordResetInterface $pws Password reset code/token repository.
     * @param  AdminMfaService $adminMfaService
     *
     * @author malayvuong
     * @since  1.0.0 - 2022-01-15, 11:41
     * @last_updated 6.62.6 - 2023-01-14, 17:01
     */
    public function __construct(
        protected UserInterface          $model,
        protected EmailLogInterface      $log,
        protected PasswordResetInterface $pws,
        protected AdminMfaService        $adminMfaService,
    )
    {
        parent::__construct();
    }

    /**
     * Verify a 2FA code that was previously sent to the user's email.
     *
     * The verification code is stored temporarily in cache under the key pattern `two_fa-<email>`.
     * On success, logs in the user by ID, issues a Sanctum token (scopes = user permissions),
     * writes an audit entry, and clears the cached code.
     *
     * @param TwoFactorVerifyRequest $request Validated request containing `email` and `code`.
     *
     * @return mixed
     *
     * @throws ContainerExceptionInterface
     *
     * @author malayvuong
     * @since  6.62.6 - 2023-01-15, 11:28 (+07:00)
     * @last_updated 8.0.0 - 2025-09-06, 10:15 (+07:00)
     */
    public function twoFactorVerify(TwoFactorVerifyRequest $request): mixed
    {
        try {
            $challenge = null;

            if ($request->filled('challenge_id')) {
                $challenge = $this->adminMfaService->findPendingChallengeByUuid((string)$request->input('challenge_id'));
            }

            if (!$challenge && $request->filled('email')) {
                $challenge = $this->adminMfaService->findPendingChallengeByEmail((string)$request->input('email'));
            }

            if ($challenge) {
                $result = $this->adminMfaService->verifyChallenge($challenge, (string)$request->input('code'));

                if ($result['type'] === 'auth') {
                    $this->resetLoginThrottle($request->ip());
                    event(new AuditLogEvent('admin-signIn-success', [], User::class, $challenge->user_id, 'fa fa-sign-in', 'success'));

                    return ires()->data($result['payload'])->message(__('mgs.ok.sign-in'));
                }

                return ires()->data($result['payload'])->message(__('mgs.ok.verify-code'));
            }

            $cacheKey = 'two_fa-' . $request->input('email', '');
            $codeCache = cache()->get($cacheKey);

            if (empty($codeCache) || $codeCache['code'] !== $request->input('code', '')) {
                return ires()->failed()->code(200)->message(__('mgs.auth.invalid_code'));
            }

            auth()->loginUsingId($codeCache['user_id']);

            $user = auth()->user();
            $payload = $this->adminMfaService->buildAuthPayload($user);
            event(new AuditLogEvent('admin-signIn-success', [], User::class, $user?->id, 'fa fa-sign-in', 'success'));

            cache()->forget($cacheKey);
            $this->resetLoginThrottle($request->ip());

            return ires()->data($payload)->message(__('mgs.ok.sign-in'));
        } catch (RuntimeException|NotFoundExceptionInterface $e) {
            return ires()->failed()->code(200)->message($e->getMessage());
        } catch (ContainerExceptionInterface $e) {
            return ires()->error()->message(__('mgs.auth.invalid_code'))->data($e->getMessage());
        }
    }

    /**
     * Verify an MFA authentication challenge using the supplied code.
     *
     * Looks up the pending challenge by UUID, verifies the code, and on success
     * issues a Sanctum token (type=auth) or confirms a non-auth challenge. Resets
     * login throttle and writes an audit log entry on successful authentication.
     *
     * @param VerifyAuthChallengeRequest $request Validated request with challenge_id and code.
     * @return mixed Standardized response with auth payload or verification result.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function verifyChallenge(VerifyAuthChallengeRequest $request): mixed
    {
        try {
            $challenge = $this->adminMfaService->findPendingChallengeByUuid((string)$request->input('challenge_id'));

            if (!$challenge) {
                return ires()->failed()->code(200)->message(__('mgs.auth.challenge_not_found'));
            }

            $result = $this->adminMfaService->verifyChallenge($challenge, (string)$request->input('code'));

            if ($result['type'] === 'auth') {
                $this->resetLoginThrottle($request->ip());
                event(new AuditLogEvent('admin-signIn-success', [], User::class, $challenge->user_id, 'fa fa-sign-in', 'success'));

                return ires()->data($result['payload'])->message(__('mgs.ok.sign-in'));
            }

            return ires()->data($result['payload'])->message(__('mgs.ok.verify-code'));
        } catch (RuntimeException $e) {
            return ires()->failed()->code(200)->message($e->getMessage());
        }
    }

    /**
     * Verify an MFA authentication challenge using a one-time recovery code.
     *
     * Resolves the pending challenge by UUID and verifies via the recovery code method.
     * On success, authenticates the user, resets login throttle, and writes an audit log entry.
     *
     * @param VerifyAuthChallengeRequest $request Validated request with challenge_id and recovery code.
     * @return mixed Standardized response with auth payload or verification result.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function useRecoveryCode(VerifyAuthChallengeRequest $request): mixed
    {
        try {
            $challenge = $this->adminMfaService->findPendingChallengeByUuid((string)$request->input('challenge_id'));

            if (!$challenge) {
                return ires()->failed()->code(200)->message(__('mgs.auth.challenge_not_found'));
            }

            $result = $this->adminMfaService->verifyChallenge(
                $challenge,
                (string)$request->input('code'),
                AdminMfaService::METHOD_RECOVERY_CODE
            );

            if ($result['type'] === 'auth') {
                $this->resetLoginThrottle($request->ip());
                event(new AuditLogEvent('admin-signIn-success', [], User::class, $challenge->user_id, 'fa fa-sign-in', 'success'));

                return ires()->data($result['payload'])->message(__('mgs.ok.sign-in'));
            }

            return ires()->data($result['payload'])->message(__('mgs.ok.verify-code'));
        } catch (RuntimeException $e) {
            return ires()->failed()->code(200)->message($e->getMessage());
        }
    }

    /**
     * Resend the email verification code for a pending MFA authentication challenge.
     *
     * Looks up the pending challenge by UUID and dispatches a new email code delivery.
     *
     * @param ResendAuthChallengeRequest $request Validated request containing the challenge_id UUID.
     * @return mixed Standardized response confirming the code was resent or an error message.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function resendChallenge(ResendAuthChallengeRequest $request): mixed
    {
        try {
            $challenge = $this->adminMfaService->findPendingChallengeByUuid((string)$request->input('challenge_id'));

            if (!$challenge) {
                return ires()->failed()->code(200)->message(__('mgs.auth.challenge_not_found'));
            }

            return ires()->data($this->adminMfaService->resendEmailCode($challenge))
                ->message(__('mgs.ok.verify-code'));
        } catch (RuntimeException $e) {
            return ires()->failed()->code(200)->message($e->getMessage());
        }
    }

    /**
     * Set a new password using a verified reset token (Step 4).
     *
     * Requires a password reset record whose `token` matches and `checked_at` is not null.
     * Persists the new hashed password, emits `PasswordChangeNotification` and an `AuditLog`
     * entry, then deletes the reset record.
     *
     * @param SetNewPasswordRequest $request Validated request with `token` and new `password`.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  6.19.6 - 2022-04-04, 15:54 (+07:00)
     * @last_updated 8.0.0 - 2025-09-06, 10:15 (+07:00)
     */
    public function setPassword(SetNewPasswordRequest $request): mixed
    {
        $code = $this->pws->findBy([
            'token' => $request->input('token'),
            ['checked_at', '!=', null],
            ['valid_to', '>', now()],
        ]);

        if (!$code) {
            return ires()->failed()->message(__('mgs.er.verify-code-no-exists'));
        }

        $user = $this->model->find($code->user_id, ['id', 'name', 'email']);

        if (!$user) {
            return ires()->failed()->message(__('mgs.er.user-not-valid'));
        }

        $user->password = Hash::make($request->input('password'));

        DB::beginTransaction();
        try {
            $user->save();

            event(new PasswordChangeNotification($request->userAgent(), $request->ip(), $user));
            event(new AuditLogEvent('resetPassword', [], 'User', $user->id, 'fa fa-lock', 'text-green-600'));

            //  Remove code
            $code->delete();
        } catch (Exception $e) {
            DB::rollBack();

            return ires()->error()->message(__('mgs.er.password-not-save', ['error' => $e->getMessage()]));
        }
        DB::commit();

        // A reset password ends every API token of the account
        app(TokenRevoker::class)->revokeForUser($user, null);

        $form = [
            'email' => null,
            'step' => 4,
            'type' => 'done',
            'password' => null,
            'password_confirmation' => null,
            'code' => null,
            'token' => null,
        ];

        return ires()->data($form)->message(__('mgs.ok.password-changed'));
    }

    /**
     * Verify the email code sent for password reset (Step 2 → 3).
     *
     * Expects a `code` query parameter. If valid and not expired (`valid_to` > now), marks the
     * record as `checked_at = now()` and returns the form state with the associated `token`.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  6.19.6 - 2022-04-04, 15:07 (+07:00)
     * @last_updated 8.0.0 - 2025-09-06, 10:15 (+07:00)
     */
    public function checkCode(): mixed
    {
        if (!request()->has('code')) {
            return ires()->failed()->message(__('validation.required', ['attribute' => __('validation.attributes.verify-code')]));
        }

        if (!request()->filled('email')) {
            return ires()->failed()->message(__('validation.required', ['attribute' => 'email']));
        }

        $user = $this->model->findBy([
            'email' => request('email'),
        ], ['id', 'email']);

        if (!$user) {
            return ires()->failed()->code(422)->message(__('mgs.er.verify-code-no-exists'));
        }

        $code = $this->pws->findBy([
            'code' => request('code'),
            'user_id' => $user->id,
            ['valid_to', '>', now()],
        ]);

        if (!$code) {
            return ires()->failed()->code(422)->message(__('mgs.er.verify-code-no-exists'));
        }

        $code->checked_at = now();
        $code->save();

        $form = [
            'email' => request('email'),
            'step' => 3,
            'type' => 'check-code',
            'password' => null,
            'password_confirmation' => null,
            'code' => request('code'),
            'token' => $code->token,
        ];

        return ires()->data($form)->message(__('mgs.ok.verify-code'));
    }

    /**
     * Send a password reset verification code via email (Step 1).
     *
     * Looks up the user by email, validates account status and verification, rate-limits email
     * sends using `EmailLogInterface` (last 5 minutes), emits `SendCodeVerify`, and stores the
     * generated code with a 5-minute validity window.
     *
     * @param RequestCodeRequest $request Validated request with `email` and reset `type`.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  6.19.6 - 2022-04-04, 11:17 (+07:00)
     * @last_updated 8.0.0 - 2025-09-06, 10:15 (+07:00)
     */
    public function requestCode(RequestCodeRequest $request): mixed
    {
        $form = $this->passwordResetForm((string) $request->input('email'));
        $model = $this->model->findBy([
            'email' => $request->input('email'),
        ], ['id', 'status', 'email', 'email_verified_at']);

        if (!$model) {
            return ires()->data($form);
        }

        if ($model->status && $model->status !== UserStatusEnum::USER_ACTIVE->value) {
            return ires()->data($form);
        }

        if (!$model->email_verified_at) {
            return ires()->data($form);
        }

        //  Check time of mail
        $mail = $this->log->get([
            'select' => ['id', 'created_at'],
            'take' => 1,
            'where' => [
                ['created_at', '>=', now()->addMinutes(-5)],
                'type' => $request->input('type'),
                'user_id' => $model->id,
            ],
            'order' => [
                'created_at' => 'desc',
            ],
        ]);

        if ($mail) {
            return ires()->failed()->message(__('mgs.er.email-limited', ['time' => $mail->created_at->timezone(config('ispa-cms.timezone'))->addMinutes(5)->format('Y-m-d H:i:s')]));
        }

        DB::beginTransaction();
        try {
            /** Make Recover CODE */
            $code = str()->padLeft(random_int(0, 99999999), 8);
            //  Process to send email via Event
            event(new SendCodeVerify($model, $code, request('type')));
            //  Save the code
            $codeModel = $this->pws->model();
            $codeModel->code = $code;
            $codeModel->user_id = $model->id;
            $codeModel->token = Str::random(64);
            $codeModel->valid_to = now()->addMinutes(5);
            $codeModel->save();
        } catch (Exception $e) {
            DB::rollBack();

            return ires()->error()->message('DB error. $message' . $e->getMessage());
        }
        DB::commit();

        return ires()->data($form);
    }

    /**
     * Admin sign-in with optional email-based 2FA and login rate limiting.
     *
     * Applies a 5-minute IP-based throttle window. If credentials match and the user has 2FA
     * enabled, a code is generated, cached for 5 minutes, emailed, and the response indicates
     * `type = two_fa`. Otherwise, issues a Sanctum token with user permission scopes and returns
     * `type = auth` along with basic user and locale info.
     *
     * Response headers may include remaining-attempt counters and block indicators when limited.
     *
     * @param SignInRequest $request Validated request with `username` and `password`.
     *
     * @return mixed
     *
     * @throws Psr\SimpleCache\InvalidArgumentException
     * @throws Exception
     *
     * @author malayvuong
     * @since  1.0.0 - 2022-01-15, 11:36 (+07:00)
     * @last_updated 8.0.0 - 2025-09-06, 10:15 (+07:00)
     */
    public function signIn(SignInRequest $request)
    {
        //  Check limited login for 1 hour and from IP address
        $limited = cache()->remember('login-limited-' . $request->ip(), 300, function (): array {
            return [
                'count' => 0,
                'time' => now()->addMinutes(5),
            ];
        });

        if ($limited['count'] > 4 && $limited['time'] > now()) {
            return ires()->failed()->headers([
                Headers::ISPA_AUTH_FAILED_REMAINING_COUNT => $limited['count'],
                Headers::ISPA_AUTH_FAILED_REMAINING_MESSAGE => __('mgs.auth.failed-remaining', ['n' => $limited['count']]),
                Headers::ISPA_AUTH_FAILED_BLOCK => 1,
                Headers::ISPA_AUTH_FAILED_REMAINING_TIME => $limited['time'],
            ])->code(200)->message(__('mgs.auth.login-limited', ['time' => $limited['time']->format('Y-m-d H:i:s')]));
        }

        $user = $this->model->getByEmailOrUsername($request->input('username'), [
            'roles.permissions', 'settings', 'mfaFactors.recoveryCodes',
        ]);

        // Webmaster and Admin, the same staff types every other admin gate accepts (ApiAdminVerifyMiddleware, passkey sign-in).
        if (!$user || !in_array($user->type, [UserType::WEBMASTER, UserType::ADMIN], true) || !Hash::check($request->input('password'), $user->password)) {
            /** Audit log sign in */
            if ($user) {
                event(new AuditLogEvent('admin-signIn-failed', [], User::class, $user->id, 'fa fa-sign-in', 'danger'));
            }
            $plus = $limited['count'] + 1;
            cache()->set('login-limited-' . $request->ip(), [
                'count' => $plus,
                'time' => now()->addMinutes(5), //  5 min after the last time
            ], 300);

            $headers = [
                Headers::ISPA_AUTH_FAILED_REMAINING_COUNT => $plus,
                Headers::ISPA_AUTH_FAILED_REMAINING_MESSAGE => __('mgs.auth.failed-remaining', ['n' => $plus]),
            ];

            if ($plus > 4) {
                $headers[Headers::ISPA_AUTH_FAILED_BLOCK] = 1;
                $headers[Headers::ISPA_AUTH_FAILED_REMAINING_TIME] = now()->addMinutes(5);
            }

            return ires()->failed()->code(200)->headers($headers)->message(__('mgs.auth.failed'));
        }

        try {
            $challenge = $this->adminMfaService->createChallenge(
                $user,
                $request->ip(),
                $request->userAgent(),
                (bool)$request->boolean('remember')
            );
        } catch (RuntimeException $e) {
            return ires()->failed()->code(200)->message($e->getMessage());
        }

        if ($challenge) {
            return ires()->data($this->adminMfaService->buildChallengePayload($challenge));
        }

        $payload = $this->adminMfaService->buildAuthPayload($user);

        /** Audit log sign in */
        event(new AuditLogEvent('admin-signIn-success', [], User::class, $user->id, 'fa fa-sign-in', 'success'));

        //  Reset the cache
        $this->resetLoginThrottle($request->ip());

        return ires()->data($payload)->message(__('mgs.ok.sign-in'));
    }

    /**
     * Sign out: revoke exactly the presented token through TokenRevoker (the sign-out routes are the only revoke path
     * that may end the token carrying the request).
     *
     * @param TokenRevoker $revoker Token revoker.
     * @return mixed
     */
    public function logout(TokenRevoker $revoker): mixed
    {
        $token = user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $revoker->revokeToken($token, null) > 0) {
            return ires()->message(__('mgs.ok.sign-out'));
        }

        return ires()->error()->message(__('mgs.er.sign-out'));
    }

    public function passkeyOptions(PasskeyService $passkeys): mixed
    {
        return ires()->data($passkeys->loginOptions('admin'));
    }

    public function passkeyLogin(PasskeyLoginRequest $request, PasskeyService $passkeys): mixed
    {
        $payload = $request->validated();
        $user = $passkeys->verifyLogin(
            (string) $payload['challenge_id'],
            'admin',
            $request->publicKeyCredential(),
        );

        if (! in_array($user->type, [UserType::WEBMASTER, UserType::ADMIN], true)) {
            throw InvalidPasskeyException::make('Passkey này không có quyền truy cập khu vực quản trị.');
        }

        $challenge = $this->adminMfaService->createChallenge(
            $user,
            $request->ip(),
            $request->userAgent(),
            (bool) ($payload['remember'] ?? false),
        );

        if ($challenge) {
            return ires()->data($this->adminMfaService->buildChallengePayload($challenge));
        }

        $authPayload = $this->adminMfaService->buildAuthPayload($user);
        event(new AuditLogEvent('admin-signIn-success', [], User::class, $user->id, 'fa fa-sign-in', 'success'));
        $this->resetLoginThrottle($request->ip());

        return ires()->data($authPayload)->message(__('mgs.ok.sign-in'));
    }

    public function passkeyRegistrationOptions(PasskeyService $passkeys): mixed
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        return ires()->data($passkeys->registrationOptions($user, 'admin'));
    }

    public function passkeyRegister(PasskeyRegistrationRequest $request, PasskeyService $passkeys): mixed
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $payload = $request->validated();

        $passkey = $passkeys->register(
            $user,
            (string) $payload['challenge_id'],
            'admin',
            (string) $payload['name'],
            $request->publicKeyCredential(),
        );

        return ires()->data(['passkey_id' => (string) $passkey->getKey()]);
    }

    public function passkeys(PasskeyService $passkeys): mixed
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        return ires()->data(['passkeys' => $passkeys->forUser($user)]);
    }

    public function deletePasskey(string $passkey, PasskeyService $passkeys): mixed
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        $passkeys->delete($user, $passkey);

        return ires()->data(['ok' => true]);
    }

    private function resetLoginThrottle(string $ipAddress): void
    {
        cache()->set('login-limited-' . $ipAddress, [
            'count' => 0,
            'time' => now()->addMinutes(5),
        ], 300);
    }

    private function passwordResetForm(string $email): array
    {
        return [
            'email' => $email,
            'step' => 2,
            'type' => 'check-code',
            'password' => null,
            'password_confirmation' => null,
            'code' => null,
            'token' => null,
        ];
    }
}
