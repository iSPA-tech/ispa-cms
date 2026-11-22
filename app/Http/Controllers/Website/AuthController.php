<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\Website;

use Exception;
use App\Enums\ErrorCodeEnum;
use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Routing\Redirector;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Cms\Core\Events\AuditLogEvent;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\Foundation\Application;
use Cms\Core\Http\Requests\Auth\ClientSignInRequest;

class AuthController extends Controller
{
    /**
     * AuthController constructor.
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 11:14 ICT
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware('guest')->except('signOut');
    }

    /**
     * Show register page
     *
     * @return Factory|View|Application
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 11:35 ICT
     */
    public function register(): Factory|View|Application
    {
        return view('pages.auth.register');
    }

    /**
     * Post to sign in
     *
     * @param ClientSignInRequest $request
     *
     * @return mixed
     *
     * @since 6.19.6 - 2022-06-08, 16:51 ICT
     * @author malayvuong
     */
    public function postSignIn(ClientSignInRequest $request): mixed
    {
        $credentials = $request->only('username', 'password');
        $submittedUsername = (string)$credentials['username'];
        $remember = (bool)$request->input('remember', $request->input('remember-me', false));

        if (filter_var($credentials['username'], FILTER_VALIDATE_EMAIL)) {
            $credentials['email'] = $credentials['username'];
            unset($credentials['username']);
        }

        if (!Auth::attempt($credentials, $remember)) {
            $this->auditFailedSignIn($submittedUsername, (string)$credentials['password']);

            return $this->failedSignInResponse(
                $request,
                __('mgs.auth.failed'),
                ErrorCodeEnum::get('AUTHENTICATION_FAILED')
            );
        }

        if (user('status', '-') !== UserStatusEnum::USER_ACTIVE->value) {
            /** @var User $authenticatedUser */
            $authenticatedUser = Auth::user();
            Auth::logout();
            $this->auditFailedSignIn(
                $submittedUsername,
                (string)$credentials['password'],
                $authenticatedUser,
                'account-not-active'
            );

            return $this->failedSignInResponse(
                $request,
                __('mgs.auth.banned'),
                ErrorCodeEnum::get('ACCOUNT_BANNED')
            );
        }

        event(new AuditLogEvent('sign-in', [], User::class, auth()->id(), 'fa fa-sign-in-alt', 'text-green-600'));

        $fallbackUrl = Route::has('account.dashboard')
            ? route('account.dashboard')
            : (Route::has('home') ? route('home') : url('/'));

        if ($request->expectsJson()) {
            return ires()->message(__('mgs.ok.sign-in'))->data([
                'redirect_url' => session()->pull('url.intended', $fallbackUrl),
            ]);
        }

        return redirect()->intended($fallbackUrl)
            ->with('success', __('mgs.auth.success'));
    }

    private function failedSignInResponse(
        ClientSignInRequest $request,
        string $message,
        ?string $errorCode
    ): mixed {
        if ($request->expectsJson()) {
            return ires()->error()->message($message)->errorCode($errorCode);
        }

        return redirect()->back()
            ->withInput($request->except('password'))
            ->with('errors', [$message]);
    }

    private function auditFailedSignIn(
        string $submittedUsername,
        string $password,
        ?User $matchedUser = null,
        ?string $reason = null
    ): void {
        $identifierType = filter_var($submittedUsername, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $matchedUser ??= User::query()
            ->whereRaw("LOWER({$identifierType}) = ?", [Str::lower($submittedUsername)])
            ->first();

        $canonicalIdentifier = $matchedUser?->{$identifierType};
        $caseMismatch = is_string($canonicalIdentifier)
            && $canonicalIdentifier !== $submittedUsername
            && Str::lower($canonicalIdentifier) === Str::lower($submittedUsername);

        if ($reason === null) {
            $passwordMatches = $matchedUser !== null
                && is_string($matchedUser->password)
                && Hash::check($password, $matchedUser->password);
            $reason = match (true) {
                $matchedUser === null => 'user-not-found',
                !$passwordMatches => 'password-mismatch',
                $caseMismatch => 'identifier-case-mismatch',
                default => 'authentication-failed',
            };
        }

        $changed = ['username' => $submittedUsername];
        if ($matchedUser !== null) {
            $changed['canonical_username'] = $matchedUser->username;
        }
        $changed['identifier_type'] = $identifierType;
        $changed['reason'] = $reason;
        $changed['case_mismatch'] = $caseMismatch;
        if ($reason === 'account-not-active') {
            $changed['account_status'] = $matchedUser?->status;
        }

        event(new AuditLogEvent(
            'public-client-signIn-failed',
            $changed,
            User::class,
            $matchedUser?->id ?? 0,
            'fa fa-sign-in',
            'danger'
        ));
    }

    /**
     * Authenticator view
     *
     * @return Factory|View|Application
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-07, 02:16 ICT
     */
    public function signIn(): Factory|View|Application
    {
        return view('pages.auth.sign-in');
    }

    /**
     * Process to sign out of account
     *
     * @return Redirector|Application|RedirectResponse
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 11:17 ICT
     */
    public function signOut(): Redirector|Application|RedirectResponse
    {
        Auth::logout();
        session()->flush();
        session()->regenerate();

        return redirect(route('auth.sign-in'));
    }
}
