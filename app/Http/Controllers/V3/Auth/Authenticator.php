<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V3\Auth;

use App\Enums\ErrorCodeEnum;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Cms\Core\Http\Requests\V3\Auth\RefreshRequest;
use Cms\Core\Http\Requests\V3\Auth\SignInRequest;
use Cms\Core\Interfaces\UserInterface;
use Cms\Core\Services\Security\ApiTokenIssuer;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenRevoker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Service sign-in and token refresh of API v3.
 *
 * Tokens are issued through ApiTokenIssuer with the `service` audience. The `secret` and `no_expired`
 * request parameters only choose the lifetime the caller asks for; the lifetime setting of the audience caps it, so
 * they can shorten a token but never lengthen it beyond the setting. Nothing is cached in Redis: the plain token is
 * returned in the response that issued it and nowhere else.
 */
class Authenticator extends Controller
{
    /**
     * Authenticator constructor
     *
     * @param UserInterface $user
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-19, 23:29 ICT
     */
    public function __construct(
        protected UserInterface $user,
    )
    {
        parent::__construct();
    }

    /**
     * Refresh token: rotate the presented token. The new token keeps the audience of the presented one and the
     * presented token is revoked; a token of another audience cannot be exchanged here.
     *
     * @param RefreshRequest $request
     * @param TokenRevoker $revoker Token revoker.
     * @param ApiTokenIssuer $issuer Token issuer.
     *
     * @return mixed
     * @since 7.160.1175 - 2024-04-20, 14:37 ICT
     * @author malayvuong
     */
    public function refresh(RefreshRequest $request, TokenRevoker $revoker, ApiTokenIssuer $issuer)
    {
        //  Get user
        $user = $request->user();

        if (!$user) {
            return ires()->error()->message(__('mgs.auth.failed'));
        }

        $presented = $user->currentAccessToken();
        $presentedAudience = $presented instanceof PersonalAccessToken ? $presented->getAttribute('audience') : null;

        //  A token issued for another API family cannot be exchanged for a service token
        if ($presentedAudience !== null && $presentedAudience !== TokenAudience::SERVICE) {
            return ires()->error()->code(403)->message(__('mgs.no.permission'));
        }

        //  Set no_expired if request has no_expired > 0
        $dateExpired = $this->requestedExpiry($request, $request->input('no_expired', false) === true);

        //  Generate the new token, then revoke the presented one
        $new = $issuer->issue(
            $user,
            TokenAudience::SERVICE,
            (string) $request->input('device', 'web'),
            $user->permissions(),
            $request,
            $dateExpired,
            'v3.refresh'
        );
        if ($presented instanceof PersonalAccessToken) {
            $revoker->revokeToken($presented, null);
        }

        return ires()->data($this->payload($new, $user));
    }

    /**
     * Process login
     *
     * @param SignInRequest $request
     * @param ApiTokenIssuer $issuer Token issuer.
     *
     * @return mixed
     * @since 7.160.1175 - 2024-04-19, 23:30 ICT
     * @author malayvuong
     */
    public function signIn(SignInRequest $request, ApiTokenIssuer $issuer)
    {
        $user = $this->user->findBy(['username' => $request->input('username')]);

        //  Check user
        if (!$user) {
            return ires()->error()->message('#0001 - ' . __('mgs.auth.failed'))->errorCode(ErrorCodeEnum::get('AUTHENTICATION_FAILED'));
        }

        //  Check password
        if (!Hash::check($request->input('password'), $user->password)) {
            return ires()->error()->message('#0002 - ' . __('mgs.auth.failed'));
        }

        //  Set no_expired if request has no_expired > 0
        $dateExpired = $this->requestedExpiry($request, (bool) $request->input('no_expired', false));

        //  Create the token with the device name
        $new = $issuer->issue(
            $user,
            TokenAudience::SERVICE,
            (string) $request->input('device', 'web'),
            $user->permissions(),
            $request,
            $dateExpired,
            'v3.sign-in'
        );

        return ires()->data($this->payload($new, $user));
    }

    /**
     * Lifetime the caller asks for; the issuer caps it with the lifetime setting of the audience.
     *
     * @param Request $request Sign-in or refresh request.
     * @param bool $noExpired Whether the caller asked for the longest lifetime (10 years).
     * @return Carbon
     */
    private function requestedExpiry(Request $request, bool $noExpired): Carbon
    {
        if ($noExpired) {
            return now()->addYears(10);
        }

        return match ($request->input('secret')) {
            'admin' => now()->addMonth(),
            default => now()->addDay(),
        };
    }

    /**
     * Response payload of a freshly issued token. The token is shown here once and never cached.
     *
     * @param NewAccessToken $new Issued token.
     * @param mixed $user Token owner.
     * @return array<string, mixed>
     */
    private function payload(NewAccessToken $new, mixed $user): array
    {
        return [
            'token' => $new->plainTextToken,
            'expired_at' => $new->accessToken->expires_at?->timestamp,
            'type' => 'Bearer',
            'user' => $user,
        ];
    }
}
