<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Helpers;

use Exception;
use Throwable;
use App\Constants\Constant;
use Illuminate\Support\Str;
use __PHP_Incomplete_Class;
use Cms\Core\Models\Language;
use App\Support\LocaleManager;
use Stevebauman\Location\Position;
use Illuminate\Support\Collection;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Schema;
use Stevebauman\Location\Facades\Location;
use Intervention\Image\Drivers\Imagick\Driver;

class AppHelpers extends Facade
{
    /**
     * @return $this
     */
    public static function getInstance(): self
    {
        return static::getFacadeRoot();
    }

    /**
     * Process to crop image
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-02-27, 00:41 ICT
     */
    public static function cropImage(
        string $disk,
        string $sourcePath,
        string $outPutPath,
        int    $width = 0,
        int    $height = 0,
        string $extension = 'webp'
    ): void
    {
        try {
            //  Check if original image exists in storage -> return original image path
            if (Storage::disk($disk)->exists($sourcePath)) {
                $filePath = Storage::disk($disk)->path($sourcePath);
            } else {
                //  Else return default image path
                $filePath = public_path('images/default.jpeg');
            }
            ini_set('memory_limit', '1024M');
            //  Process to crop image here
            $manager = new ImageManager(new Driver());
            $image = $manager->read($filePath);
            // If no height => resize by width
            if (empty($height) || $height < 50) {
                $image = $image->scale($width);
            }
            //  Crop image with width and height
            if ($width > 50 && $height > 50) {
                $image = $image->cover($width, $height);
            }

            //  Same treatment the variant service gives its derivatives: a
            //  source photo's EXIF/XMP/8BIM can outweigh the pixels of a small
            //  crop several times over. Non-sRGB ICC profiles are kept, so
            //  wide-gamut images do not come out flat.
            app(\Cms\Core\Services\ImageVariantService::class)->stripMetadata($image);

            if (in_array($extension, ['jpg', 'jpeg'])) {
                $encoded = $image->toJpeg(($width <= 300 || $height <= 300) ? 50 : 75);
            } else if ($extension === 'png') {
                $encoded = $image->toPng();
            } else if ($extension === 'gif') {
                $encoded = $image->toGif();
            } else {
                $encoded = $image->toWebp(($width <= 300 || $height <= 300) ? 50 : 75);
            }
            //  Save image to destination
            Storage::disk($disk)->put($outPutPath, $encoded);
        } catch (Exception $e) {
            //  Log error
            logger($e->getMessage());
        }
    }

    /**
     * Model relationship helper
     *
     * @param array $with
     *
     * @return array
     * @author malayvuong
     * @since 6.62.6 - 2022-08-21, 10:41 ICT
     */
    public static function modelRelationshipHelper(array $with = []): array
    {
        $relation = [];
        if (in_array(Constant::POSTS, collect($with)->keys()->toArray(), true)) {
            if (is_callable($with[Constant::POSTS])) {
                $relation[Constant::POSTS] = $with[Constant::POSTS];
            } else {
                $relation[Constant::POSTS] = static function ($q) use ($with) {
                    $q->where(function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    })->published()->whereType('post')->select(
                        'id', 'name', 'description', 'video_url', 'config', 'user_id', 'published_at'
                    )->with([
                        'slugs:target_id,slug',
                        'author:id,name',
                    ]);
                };
            }
        }

        return $relation;
    }

    /**
     * Generate key for cache
     *
     * @param array $data
     * @param string $separator
     *
     * @return string
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public static function makeKey(array $data = [], string $separator = '|'): string
    {
        return implode($separator, array_map(static fn($v, $k) => sprintf('%s:%s', $k, $v), $data, array_keys($data)));
    }

    /**
     * Get Location from IP Address
     *
     * @return Position|bool
     *
     * @author malayvuong
     *
     * @since  1.0.0
     */
    public static function getLocation(): Position|bool
    {
        // Outside production a developer may set LOCATION_FALLBACK_IP, since a local request comes from 127.0.0.1.
        $fallback = (string) config('ispa-cms.location_fallback_ip', '');
        $ip = ! app()->environment('production') && $fallback !== '' ? $fallback : request()->ip();

        return cache()->remember($ip, 3600, function () use ($ip) {
            return Location::get($ip);
        });
    }

    /**
     * Get all languages for website
     *
     * @author malayvuong
     * @since 6.5.5 - 2022-03-5, 16:50
     */
    public static function getLanguages(): mixed
    {
        $cacheKey = 'language-locales';
        $fallback = collect([self::makeFallbackLanguage(config('app.locale', 'en'))]);

        if (self::shouldUseLanguageFallback()) {
            return $fallback;
        }

        $languages = cache()->get($cacheKey);

        if ($languages instanceof __PHP_Incomplete_Class
            || ($languages && !$languages instanceof Collection)
            || ($languages instanceof Collection
                && $languages->first() instanceof __PHP_Incomplete_Class)) {
            cache()->forget($cacheKey);
            $languages = null;
        }

        if (!$languages) {
            try {
                $languages = Language::query()
                    ->where([
                        'for_web' => true,
                    ])
                    ->get();
            } catch (Throwable $exception) {
                if (self::shouldUseLanguageFallback()) {
                    return $fallback;
                }

                throw $exception;
            }

            cache()->put($cacheKey, $languages, 86400);
        }

        return $languages;
    }

    /**
     * Check the language session and return
     *
     * @throws Throwable
     * @since 1.0.0
     * @last_updated 7.160.1175 - 2025-01-04, 00:03 ICT
     * @author malayvuong
     */
    public static function getLanguage()
    {
        static $inProgress = false;
        // The resolved language, per application instance (a request, or one test): a process-wide value would carry
        // one test's language into the next.
        static $cachedLanguage = null;
        $cachedLanguage ??= new \WeakMap();
        $app = app();

        if ($inProgress) {
            return (object)[
                'path' => config('app.locale', getenv('DEFAULT_LOCALE')),
                'id' => get_lang_id(),
            ];
        }

        if (isset($cachedLanguage[$app])) {
            return $cachedLanguage[$app];
        }

        $inProgress = true;

        $currentLocale = LocaleManager::sanitize(app()->getLocale())
            ?? LocaleManager::sanitize(config('app.locale'))
            ?? config('app.locale', 'en');

        $lang = static::resolveLanguageByPath($currentLocale);

        if (!$lang) {
            $fallbackLocale = LocaleManager::sanitize(config('app.locale')) ?? $currentLocale;

            if ($fallbackLocale !== $currentLocale) {
                $lang = static::resolveLanguageByPath($fallbackLocale);
            }

            if (!$lang) {
                $lang = (object)[
                    'path' => $fallbackLocale,
                    'id' => get_lang_id(),
                ];
            }
        }

        if ($lang && app()->bound('session')) {
            Session::put('current-language', $lang);
        }

        // A fallback made while the languages table is missing (routes load before the first migration) must not stick.
        if (!self::shouldUseLanguageFallback()) {
            $cachedLanguage[$app] = $lang;
        }
        $inProgress = false;

        return $lang;
    }

    /**
     * Resolve language model by locale path with cache safety for legacy entries.
     *
     * @throws Throwable
     */
    protected static function resolveLanguageByPath(string $locale): mixed
    {
        if (self::shouldUseLanguageFallback()) {
            return self::makeFallbackLanguage($locale);
        }

        $cacheKey = 'language-locale-' . $locale;

        $lang = cache()->get($cacheKey);

        if (($lang && !$lang instanceof Language)) {
            cache()->forget($cacheKey);
            $lang = null;
        }

        if (!$lang) {
            try {
                $lang = Language::query()
                    ->select('id', 'name', 'path', 'path_alias', 'flag', 'currency')
                    ->where('path', $locale)
                    ->first();
            } catch (Throwable $exception) {
                if (self::shouldUseLanguageFallback()) {
                    return self::makeFallbackLanguage($locale);
                }

                throw $exception;
            }

            if ($lang) {
                cache()->put($cacheKey, $lang, config('setting.cache.locale', 3600));
            }
        }

        return $lang ?? (self::shouldUseLanguageFallback() ? self::makeFallbackLanguage($locale) : null);
    }

    protected static function makeFallbackLanguage(string $locale): object
    {
        $locale = LocaleManager::sanitize($locale) ?? config('app.locale', 'en');

        return (object)[
            'id' => 0,
            'name' => Str::upper(str_replace(['_', '-'], ' ', $locale)),
            'path' => $locale,
            'path_alias' => $locale,
            'flag' => null,
            'currency' => null,
        ];
    }

    protected static function shouldUseLanguageFallback(): bool
    {
        if ((bool) config('ispa-cms.skip_languages_query', false)) {
            return true;
        }

        if (!app()->runningInConsole()) {
            return false;
        }

        // Only a found table is remembered, and per application instance: a missing table (fresh install,
        // migrations still running, a database rebuilt after boot) must be re-checked, or a stale answer would stick.
        static $tableFound = null;
        $tableFound ??= new \WeakMap();
        $app = app();

        if (isset($tableFound[$app])) {
            return false;
        }

        try {
            if (Schema::hasTable('languages')) {
                $tableFound[$app] = true;

                return false;
            }
        } catch (Throwable $exception) {
            return true;
        }

        return true;
    }

    /**
     * Get changes from edit Model
     *-------------------
     *
     * @param mixed $model
     *
     * @return array
     * @author malayvuong
     */
    public static function getChanges(mixed $model = null): array
    {
        $changed = [];
        if ($model->isDirty()) {
            $changeTo = $model->getDirty();
            //  Get Changed
            $changed = [
                'from' => collect(
                    $model->getRawOriginal()
                )->only(
                    collect($changeTo)->keys()->all()
                )->toArray(),
                'to' => $changeTo,
            ];
        }

        return $changed;
    }

    /**
     * Get RANK ID from Text name
     *-------------------
     *
     * @param string|null $rank | Rank Name
     *
     * @return int $id
     *
     * @author malayvuong
     */
    public static function rankId(?string $rank = null): int
    {
        switch ($rank) {
            case 'Kingdom':
            case 'kingdom':
                $id = 1;
                break;
            case 'Phylum':
            case 'phylum':
                $id = 2;
                break;
            case 'Class':
            case 'class':
                $id = 3;
                break;
            case 'Order':
            case 'order':
                $id = 4;
                break;
            case 'Family':
            case 'family':
                $id = 5;
                break;
            case 'Genus':
            case 'genus':
                $id = 6;
                break;
            case 'Species':
            case 'species':
                $id = 7;
                break;
            case 'Infraspecies':
            case 'subspecies':
                $id = 8;
                break;

            default:
                $id = 0;
                break;
        }

        return $id;
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-22, 18:01 ICT
     */
    protected static function getFacadeAccessor(): string
    {
        return 'AppHelpers';
    }
}
