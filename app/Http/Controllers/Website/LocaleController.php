<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Support\LocaleManager;
use Cms\Core\Models\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Set Locale
     *-------------------
     *
     * @param string $locale
     *
     * @return RedirectResponse
     *
     * @author malayvuong
     */
    public function index(Request $request, string $locale = 'vi'): RedirectResponse
    {
        $normalizedLocale = LocaleManager::sanitize($locale);

        if (!$normalizedLocale || !LocaleManager::isWebLocaleEnabled($normalizedLocale)) {
            abort(404);
        }

        session(['locale' => $normalizedLocale]);
        app()->setLocale($normalizedLocale);

        //  Get Language and set to cookie
        $lang = cache()->remember('language-locale-' . $normalizedLocale, config('setting.cache.locale', 3600), function () use ($normalizedLocale) {

            return Language::query()->select('id', 'name', 'path', 'path_alias', 'flag', 'currency')->where([
                'path' => $normalizedLocale,
                'for_web' => true,
            ])->first();
        });

        if (!$lang) {
            abort(404);
        }

        session(['current-language' => $lang]);

        $redirectTo = $this->resolveRedirectTarget($request);

        return redirect($redirectTo)->withCookie(cookie('locale', $normalizedLocale));
    }

    private function resolveRedirectTarget(Request $request): string
    {
        $fallback = route('home');
        $previous = url()->previous();

        if ($previous === '' || $previous === $request->fullUrl()) {
            return $fallback;
        }

        $previousPath = parse_url($previous, PHP_URL_PATH);
        if (!is_string($previousPath) || preg_match('#^/lang/[^/]+/?$#', $previousPath) === 1) {
            return $fallback;
        }

        return $previous;
    }
}
