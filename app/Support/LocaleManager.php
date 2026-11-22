<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Cms\Core\Models\Language;
use Throwable;

class LocaleManager
{
    /**
     * Return the configured list of supported locales.
     */
    public static function available(): array
    {
        return (array)config('ispa-cms.available_languages', []);
    }

    /**
     * Normalize and validate a locale code against the allowed list.
     */
    public static function sanitize(?string $locale): ?string
    {
        if (!$locale) {
            return null;
        }

        $normalized = str_replace('_', '-', trim($locale));
        if ($normalized === '') {
            return null;
        }

        $available = array_map(static fn($opt) => str_replace('_', '-', trim($opt)), self::available());
        $base = strtolower(strtok($normalized, '-') ?: $normalized);

        // Prefer base locale when both base and regional variants are allowed (e.g. return "vi" instead of "vi-VN").
        $baseCandidates = [];
        foreach ($available as $option) {
            if ($option === '') {
                continue;
            }
            if (strtolower(strtok($option, '-')) === $base) {
                $baseCandidates[] = $option;
            }
        }

        if (!empty($baseCandidates)) {
            foreach ($baseCandidates as $candidate) {
                if (!str_contains($candidate, '-')) {
                    return $candidate;
                }
            }

            return $baseCandidates[0];
        }

        return null;
    }

    /**
     * Extract the highest-priority locale from an Accept-Language header.
     */
    public static function parseAcceptLanguage(?string $header): ?string
    {
        if (!$header) {
            return null;
        }

        foreach (explode(',', $header) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            [$tag] = explode(';', $segment, 2);
            if ($locale = self::sanitize($tag)) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Resolve the best-fit locale for web (session-aware) traffic.
     */
    public static function determineForWeb(Request $request): string
    {
        $sessionLocale = null;
        if ($request->hasSession()) {
            $session = $request->session();
            if ($session->has('locale')) {
                $sessionLocale = self::sanitize($session->get('locale'));
            }
        }
        if ($sessionLocale && self::isWebLocaleEnabled($sessionLocale)) {
            return $sessionLocale;
        }

        if (($cookieLocale = self::sanitize($request->cookie('locale'))) && self::isWebLocaleEnabled($cookieLocale)) {
            if ($request->hasSession()) {
                $request->session()->put('locale', $cookieLocale);
            }

            return $cookieLocale;
        }

        if ($default = self::projectDefaultLocale()) {
            return $default;
        }

        if ($default = self::sanitize(self::defaultLocaleFromDatabase())) {
            return $default;
        }

        if (($headerLocale = self::parseAcceptLanguage($request->header('Accept-Language')))
            && self::isWebLocaleEnabled($headerLocale)) {
            return $headerLocale;
        }

        return self::sanitize(config('app.locale')) ?? config('app.locale', 'en');
    }

    /**
     * Resolve the best-fit locale for stateless API traffic.
     */
    public static function determineForApi(Request $request): string
    {
        if ($explicit = self::sanitize($request->header('X-Locale'))) {
            return $explicit;
        }

        if ($default = self::projectDefaultLocale()) {
            return $default;
        }

        if ($default = self::sanitize(self::defaultLocaleFromDatabase())) {
            return $default;
        }

        if (($headerLocale = self::parseAcceptLanguage($request->header('Accept-Language')))
            && self::isWebLocaleEnabled($headerLocale)) {
            return $headerLocale;
        }

        return self::sanitize(config('app.fallback_locale'))
            ?? config('app.fallback_locale', config('app.locale', 'en'));
    }

    /**
     * Flush cached language lookups for the given locales.
     */
    public static function forgetCache(?string $locale = null, ?string $original = null): void
    {
        Cache::forget('language-locales');
        Cache::forget('enabled-web-language-locales');
        Cache::forget('default_language');

        if ($locale) {
            Cache::forget('language-locale-' . $locale);
        }

        if ($original && $original !== $locale) {
            Cache::forget('language-locale-' . $original);
        }
    }

    protected static function defaultLocaleFromDatabase(): ?string
    {
        $resolve = static function () {
            try {
                return Language::query()->where('default_web', true)->value('path');
            } catch (\Throwable) {
                // No languages table yet (fresh install, migrations pending): fall through to the header and config.
                return null;
            }
        };

        $cached = Cache::get('default_language');
        if ($cached !== null && (!is_string($cached) || $cached === '')) {
            // A shared cache may hold a stale object (e.g. __PHP_Incomplete_Class) written by an older release.
            Cache::forget('default_language');
        }

        $value = Cache::remember('default_language', 86400, $resolve);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected static function projectDefaultLocale(): ?string
    {
        $locale = self::sanitize((string) config('ispa-cms.project_default_locale', ''));

        return $locale && self::isWebLocaleEnabled($locale) ? $locale : null;
    }

    public static function isWebLocaleEnabled(?string $locale): bool
    {
        $normalized = self::sanitize($locale);
        if (!$normalized) {
            return false;
        }

        try {
            return self::webLocales()->contains($normalized);
        } catch (Throwable) {
            return in_array($normalized, self::available(), true);
        }
    }

    protected static function webLocales()
    {
        return Cache::remember('enabled-web-language-locales', 86400, static function () {
            return Language::query()
                ->where('for_web', true)
                ->pluck('path')
                ->map(static fn($locale) => self::sanitize($locale))
                ->filter()
                ->values();
        });
    }
}
