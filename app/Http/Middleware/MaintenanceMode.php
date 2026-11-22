<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode as Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MaintenanceMode extends Middleware
{
    /**
     * Handle maintenance mode.
     *
     * @param Request $request
     * @param  Closure  $next
     * @return mixed
     *
     * @throws HttpException
     */
    public function handle($request, Closure $next): mixed
    {
        if (config('ispa-cms.maintenance') && ! $request->is('storage*') && ! $request->is('api*') && ! $request->is('ipanel*') && ! $request->is('maintenance')) {
            return new RedirectResponse(route('maintenance'));
        }

        return $next($request);
    }
}
