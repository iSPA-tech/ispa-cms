<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\TokenGate;
use Closure;
use Cms\Core\Services\Security\TokenAudience;
use Illuminate\Http\Request;
use App\Constants\UserType;

/**
 * The admin gate (alias `api.admin`): only Webmaster and Admin accounts pass, and the bearer token must also
 * pass the shared {@see TokenGate}: issued for the admin audience (or the audiences named as parameters, as in
 * `api.admin:admin,service` on the v3 admin routes), owner active, not idle, not expired; refused in `enforce`, counted in
 * `log`, ignored in `off`. A browser session (no bearer token, as the crawler web pages use) still passes on the account
 * type alone. An expired token is refused in every mode (resolver fix).
 */
class ApiAdminVerifyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): mixed $next
     * @param string ...$audiences Token audiences the route accepts; default `admin`.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$audiences): mixed
    {
        $audiences = $audiences === [] ? [TokenAudience::ADMIN] : $audiences;
        $decision = app(TokenGate::class)->forRequest($request, [...$audiences, TokenGate::SESSION], 'api.admin');
        $user = $decision['owner'];

        if ($user) {
            $request->setUserResolver(static fn () => $user);
        }

        $userType = (int) ($user?->type ?? -1);

        if (!$user instanceof User || !in_array($userType, [UserType::WEBMASTER, UserType::ADMIN], true)) {
            return ires()
                ->unauthorized(__('mgs.no.permission'));
        }

        // A token issued for another audience (for example a staff member's mobile `account` token) never opens the admin
        // API, whatever the token gate mode: that is what lets staff accounts sign in to the apps.
        $tokenAudience = $decision['token']?->getAttribute('audience');
        if (is_string($tokenAudience) && $tokenAudience !== '' && ! in_array($tokenAudience, $audiences, true)) {
            return ires()
                ->unauthorized(__('mgs.no.permission'))
                ->errorCode(TokenGate::errorCode(TokenGate::REASON_AUDIENCE));
        }

        if ($decision['outcome'] === TokenGate::DENY) {
            return ires()
                ->unauthorized(__('mgs.no.permission'))
                ->errorCode(TokenGate::errorCode($decision['reason']));
        }

        return $next($request);
    }
}
