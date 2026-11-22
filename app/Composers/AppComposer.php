<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Composers;

use App\Helpers\AppHelpers;
use Cms\Core\Models\Language;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;

class AppComposer
{
    public function compose(): void
    {
        $cacheTime = config('setting.cache.locale', 3600);

        $currentLanguage = Cookie::has('locale') ? Cookie::get('locale') : config('app.locale');

        if (!Session::has('language-locale-' . $currentLanguage)) {
            Session::put(
                'language-locale-' . $currentLanguage,
                cache()->remember(
                    'language-locale-' . $currentLanguage, $cacheTime,
                    function () use ($currentLanguage) {
                        return Language::query()->select('id', 'name', 'path', 'path_alias', 'flag', 'currency')
                            ->where([
                                'path' => $currentLanguage,
                            ])->first();
                    })
            );
        }
        $language = Session::get('language-locale-' . $currentLanguage);

        if (!Session::has('language-locales')) {
            Session::put(
                'language-locales',
                AppHelpers::getLanguages()
            );
        }

        Session::put('current-language', $language);
    }
}
