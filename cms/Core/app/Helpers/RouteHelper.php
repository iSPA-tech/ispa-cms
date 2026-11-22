<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Helpers;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

/**
 * Helper for registering locale-aware resource routes.
 *
 * Generates per-locale route groups with translated URL prefixes so that
 * each supported language can have its own slug for the same resource
 * controller. Falls back to a configurable default when no translation
 * is available for a given locale.
 *
 * @package Cms\Core\Helpers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RouteHelper
{
    /**
     * Register localized index and show routes for a set of resource controllers.
     *
     * For each resource key and each configured locale, a route group is
     * created with the translated (or fallback) URL prefix. Two routes
     * are registered per group: "index" (GET /) and "show" (GET {slug}).
     *
     * @param  array  $resources      Map of resource key => controller class
     * @param  array  $fallbacks      Map of resource key => [locale => prefix] fallback translations
     * @param  string $defaultPrefix  Optional URL prefix prepended to all generated routes
     * @return void
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function localizedResources(array $resources, array $fallbacks, string $defaultPrefix = ''): void
    {
        $locales = config('app.locales', array_keys(reset($fallbacks)));
        $defaultLocale = config('app.locale');
        $translations = [];

        foreach ($locales as $locale) {
            $translations[$locale] = Lang::has('routes', $locale)
                ? Lang::get('routes', [], $locale)
                : [];
        }

        foreach ($resources as $key => $controller) {
            foreach ($locales as $locale) {
                $prefix = $translations[$locale][$key]
                    ?? ($fallbacks[$key][$locale] ?? $key);

                $namePrefix = $key . ($locale === $defaultLocale ? '' : '.' . $locale) . '.';

                Route::prefix(trim("{$defaultPrefix}/{$prefix}", '/'))
                    ->as($namePrefix)
                    ->controller($controller)
                    ->group(static function () {
                        Route::get('/', 'index')->name('index');
                        Route::get('{slug}', 'show')
                            ->where('slug', '.*')
                            ->name('show');
                    });
            }
        }
    }
}
