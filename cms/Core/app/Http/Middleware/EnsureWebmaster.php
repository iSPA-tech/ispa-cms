<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Lets only Webmasters through (alias `admin.webmaster`). Runs after `auth:sanctum` and `api.admin`, so the user is
 * already a signed-in admin; anyone else gets 403 with errorCode `WEBMASTER_ONLY`.
 */
class EnsureWebmaster
{
    /**
     * Refuse the request unless the signed-in user is a Webmaster.
     *
     * @param Request $request Incoming admin API request.
     * @param Closure(Request): mixed $next Next middleware.
     *
     * @return mixed The next response, or a 403 JSON response.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->isWebmaster()) {
            return ires()->forbidden('Only a Webmaster can use this endpoint.')
                ->errorCode('WEBMASTER_ONLY')
                ->toResponse($request);
        }

        return $next($request);
    }
}
