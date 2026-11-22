<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API-only deployments (for example api.example.com) must not serve public template pages
 * in a browser — that is duplicate content against the real frontend. Every
 * direct page view 301s onto the frontend domain at the same path; only the
 * admin panel, the APIs and infrastructure paths stay.
 *
 * OPT-IN per deployment: inert until PUBLIC_WEB_REDIRECT_TARGET is set
 * (config api-host.redirect_page_views_to), so the shared core stays safe
 * for projects whose Laravel app IS the public site. Deliberately
 * project-neutral — any headless host can turn it on.
 */
class RedirectPublicWebToFrontend
{
    /** Path prefixes that keep working on the API domain (lowercased). */
    private const EXEMPT_PREFIXES = [
        'api',            // every API surface, admin APIs included
        'ipanel',         // admin SPA (/iPanel and its sub-paths)
        'health',         // CICD / uptime probes
        'up',
        'storage',        // uploaded media served from this host
        'projects',       // template assets used by the admin previewer
        'preview',        // admin template preview
        'preview-browser',
        'lang',           // locale switch used inside the admin
        'sanctum',
        'broadcasting',
        '_debugbar',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $target = rtrim((string) config('api-host.redirect_page_views_to', ''), '/');
        if ($target === '') {
            return $next($request);
        }

        $path = strtolower(trim($request->path(), '/'));
        if ($path !== '') {
            foreach (self::EXEMPT_PREFIXES as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return $next($request);
                }
            }
        }

        $query = $request->getQueryString();

        return redirect()->away(
            $target . '/' . trim($request->path(), '/') . ($query ? '?' . $query : ''),
            301
        );
    }
}
