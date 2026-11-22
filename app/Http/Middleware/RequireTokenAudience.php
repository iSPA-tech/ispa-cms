<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TokenGate;
use Closure;
use Illuminate\Http\Request;

/**
 * Alias `api.audience:<audience>[,<audience>...]`: the token that authenticated the request must have been issued
 * for one of the listed API families (`admin`, `account`, `client`, `service`; `session` admits a browser session), its owner
 * must be active and it must not be idle. Placed after `auth:sanctum`; it judges the identity Sanctum's guard chose. The
 * shared {@see TokenGate} decides by the site's mode: `off` lets everything through, `log` counts and lets through,
 * `enforce` answers 401 with an error code `TOKEN_<REASON>`.
 */
class RequireTokenAudience
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request The incoming request.
     * @param Closure(Request): mixed $next The next handler.
     * @param string ...$audiences The audiences the route accepts.
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string ...$audiences): mixed
    {
        $gate = app(TokenGate::class);
        if ($gate->mode() === 'off') {
            return $next($request);
        }

        $decision = $gate->forRequest($request, $audiences, 'api.audience', true);
        if ($decision['outcome'] === TokenGate::DENY) {
            return ires()
                ->unauthorized(__('mgs.no.permission'))
                ->errorCode(TokenGate::errorCode($decision['reason']));
        }

        return $next($request);
    }
}
