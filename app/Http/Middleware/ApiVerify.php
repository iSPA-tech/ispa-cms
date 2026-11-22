<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiVerify
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): mixed $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $expectedKey = trim((string) config('ispa-cms.api_auth_key', ''));
        $providedKey = trim((string) $request->header('ISPA-AUTH-KEY', ''));

        if ($expectedKey === '' ||
            $providedKey === '' ||
            !hash_equals($expectedKey, $providedKey)) {
            return ires()
                ->unauthorized('ISPA_AUTH_KEY: ' . __('mgs.auth.failed'));
        }

        return $next($request);
    }
}
