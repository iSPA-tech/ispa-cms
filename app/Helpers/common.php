<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Enums\StatusEnum;
use App\Helpers\AppHelpers;
use Illuminate\Support\Str;
use Cms\Core\Models\MetaClass;
use App\Jobs\RequestCropImageJob;
use Illuminate\Support\Facades\Log;
use Cms\Core\Models\MetaValueBigNum;
use Cms\Core\Models\MetaValueTinyNum;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Services\CMSSettingUnified;
use Illuminate\Database\Eloquent\Builder;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

if (!function_exists('mb_split')) {
    /**
     * Polyfill for mb_split when mbstring is unavailable on the host.
     */
    function mb_split($pattern, $string, $limit = -1)
    {
        if ($pattern === '') {
            return false;
        }

        $delimiter = '~';
        $escapedPattern = str_replace($delimiter, '\\' . $delimiter, $pattern);
        $regex = $delimiter . $escapedPattern . $delimiter . 'u';

        return preg_split($regex, $string, $limit);
    }
}

if (!function_exists('mb_strimwidth')) {
    /**
     * Simple polyfill for mb_strimwidth using Unicode-safe splitting.
     */
    function mb_strimwidth($string, $start, $width, $trimMarker = '', $encoding = 'UTF-8')
    {
        if ($width <= 0) {
            return '';
        }

        $chars = preg_split('//u', (string)$string, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            return substr($string, $start, $width);
        }

        if ($start > 0) {
            $chars = array_slice($chars, $start);
        }

        $trimChars = $trimMarker !== '' ? preg_split('//u', $trimMarker, -1, PREG_SPLIT_NO_EMPTY) : [];
        $trimLen = is_array($trimChars) ? count($trimChars) : 0;

        if (count($chars) <= $width) {
            return implode('', array_slice($chars, 0, $width));
        }

        $visibleWidth = max(0, $width - $trimLen);
        $visible = array_slice($chars, 0, $visibleWidth);

        if ($trimLen > 0 && is_array($trimChars)) {
            $visible = array_merge($visible, $trimChars);
        }

        return implode('', $visible);
    }
}

if (!function_exists('iSetting')) {
    /**
     * Unified setting getter for runtime layers (env/private/public).
     *
     * Resolution order:
     * 1) metadata lookup by setting_key
     * 2) env via Setting.name for isEnv=true
     * 3) Redis private runtime map for isPublic=false
     * 4) config('setting.*') for public settings
     *
     * Use this helper for API/runtime keys. Do not use iSeo() for env/private/api keys,
     * because iSeo() is a frontend SEO mapper.
     *
     * @param string $settingKey
     * @param mixed $default
     * @param array $options Supported: ['allow_db_fallback' => bool]
     *
     * @return mixed
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @author malayvuong
     * @since 7.160.1175 - 2025-08-22, 14:39 ICT
     */
    function iSetting(string $settingKey, mixed $default = null, array $options = []): mixed
    {
        return app(CMSSettingUnified::class)->get($settingKey, $default, $options);
    }
}


if (!function_exists('route_locale')) {
    /**
     * Normalize a locale string so it can be safely used inside route names.
     *
     * @throws Throwable
     */
    function route_locale(?string $locale = null, ?string $fallback = null): string
    {
        $fallback = $fallback ?? config('app.locale', 'en');
        $locale = $locale ?? lang('path', $fallback);

        if (blank($locale)) {
            return $fallback;
        }

        $normalized = Str::of($locale)
            ->lower()
            ->before('-')
            ->before('_')
            ->trim();

        return $normalized->isEmpty() ? $fallback : $normalized->toString();
    }
}

if (!function_exists('product_permalink')) {
    /**
     * Build a safe product detail URL.
     *
     * Returns null when the product is missing a slug or the route is undefined so callers can fall back gracefully.
     */
    function product_permalink(mixed $product, ?string $locale = null): ?string
    {
        $slug = data_get($product, 'slug');

        if (blank($product) || blank($slug)) {
            return null;
        }

        $normalizedLocale = route_locale($locale, config('app.locale', 'en'));
        $routeName = sprintf('product.show.%s', $normalizedLocale);

        if (!Route::has($routeName)) {
            Log::warning('Attempted to build product permalink for missing route.', [
                'route' => $routeName,
                'product_id' => data_get($product, 'id'),
                'locale' => $normalizedLocale,
            ]);

            return null;
        }

        return route($routeName, ['slug' => $slug]);
    }
}

if (!function_exists('get_project_list')) {
    /**
     * Get project list
     *
     * @return array
     * @author malayvuong
     * @since 7.160.1175 - 2024-02-03, 19:15 ICT
     */
    function get_project_list(): array
    {
        //  Get all project folder names from base_path/projects
        $dirs = glob(base_path('projects/*'), GLOB_ONLYDIR);
        $projects = [];
        foreach ($dirs as $template) {
            $explode = explode('/', $template);
            $projects[] = $explode[(count($explode) - 1)];
        }

        return $projects;
    }
}

if (!function_exists('get_language_code')) {
    /**
     * Get language code for browser detection place into html tag (lang=xx)
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-05-02, 11:21 ICT
     */
    function get_language_code(): string
    {
        $language = lang('path_alias', 'vi');

        //  Check if language includes - (ex: en-US)
        if (str($language)->contains('-')) {
            //  Get first part of language
            $languageArr = explode('-', $language);
        } else {
            //  Get first part of language
            $languageArr = explode('_', $language);
        }

        return $languageArr[0] ?? 'vi';
    }
}

if (!function_exists('project_path')) {
    /**
     * Get project path
     *
     * @param string|null $path
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2024-02-03, 19:15 ICT
     */
    function project_path(?string $path = null): string
    {
        $projectRoot = 'projects/' . config('ispa-cms.folder_name');

        if (!empty($path)) {
            //  Trim left / and right / if path exists
            $path = trim($path, '/');

            return base_path($projectRoot . '/' . $path);
        }

        return base_path($projectRoot);
    }
}

if (!function_exists('get_currency_name')) {
    /**
     * Get currency name by currency symbol (đ => VND, $ => USD, ...)
     *
     * @param string $currency
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2025-04-19, 16:35 ICT
     */
    function get_currency_name(string $currency = 'đ'): string
    {
        return match ($currency) {
            'đ' => 'VND',
            '$' => 'USD',
            '¥' => 'JPY',
            '₩' => 'KRW',
            '£' => 'GBP',
            '€' => 'EUR',
            default => $currency,
        };
    }
}

if (!function_exists('get_post_route_by_type')) {
    /**
     * Get Post router name by type
     *
     * @param string $type
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2024-02-03, 19:15 ICT
     */
    function get_post_route_by_type(string $type = 'post'): string
    {
        return match ($type) {
            'document' => 'document.show.' . lang('path', getenv('DEFAULT_LOCALE', 'vi')),
            default => 'article.' . lang('path', getenv('DEFAULT_LOCALE', 'vi')),
        };
    }
}

if (!function_exists('get_lang_id')) {
    /**
     * Get default language ID
     *
     * @return int
     * @author malayvuong
     * @since 7.160.1175 - 2024-02-03, 19:15 ICT
     */
    function get_lang_id(): int
    {
        $locale = Cookie::get('locale');

        if (empty($locale)) {
            $locale = getenv('DEFAULT_LOCALE', 'vi');
        }

        return $locale === 'vi' ? 1 : 2;
    }
}

if (!function_exists('get_sale_price')) {
    /**
     * Get sale price
     *
     * @param mixed $variant
     * @param string $type
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-31, 23:13 ICT
     */
    function get_sale_price(mixed $variant = null, string $type = 'string'): string
    {
        if (empty($variant)) {
            return '';
        }
        $salePrice = 0;
        if (
            $variant->sale_price > 0
            && $variant->sale_price < $variant->sell_price
            && !empty($variant->sale_from_date)
            && !empty($variant->sale_to_date)
        ) {
            $salePrice = $variant->sale_price;
        }

        if (in_array($type, ['string', 'number_formatted'], true)) {
            $currency = lang('currency', '$');
            $salePrice = number_format($salePrice) . $currency;
        }

        return $salePrice;
    }
}

if (!function_exists('get_variant_name')) {
    /**
     * Get variant name by options
     *
     * @param mixed $variant
     * @param mixed $first
     * @param mixed $second
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-27, 20:30 ICT
     */
    function get_variant_name(mixed $variant, mixed $first, mixed $second): string
    {
        if (empty($variant) || (empty($first) && empty($second))) {
            return '';
        }

        $name = '';
        //  Get name by first index of $first->options
        if ($variant->first_option > -1 && !empty($first)) {
            $name .= $first->options[$variant->first_option] ?? '';
        }
        //  Get name by second index of $second->options
        if ($variant->second_option > -1 && !empty($second)) {
            $name .= ' ' . ($second->options[$variant->second_option] ?? '');
        }

        return str($name)->trim();
    }
}

if (!function_exists('get_price')) {
    /**
     * Get price of variant
     * - if type is number, return only sell price or sale price upon sale date
     * - if type is string, return both sell price and sale price upon sale date with currency and format and text line-through
     *
     * @param mixed $variant
     * @param string $type
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-26, 22:06 ICT
     */
    function get_price(mixed $variant = null, string $type = 'string'): string
    {
        if (empty($variant)) {
            return '';
        }

        $newPrice = $variant->sell_price ?? 0;
        $oldPrice = 0;

        //  if sale_price > 0 and sale_price < sell_price and have sale_from_date and sale_to_date
        if (
            $variant->sale_price > 0
            && $variant->sale_price < $variant->sell_price
            && !empty($variant->sale_from_date)
            && !empty($variant->sale_to_date)
            && now()->between($variant->sale_from_date, $variant->sale_to_date)
        ) {
            $newPrice = $variant->sale_price;
            $oldPrice = $variant->sell_price;
        }

        if (in_array($type, ['string', 'number_formatted'], true)) {
            $currency = lang('currency', 'đ');
            $currencyPrefix = lang('path') !== 'vi' ? $currency : '';
            $currencySuffix = lang('path') !== 'vi' ? '' : $currency;
            $newPrice = $currencyPrefix . number_format($newPrice, lang('path') !== 'vi' ? 2 : 0) . $currencySuffix;
            $oldPrice = $oldPrice > 0 ? $currencyPrefix . number_format($oldPrice, lang('path') !== 'vi' ? 2 : 0) . $currencySuffix : '';
        }

        return in_array($type, ['number', 'number_formatted'], true) ? $newPrice : "<span class='price-new'>$newPrice</span>
			" . ($oldPrice ? "<span class='text-sm lg:text-base line-through text-gray-500 dark:text-gray-300'>$oldPrice</span>" : "");
    }
}

if (!function_exists('get_lowest_price')) {
    /**
     * Get the lowest price
     *
     * @param Countable|array $variants
     *
     * @return object
     * @author malayvuong
     * @since 1.0.0
     * @last_updated 7.160.1175 - 2023-12-26, 22:00 ICT - by malayvuong
     */
    function get_lowest_price(Countable|array $variants): object
    {
        $returnPrice = (object)[];
        foreach ($variants as $key => $variant) {
            if ($variant->status !== StatusEnum::PUBLISHED->value) {
                continue;
            }

            if ($key === 0 || $variant->sell_price < $returnPrice->sell_price) {
                $returnPrice = $variant;
            }
        }

        return $returnPrice;
    }
}
if (!function_exists('get_meta_value_by_name')) {
    /**
     * Generate post route by type
     *
     * @param int $attrId
     * @param array $meta
     * @param string|null $return
     *
     * @return string
     * @since 7.160.1175 - 2023-11-23, 20:30 ICT
     * @author malayvuong
     */
    function get_meta_value_by_name(int $attrId, array $meta = [], ?string $return = null): string
    {
        $collection = collect($meta);

        $attr = $collection->where('meta_attribute_id', $attrId)->first();

        if (empty($attr)) {
            return $return === 'id' ? '0' : '';
        }

        $value = $attr['value']['value'] ?? '';

        //  If attribute is number type
        if (in_array($attr['value_type'], [MetaValueTinyNum::class, MetaValueBigNum::class], true)) {
            $value = $attr['value']['id'] ?? 0;
        }

        if ($return === 'id') {
            $value = $attr['value']['id'] ?? 0;
        }

        if ($attr['attribute']['type'] === 'date' && empty($return)) {
            $value = now()->parse($value)->setTimezone(get_timezone())->format('Y-m-d');
        }

        return $value;
    }
}

if (!function_exists('post_route')) {
    /**
     * Generate post route by type
     *
     * @param Model|Builder $model
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-23, 20:30 ICT
     */
    function post_route(Model|Builder $model): string
    {
        $type = $model->type ?? 'post';
        $route = match ($type) {
            'post' => 'article.' . lang('path', 'vi'),
            'page' => 'page.detail',
            'product' => 'product.detail',
            default => 'blog.detail',
        };
        $cateSlug = $model->category->slug ?? 'no-slug';
        $slug = $model->slug ?? 'no-slug';

        return route($route, ['slug' => $slug, 'cate' => $cateSlug]);
    }
}

if (!function_exists('meta_data_collection')) {
    /**
     * Get value of metadata by type of target
     *
     * @param mixed $metadata
     * @param string $type
     *
     * @return object
     * @throws JsonException
     * @since 6.62.6 - 2023-08-02, 09:39 ICT
     * @author malayvuong
     */
    function meta_data_collection(mixed $metadata, string $type = 'User'): object
    {
        //  Set meta data
        $meta = [];
        foreach ($metadata as $value) {
            $val = $value->value->value ?? '';
            if (
                ($value->attribute->input_type === 'select'
                    && $value->attribute->type === 'string')
                || $value->attribute->name === 'address_object') {
                $val = json_decode($val, true, 512, JSON_THROW_ON_ERROR);
            }

            $meta[$value->attribute->name] = [
                'meta_id' => $value->id,
                'class_id' => $value->meta_class_id,
                'type' => $value->attribute->type,
                'id' => $value->meta_attribute_id,
                'value' => $val ?? '',
                'options' => $value->attribute->options ?? [],
                'original_value' => $value->value ?? [],
            ];
        }

        $classes = MetaClass::query()->where('target', $type)->with('options')->get();
        foreach ($classes as $class) {
            foreach ($class->options as $attribute) {
                if (isset($meta[$attribute->name])) {
                    continue;
                }

                $meta[$attribute->name] = [
                    'meta_id' => 0,
                    'class_id' => $class->id,
                    'type' => $attribute->type,
                    'id' => $attribute->id,
                    'value' => $attribute->input_type !== 'select' ? '' : [],
                ];
            }
        }

        return (object)$meta;
    }
}

if (!function_exists('user_avatar_url')) {
    /**
     * Resolve the best public avatar URL for a user with a shared website fallback.
     */
    function user_avatar_url(mixed $user = null, ?string $fallback = null): string
    {
        if ($fallback === null) {
            $fallback = asset('images/avatar.svg');
        }

        if (!$user) {
            return $fallback;
        }

        $avatar = data_get($user, 'avatar');
        $userId = data_get($user, 'id');

        if ($user instanceof Model && method_exists($user, 'avatar')) {
            try {
                $user->loadMissing('avatar');
                $avatar = $user->getRelation('avatar') ?? $avatar;
                $userId ??= $user->getKey();
            } catch (\Throwable) {
                // Keep the current payload and attempt a direct lookup below.
            }
        }

        if (!$avatar && !empty($userId)) {
            try {
                $avatar = \Cms\Core\Models\Upload::query()
                    ->where('target_type', \App\Models\User::class)
                    ->where('target_id', $userId)
                    ->where(function (Builder $query) {
                        $query->where('category', 'avatar')
                            ->orWhere('type', 'avatar');
                    })
                    ->orderByDesc('main')
                    ->orderByDesc('id')
                    ->first();
            } catch (\Throwable) {
                $avatar = null;
            }
        }

        if (!$avatar) {
            return $fallback;
        }

        if (is_string($avatar) && $avatar !== '') {
            return $avatar;
        }

        foreach (['thumbnail_sm', 'thumb_url', 'thumbnail_md', 'url'] as $key) {
            $value = data_get($avatar, $key);
            if (!empty($value)) {
                return $value;
            }
        }

        return $fallback;
    }
}

//  Crop image with width and height
if (!function_exists('crop_image')) {
    /**
     * Crop image with width and height, store into storage folder
     *
     * @param string $disk Driver storage
     * @param string $inputPath Input path
     * @param string $fileName Original file name
     * @param string|null $outPutPath Out put path
     * @param int $width Out put width
     * @param int $height Out put height
     * @param string $outPutExtension webp, jpg, jpeg, png, gif
     *
     * @return string
     * @author malayvuong
     * @since 6.62.6 - 2023-02-18, 11:21 ICT
     */
    function crop_image(
        string  $disk,
        string  $inputPath,
        string  $fileName,
        ?string $outPutPath = null,
        int     $width = 0,
        int     $height = 0,
        string  $outPutExtension = 'webp'
    ): string
    {
        //  Set source path by input path and file name
        $sourcePath = rtrim($inputPath, '/') . '/' . $fileName;
        try {
            //  Compile output name
            $outPutName = $fileName . '.' . $width . 'x' . $height . '.' . $outPutExtension;
            //  Check if output path is empty, set output path = input path
            if (empty($outPutPath)) {
                $outPutPath = $inputPath;
            }
            //  Set the Output Path
            $outPutPath = rtrim($outPutPath, '/') . '/' . $outPutName;
            //  Check if image exists in storage destination -> return path
            if (Storage::disk($disk)->exists($outPutPath)) {
                return $outPutPath;
            }
            //  Check if empty input path -> return original image path
            if ($width < 1 && $height < 1) {
                return $sourcePath;
            }
            //  If height or width is small size -> process to execute crop image instantly
            if ($width <= 300 && $height <= 300) {
                AppHelpers::cropImage($disk, $sourcePath, $outPutPath, $width, $height, $outPutExtension);
            } else {
                //  Send convert request to job queue
                RequestCropImageJob::dispatch($disk, $sourcePath, $outPutPath, $width, $height, $outPutExtension);
            }

            //  Check if image exists in storage destination -> return path
            if (Storage::disk($disk)->exists($outPutPath)) {
                return $outPutPath;
            }
        } catch (Throwable $e) {
            Log::error($e->getMessage());
            //  Return original image path if it'd any error
            return $sourcePath;
        }

        return $outPutPath;
    }
}

if (!function_exists('get_file_type_by_group')) {
    /**
     * Get file type by group name
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-08-22, 00:58 ICT
     */
    function get_file_type_by_group(string $group): array
    {
        return match ($group) {
            'document' => ['zip', 'rar', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
            'image' => ['jpg', 'jpeg', 'png', 'gif'],
            'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'wma'],
            'powerpoint' => ['ppt', 'pptx'],
            default => ['file'],
        };
    }
}

if (!function_exists('file_size_format')) {
    /**
     * @param $bytes
     * @param int $precision
     *
     * @return string
     * @last_updated 6.19.6 - 2022-05-03, 09:53 ICT - by malayvuong
     * - optimized function
     * - change name from `human_file_size` to meaning function
     */
    function file_size_format($bytes, int $precision = 2): string
    {
        $units = ['B', 'kB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= 1024 ** $pow;

        return number_format($bytes, $precision, ',', '.') . ' ' . $units[$pow];
    }
}
