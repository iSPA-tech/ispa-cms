<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Constants\Constant;
use App\Helpers\AppHelpers;
use Cms\Core\Models\Upload;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Cms\Core\Models\MetaValueText;
use Cms\Core\Models\MetaValueDate;
use Cms\Core\Responses\AppResponse;
use Illuminate\Support\Facades\File;
use Cms\Core\Services\Menu\MenuItemUrlResolver;
use Cms\Core\Models\MetaValueString;
use Cms\Core\Models\MetaValueBigNum;
use Illuminate\Support\Facades\Route;
use Cms\Core\Models\MetaValueTinyNum;
use Cms\Core\Models\MetaValueBoolean;

// app/helpers.php
if (!function_exists('ires')) {
    function ires(): mixed
    {
        return app(AppResponse::class);
    }
}

if (!function_exists('formatValueByInputType')) {
    /**
     * Format value by type
     *
     * @throws JsonException
     * @since 7.160.1175 - 2025-07-28, 15:27 ICT
     * @author malayvuong
     */
    function formatValueByInputType(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => ($value === true || $value === 'true') ? 'true' : 'false',
            'number' => (float)$value,
            'text', 'textarea', 'password', 'select' => is_array($value) || is_object($value)
                ? json_encode($value, JSON_THROW_ON_ERROR)
                : (string)$value,
            default => $value,
        };
    }
}

if (!function_exists('format_currency')) {
    /**
     * Format number by currency
     *
     * @param float $number
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2024-03-24, 17:51 ICT
     */
    function format_currency(float $number = 0): string
    {
        return number_format($number, lang('path', 'vi') === 'vi' ? 0 : 2) . ' ' . lang('currency', 'đ');
    }
}

if (!function_exists('format_price_compact')) {
    /**
     * Convert large price values into a compact, locale-aware string (e.g. 8,3 tỷ).
     *
     * @param mixed $price
     * @param bool $includeCurrencySymbol
     * @param string|null $perUnit
     *
     * @return string
     */
    function format_price_compact(mixed $price, bool $includeCurrencySymbol = false, ?string $perUnit = null): string
    {
        if ($price === null || $price === '') {
            return 'N/A';
        }

        if (is_int($price) || is_float($price)) {
            $numericPrice = (float)$price;
        } else {
            $normalized = preg_replace('/[^\d.,-]/', '', (string)$price);

            if ($normalized === '' || $normalized === null) {
                return 'N/A';
            }

            $commaCount = substr_count($normalized, ',');
            $dotCount = substr_count($normalized, '.');
            $decimalSeparator = null;

            if ($commaCount && $dotCount) {
                $decimalSeparator = strrpos($normalized, ',') > strrpos($normalized, '.') ? ',' : '.';
            } else if ($commaCount === 1 && $dotCount === 0 && strlen($normalized) - strrpos($normalized, ',') - 1 <= 2) {
                $decimalSeparator = ',';
            } else if ($dotCount === 1 && $commaCount === 0 && strlen($normalized) - strrpos($normalized, '.') - 1 <= 2) {
                $decimalSeparator = '.';
            }

            if ($decimalSeparator) {
                $thousandSeparator = $decimalSeparator === ',' ? '.' : ',';
                $normalized = str_replace([$thousandSeparator, $decimalSeparator], ['', '.'], $normalized);
            } else {
                $normalized = str_replace([',', '.'], '', $normalized);
            }

            if (!is_numeric($normalized)) {
                return 'N/A';
            }

            $numericPrice = (float)$normalized;
        }

        if (abs($numericPrice) < 0.000001) {
            return 'N/A';
        }

        $locale = strtolower(lang('path', app()->getLocale() ?? 'vi'));
        $isVietnamese = str_starts_with($locale, 'vi');
        $decimalSeparator = $isVietnamese ? ',' : '.';
        $thousandSeparator = $isVietnamese ? '.' : ',';

        $value = $numericPrice;
        $suffix = '';

        if ($numericPrice >= 1_000_000_000) {
            $value = $numericPrice / 1_000_000_000;
            $suffix = __('text.utils.unit_b');
        } else if ($numericPrice >= 1_000_000) {
            $value = $numericPrice / 1_000_000;
            $suffix = __('text.utils.unit_m');
        } else if ($numericPrice >= 1_000) {
            $value = $numericPrice / 1_000;
            $suffix = $isVietnamese ? 'k' : 'K';
        }

        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 1;
        $formattedNumber = number_format($value, $decimals, $decimalSeparator, $thousandSeparator);
        $result = trim($formattedNumber . $suffix);

        if ($includeCurrencySymbol) {
            $currencySymbol = lang('currency', $isVietnamese ? 'đ' : '$');
            $result = $isVietnamese
                ? trim($result . ' ' . $currencySymbol)
                : trim($currencySymbol . ' ' . $result);
        }

        if (!empty($perUnit)) {
            $result .= '/' . ltrim($perUnit, '/');
        }

        return $result;
    }
}

if (!function_exists('current_route_name')) {
    /**
     * Return list by multiple level recursive
     *
     * @return string
     * @author malayvuong
     */
    function current_route_name(): string
    {
        return Route::currentRouteName();
    }
}

if (!function_exists('multiLevel')) {
    /**
     * Return list by multiple level recursive
     *
     * @param   $list | array data
     * @param int|null $parentId
     * @param array $returnArray | Array will return to client
     * @param string $pairKey
     *
     * @return array
     * @author malayvuong
     */
    function multiLevel($list, ?int $parentId = null, array $returnArray = [], string $pairKey = 'parent_id'): array
    {
        if (count($list) < 1) {
            return $returnArray;
        }

        foreach ($list as $key => $item) {
            //  Todo: Make sure the item has the key by pairKey?
            if (optional($item)?->{$pairKey} === $parentId) {
                $returnArray[] = $item;
                unset($list[$key]);
                multiLevel($list, $item->id, $returnArray, $pairKey);
            }
        }

        return $returnArray;
    }
}

if (!function_exists('setMetaValue')) {
    /**
     * Set meta value
     *
     * @param $value
     * @param bool $isDate
     *
     * @return string
     * @author malayvuong
     * @since 6.62.6 - 2023-01-29, 20:50 ICT
     */
    function setMetaValue($value, bool $isDate = false): mixed
    {
        //  Check if value['value'] is an array => Update value of Value
        if (is_array($value['value']) && isset($value['value']['id'])) {
            $val = (new (get_value_class($value['type']))())
                ->where($value['id'] ? 'id' : 'user_id', ($value['value']['id'] ?? $value['id']) ?? user('id'))->first();

            if (!$val) {
                $val = new (get_value_class($value['type']))();
            }
        } else {
            //  Create new value
            $val = new (get_value_class($value['type']))();
        }

        if ($isDate) {
            $val->value = now()->parse(!empty($value['value']['id']) ? $value['value']['value'] : $value['value'])->setTimezone(get_timezone());
        } else {
            $val->value = !empty($value['value']['id']) ? $value['value']['value'] : $value['value'];
        }
        $val->label = !empty($value['value']['id']) ? ($value['value']['label'] ?? $value['value']['value']) : $value['value'];
        $val->user_id = $value['user_id'] ?? user()->id ?? null;

        $val->save();

        return $val;
    }
}

if (!function_exists('getCategoryTypeIdByString')) {
    /**
     * Check if router has name
     *
     * @param string $type
     *
     * @return string
     * @author malayvuong
     * @since 6.62.6 - 2023-01-29, 20:50 ICT
     */
    function getCategoryTypeIdByString(string $type = 'article'): string
    {
        return match ($type) {
            'product' => Constant::CATEGORY_TYPE_PRODUCT,
            'place' => Constant::CATEGORY_TYPE_PLACE,
            'event' => Constant::CATEGORY_TYPE_EVENT,
            'gallery' => Constant::CATEGORY_TYPE_GALLERY,
            'upload' => Constant::CATEGORY_TYPE_UPLOAD,
            'role' => Constant::CATEGORY_TYPE_ROLE,
            default => Constant::CATEGORY_TYPE_ARTICLE,
        };
    }
}

if (!function_exists('hasRouter')) {
    /**
     * Check if router has name
     *
     * @param string $name
     *
     * @return string
     * @author malayvuong
     * @since 6.62.6 - 2023-01-29, 20:50 ICT
     */
    function hasRouter(string $name = ''): string
    {
        //  Return true if current route name match with name
        return request()->routeIs($name);
    }
}

if (!function_exists('make_qr_code_url')) {
    /**
     * Make qr code url for scan
     *
     * @param string $type
     * @param string $uid
     *
     * @return string
     * @author malayvuong
     * @since 6.62.6 - 2023-01-29, 20:50 ICT
     */
    function make_qr_code_url(string $type = 'place', string $uid = ''): string
    {
        return urlencode(route('scan.' . $type, [
            'uid' => $uid,
//            'utm_source' => 'scan',
//            'utm_medium' => 'qr_code',
//            'utm_campaign' => 'scan-at-' . $type
        ]));
    }
}

if (!function_exists('get_user_setting')) {
    /**
     * Get value class namespace
     *
     * @param string $setting_key
     * @param mixed $default
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-01-27, 18:25 ICT
     */
    function get_user_setting(string $setting_key, mixed $default = ''): mixed
    {
        $user = user();

        if (!$user) {
            return '';
        }

        return $user->parsed_settings->{$setting_key}['value'] ?? $default;
    }
}

if (!function_exists('get_value_class')) {
    /**
     * Get value class namespace
     *
     * @param string $type
     *
     * @return string
     * @author malayvuong
     * @since 6.62.6 - 2022-11-19, 07:14 ICT
     */
    function get_value_class(string $type): string
    {
        $mappedType = [
            'bigInt' => MetaValueBigNum::class,
            'string' => MetaValueString::class,
            'text' => MetaValueText::class,
            'tinyInt' => MetaValueTinyNum::class,
            'date' => MetaValueDate::class,
            'boolean' => MetaValueBoolean::class,
        ];

        return $mappedType[$type];
    }
}

if (!function_exists('user')) {
    /**
     * Get the currently authenticated user.
     *
     * @param string|null $key
     * @param mixed|null $default
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-10-22, 11:11 ICT
     * @last_updated - 2023-02-08, 9:46 ICT
     */
    function user(?string $key = null, mixed $default = null): mixed
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        if (empty($key)) {
            return $user;
        }

        return $user->{$key} ?? $default;
    }
}

if (!function_exists('env_file_value')) {
    /**
     * Read a value straight from the .env file.
     *
     * env() goes dark once the configuration is cached (Laravel skips
     * loading .env entirely), but the CMS env-scoped settings feature and
     * the .env writer must keep seeing the real file contents. Parses the
     * file once per process.
     *
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    function env_file_value(string $key, mixed $default = null): mixed
    {
        static $values = null;

        if (!app()->configurationIsCached()) {
            return env($key, $default);
        }

        if ($values === null) {
            try {
                $path = app()->environmentFilePath();
                $values = is_file($path)
                    ? Dotenv\Dotenv::parse((string)file_get_contents($path))
                    : [];
            } catch (Throwable) {
                $values = [];
            }
        }

        if (!array_key_exists($key, $values)) {
            return $default;
        }

        return match (strtolower((string)$values[$key])) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $values[$key],
        };
    }
}

if (!function_exists('env_update')) {
    /**
     * Update .env file
     *
     * @param Collection|array $settings
     * @param string $isCacheEnable
     *
     * @since 6.62.6 - 2022-10-16, 21:50 ICT
     * @author malayvuong
     */
    function env_update(Collection|array $settings, string $isCacheEnable = 'false'): void
    {
        $path = app()->environmentFilePath();

        // While the test suite runs, never rewrite an environment file owned by the repo
        // (the developer's .env or tests/Fixtures/testing.env). Work on a per-process copy
        // below storage/framework/testing instead; files outside the repo are left alone.
        if (app()->runningUnitTests() && str_starts_with($path, base_path() . DIRECTORY_SEPARATOR)
            && !str_starts_with($path, storage_path('framework/testing') . DIRECTORY_SEPARATOR)) {
            $copyDir = storage_path('framework/testing/env');
            if (!is_dir($copyDir)) {
                @mkdir($copyDir, 0775, true);
            }
            $copy = $copyDir . '/' . basename($path) . '.' . getmypid();
            if (!is_file($copy)) {
                copy($path, $copy);
            }
            $path = $copy;
        }

        $env = file_get_contents($path);

        foreach ($settings as $st) {
            //  Process to write env file or language-setting file
            $count = 0;
            $key = str_replace(['email.', 'advanced.', 'api.'], '', $st->setting_key, $count);

            if ($count > 0 || in_array($st->name, ['ANALYTICS_VIEW_ID', 'CACHE_DRIVER'])) {
                if ($st->name === 'CACHE_DRIVER') {
                    $key = str($st->setting_key)->replace('cache.', '');
                    if ($isCacheEnable !== 'true') {
                        $st->value = 'null';
                    }
                }
                if ($st->name === 'ANALYTICS_VIEW_ID') {
                    $key = str($st->setting_key)->replace('tracking.', '');
                }
                $env = prepare_env($env, $key, $st);
                if ($key === 'VITE_TEMPLATE_FOLDER') {
                    $env = prepare_env($env, 'VITE_TEMPLATE_TAILWIND_CSS', (object)['value' => 'projects/' . $st->value . '/dev/theme.css']);
                    $env = prepare_env($env, 'VITE_TEMPLATE_TAILWIND_CONFIG', (object)['value' => 'projects/' . $st->value . '/dev/tailwind.config.js']);
                    $env = prepare_env($env, 'VITE_TEMPLATE_TAILWIND_OUTPUT', (object)['value' => './public/' . $st->value . '/assets/css']);
                }
            }
        }

        file_put_contents($path, $env);

        // With a cached configuration the new .env values are invisible to
        // config() until the cache is rebuilt. Refresh it in place so admin
        // setting changes keep applying; on failure the values still land at
        // the next deploy's config:cache.
        if (app()->configurationIsCached()) {
            try {
                Artisan::call('config:cache');
            } catch (Throwable $e) {
                CMSLogger::error([
                    'message' => 'config:cache refresh after env update failed',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

if (!function_exists('put_config')) {
    /**
     * Put config into config file
     *
     * @param $data
     * @param string $file
     *
     * @return void
     * @author malayvuong
     * @since 6.62.6 - 2022-10-17, 08:59 ICT
     */
    function put_config($data, string $file = 'setting'): void
    {
        $path = base_path('config/' . $file . '.php');

        $export = var_export($data, true);
        $patterns = [
            "/([\(object\)]*)([ ]*)array([ ]*)\(/" => '[',
            "/^([ ]*)\)(,?)$/m" => '$1]$2',
            "/=>[ ]?\n[ ]+\[/" => '=> [',
            "/=>[ ]?\n+\[/" => '=> [',
            "/([ ]*)(\'[^\']+\') => ([\[\'])/" => '$1$2 => $3',
        ];
        $export = preg_replace(array_keys($patterns), array_values($patterns), $export);

        // Log the target and the shape only: the payload holds settings values (mail provider keys, ad snippets),
        // which must never reach storage/logs.
        $groups = is_array($data) ? array_keys($data) : [];
        $keyCount = 0;
        foreach (is_array($data) ? $data : [] as $group) {
            $keyCount += is_array($group) ? count($group) : 1;
        }
        logger('Writing config file: ' . $path, ['groups' => $groups, 'keys' => $keyCount]);

        \Cms\Core\Support\Testing\TranslationFileGuard::assertWritable($path);

        try {
            File::put($path, '<?php
return ' . $export . ';');
        } catch (Exception $e) {
            //  If error, write to log
            CMSLogger::error([
                'message' => 'Error writing config file: ' . $path,
                'exception' => $e,
            ]);
        }
    }
}

if (!function_exists('prepare_env')) {
    /**
     * commit the file env
     *
     * @param string $env
     * @param string $key
     * @param object $st
     *
     * @return array|string
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-04-16, 16:38 ICT
     */
    function prepare_env(string $env, string $key, object $st): array|string
    {
        $value = $st->value;

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        } else {
            $value = (string)$value;

            if ($value === '' || preg_match('/[\s#"\'\\\\$]/u', $value) === 1) {
                $value = '"' . str_replace(
                    ['\\', '"', '$', "\r", "\n"],
                    ['\\\\', '\\"', '\\$', '', '\\n'],
                    $value
                ) . '"';
            }
        }

        $line = sprintf('%s=%s', $key, $value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $env) === 1) {
            return preg_replace_callback($pattern, static fn() => $line, $env, 1);
        }

        return rtrim($env, "\r\n") . "\n" . $line . "\n";
    }
}

if (!function_exists('matchingSlugToFunction')) {
    /**
     * Matching slug and return function name
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-08-27, 16:11 ICT
     */
    function matchingSlugToFunction(string $slug): string
    {
        return match ($slug) {
            'glossary', 'thuat-ngu' => 'glossary',
            'about', 'gioi-thieu' => 'about',
            'privacy-policy' => 'privacyPolicy',
            'terms-of-service' => 'termsOfService',
            default => '',
        };
    }
}

if (!function_exists('check_valid_access')) {

    /**
     * Check valid to access page or download
     *
     * @param array $config
     *
     * @return bool
     * @author malayvuong
     * @since 6.62.6 - 2022-08-21, 18:02 ICT
     */
    function check_valid_access(array $config = []): bool
    {
        $collect = collect($config);

        if ($collect->has('requiredLogin') && $collect->has('roles') && $collect->get('requiredLogin') === true) {
            if (!auth()->check()) {
                return false;
            }

            return check_access_by_role($collect->get('roles', []), auth()->user()->roles);
        }

        return true;
    }
}

if (!function_exists('check_access_by_role')) {
    /**
     * Check download by role.
     *
     * @param array $download_role
     * @param Collection $role
     *
     * @return bool
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 15:54 ICT
     */
    function check_access_by_role(array $download_role, Collection $role): bool
    {
        $original = collect($download_role);
        //  If user is webmaster (id=1) or original is empty, return true
        if ($original->isEmpty() || in_array(1, $role->pluck('id')->toArray(), true)) {
            return true;
        }

        return $original->firstWhere(function ($item) use ($role) {
            return in_array($item, $role->pluck('id')->toArray(), true);
        });
    }
}

if (!function_exists('get_file_icon')) {
    /**
     * Get file icon by type (extension)
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-08-14, 21:06 ICT
     */
    function get_file_icon(string $type): string
    {
        return match ($type) {
            'mp3', 'm4a', 'wma', 'flac', 'ogg' => 'fa fa-file-music',
            'jpg', 'jpeg', 'png', 'gif', 'webm', 'webp', 'bmp', 'tiff', 'tif', 'ico' => 'fa fa-file-image',
            'rar', 'zip', 'gz' => 'fa fa-file-archive',
            'csv' => 'fa fa-file-csv',
            'pdf' => 'fa fa-file-pdf',
            'doc', 'docx' => 'fa fa-file-word',
            'xls', 'xlsx' => 'fa fa-file-excel',
            'ppt', 'pptx' => 'fa fa-file-powerpoint',
            'default' => '',
        };
    }
}

if (!function_exists('video_embed')) {
    /**
     * Check and return video embed url
     *
     * @param string $url
     *
     * @return string
     * @author malayvuong
     * @since 6.62.6 - 2022-08-14, 11:51 ICT
     */
    function video_embed(string $url): string
    {
        $embedVideo = '';
        if (strpos($url, 'youtube') || strpos($url, 'youtu.be')) {
            if (strpos($url, 'youtube')) {
                //  Check if live link
                if (strpos($url, 'live')) {
                    $exp = explode('live/', $url);
                } else {
                    $exp = explode('watch?v=', $url);
                }
            } else {
                $exp = explode('youtu.be/', $url);
            }

            if (count($exp) === 2) {
                if (strpos($exp[1], '&')) {
                    $exp[1] = explode('&', $exp[1])[0];
                }
                if (strpos($exp[1], '?')) {
                    $exp[1] = explode('?', $exp[1])[0];
                }

                $embedVideo = 'https://www.youtube.com/embed/' . $exp[1];
            }
        } else if (strpos($url, 'vimeo')) {
            $exp = explode('vimeo.com/', $url);
            if (count($exp) === 2) {
                $embedVideo = 'https://player.vimeo.com/video/' . $exp[1];
            }
        }

        return $embedVideo;
    }
}

if (!function_exists('get_timezone')) {
    /**
     * Get the timezone
     *
     * @return string
     *
     * @since 6.19.6 - 2022-05-06, 14:32 ICT
     *
     * @author malayvuong
     */
    function get_timezone(): string
    {
        $timezone = config('ispa-cms.timezone');
        if (user() && isset(user()->parsed_settings->timezone)) {
            $timezone = user()->parsed_settings->timezone['value'];
        }

        return $timezone;
    }
}

if (!function_exists('get_days_offset')) {
    /**
     * Get the days before / after the date by week day name
     *
     * @param string $dayName
     * @param bool $left
     *
     * @return int
     *
     * @since 6.19.6 - 2022-05-06, 14:32 ICT
     *
     * @author malayvuong
     */
    function get_days_offset(string $dayName, bool $left = true): int
    {
        return match ($dayName) {
            'Sunday', 'Sun' => $left ? -6 : 0,
            'Saturday', 'Sat' => $left ? -5 : 1,
            'Friday', 'Fri' => $left ? -4 : 2,
            'Thursday', 'Thu' => $left ? -3 : 3,
            'Wednesday', 'Wed' => $left ? -2 : 4,
            'Tuesday', 'Tue' => $left ? -1 : 5,
            'Monday', 'Mon' => $left ? 0 : 6,
            default => 0,
        };
    }
}

if (!function_exists('get_url_menu')) {
    /**
     * Get url for menu item
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-05-25, 13:49 ICT
     */
    function get_url_menu($item): string
    {
        return app(MenuItemUrlResolver::class)->resolve($item);
    }
}

if (!function_exists('print_field_validate')) {
    /**
     * Get the date range by type
     * With the type='dateRange' => catch from request name 'dateRange'
     *
     * @param $item
     * @param string $separator
     *
     * @return string
     *
     * @since 6.6.8 - 2022-03-7, 19:37
     *
     * @author malayvuong
     */
    function print_field_validate($item, string $separator = '; '): string
    {
        $custom = is_string($item->custom) ? json_decode($item->custom, true) : (is_array($item->custom) ? $item->custom : []);
        $return = [];
        if (($custom['required'] ?? false) === true) {
            $return[] = "activeClass('$item->field_name', !validator.isEmpty(form.$item->field_name), '" . __('validation.required', ['attribute' => __($item->name_trans_key)]) . "')";
        }
        if ($item->type === 'email') {
            $return[] = "activeClass('$item->field_name', validator.isEmail(form.$item->field_name), '" . __('validation.email', ['attribute' => __($item->name_trans_key)]) . "')";
        } else if (str_contains('phone', $item->field_name) || str_contains('mobile', $item->field_name)) {
            $return[] = "(
        form.$item->field_name.length " . (($custom['required'] ?? false) === true ? '>=' : '>') . " 0
        && activeClass('$item->field_name', validator.isMobilePhone(form.$item->field_name), '" . __('validation.regex', ['attribute' => __($item->name_trans_key)]) . "')
      )" . (($custom['required'] ?? false) !== true ? "; activeClass('$item->field_name', validator.isEmpty(form.$item->field_name))" : '');
        }

        return implode($separator, $return);
    }
}

if (!function_exists('get_date_range')) {
    /**
     * Get the date range by type
     * With the type='dateRange' => catch from request name 'dateRange'
     *
     * @param string $type
     *
     * @return array
     *
     * @since 6.6.8 - 2022-03-7, 19:37
     *
     * @author malayvuong
     */
    function get_date_range(string $type = 'last_week'): array
    {
        $last_week = now()->addWeeks(-1);
        $last_month = now()->addMonths(-1);

        return match ($type) {
            'last_7_days' => [now()->addDays(-7), now()],
            'last_week' => [now()->parse($last_week)->startOfWeek(), now()->parse($last_week)->endOfWeek()],
            'last_30_days' => [now()->addDays(-30), now()],
            'last_month' => [now()->parse($last_month)->startOfMonth(), now()->parse($last_month)->endOfMonth()],
            'dateRange' => request('dateRange', []),
            default => [],
        };
    }
}

if (!function_exists('get_trans_from_model')) {
    /**
     * Make cache key name from array data
     *
     * @param string $modelNamespace
     *
     * @return string
     *
     * @since 6.5.5 - 2022-03-05, 10:04
     *
     * @author malayvuong
     */
    function get_trans_from_model(string $modelNamespace): string
    {
        $model = str($modelNamespace)->replace('App\Models\\', '');
        //  if in $model has `\` then split by `\` and get the last part
        if (str_contains($model, '\\')) {
            $model = explode('\\', $model);
            $model = end($model);
        }

        $model = str($model)->lower();

        return __('nav.a.' . $model . 's');
    }
}

if (!function_exists('menu_navigate')) {
    /**
     * Make cache key name from array data
     *
     * @param $menu
     * @param string|null $parentPrefix
     *
     * @return string
     * @throws Throwable
     * @since 6.2.7 - 2022-02-22, 13:39
     * @author malayvuong
     */
    function menu_navigate($menu, ?string $parentPrefix = null): string
    {
        return app(MenuItemUrlResolver::class)->resolve($menu, $parentPrefix);
    }
}

if (!function_exists('make_key')) {
    /**
     * Make cache key name from array data
     *
     * @param array $data
     *
     * @return string
     *
     * @author malayvuong
     *
     * @since 6.2.7 - 2022-02-22, 13:39
     */
    function make_key(array $data = []): string
    {
        return AppHelpers::makeKey($data);
    }
}

if (!function_exists('languages')) {
    /**
     * Get the languages list
     *
     * @return mixed
     * @throws Throwable
     * @since 6.5.5 - 2022-02-22, 13:40
     * @author malayvuong
     */
    function languages(): mixed
    {
        return AppHelpers::getLanguages();
    }
}

if (!function_exists('lang')) {
    /**
     * Get the language key
     *
     * @param string|null $key
     * @param mixed|null $default
     *
     * @return mixed
     * @throws Throwable
     * @since 6.2.7 - 2022-02-22, 13:40
     * @author malayvuong
     */
    function lang(?string $key = null, mixed $default = null): mixed
    {
        if (!empty($key)) {
            return AppHelpers::getLanguage()->{$key} ?? $default;
        }

        return AppHelpers::getLanguage();
    }
}

if (!function_exists('location')) {
    /**
     * Get current location information via ip address
     *
     * @return object
     *
     * @author malayvuong
     *
     * @since 6.2.7 - 2022-02-22, 13:40
     */
    function location(): object
    {
        $location = AppHelpers::getLocation();

        if (!$location) {
            return (object)['longitude' => null, 'latitude' => null, 'cityName' => null, 'zipCode' => null, 'countryName' => null, 'countryCode' => null];
        }

        return AppHelpers::getLocation();
    }
}

if (!function_exists('getBrowserLocale')) {
    function getBrowserLocale(): string
    {
        // Credit: https://gist.github.com/Xeoncross/dc2ebf017676ae946082
        $websiteLanguages = config('app.locales');
        // Parse the Accept-Language according to:
        // http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.4
        preg_match_all(
            '/([a-z]{1,8})' .       // M1 - First part of language e.g en
            '(-[a-z]{1,8})*\s*' .   // M2 -other parts of language e.g -us
            // Optional quality factor M3 ;q=, M4 - Quality Factor
            '(;\s*q\s*=\s*((1(\.0{0,3}))|(0(\.[0-9]{0,3}))))?/i',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'],
            $langParse);

        $langs = $langParse[1]; // M1 - First part of language
        $quals = $langParse[4]; // M4 - Quality Factor

        $numLanguages = count($langs);
        $langArr = [];

        for ($num = 0; $num < $numLanguages; $num++) {
            $newLang = strtoupper($langs[$num]);
            $newQual = isset($quals[$num]) ?
                (empty($quals[$num]) ? 1.0 : floatval($quals[$num])) : 0.0;

            // Choose whether to upgrade or set the quality factor for the
            // primary language.
            $langArr[$newLang] = (isset($langArr[$newLang])) ?
                max($langArr[$newLang], $newQual) : $newQual;
        }

        // sort list based on value
        // langArr will now be an array like: array('EN' => 1, 'ES' => 0.5)
        arsort($langArr, SORT_NUMERIC);

        // The languages the client accepts in order of preference.
        $acceptedLanguages = array_keys($langArr);

        // Set the most preferred language that we have a translation for.
        foreach ($acceptedLanguages as $preferredLanguage) {
            if (in_array($preferredLanguage, $websiteLanguages, true)) {
                $_SESSION['lang'] = $preferredLanguage;

                return strtolower($preferredLanguage);
            }
        }

        return strtolower(config('app.locale'));
    }
}

if (!function_exists('isBot')) {
    function isBot($agent = null): bool
    {
        // Crawler, link-preview and HTTP-library user agents (case-insensitive substrings). Names of real
        // browsers such as the DuckDuckGo browser or the Pinterest in-app browser stay out; their crawlers contain 'bot'.
        $bots = [
            'Googlebot', 'Baiduspider', 'ia_archiver', 'R6_FeedFetcher', 'NetcraftSurveyAgent', 'Sogou web spider',
            'bingbot', 'Yahoo! Slurp', 'facebookexternalhit', 'PrintfulBot', 'msnbot', 'Twitterbot', 'UnwindFetchor',
            'urlresolver', 'Butterfly', 'TweetmemeBot', 'PaperLiBot', 'MJ12bot', 'AhrefsBot', 'Exabot', 'Ezooms',
            'YandexBot', 'SearchmetricsBot', 'picsearch', 'TweetedTimes Bot', 'QuerySeekerSpider', 'ShowyouBot',
            'woriobot', 'merlinkbot', 'BazQuxBot', 'Kraken', 'SISTRIX Crawler', 'R6_CommentReader', 'magpie-crawler',
            'GrapeshotCrawler', 'PercolateCrawler', 'MaxPointCrawler', 'R6_FeedFetcher', 'NetSeer crawler',
            'grokkit-crawler', 'SMXCrawler', 'PulseCrawler', 'Y!J-BRW', '80legs.com/webcrawler', 'Mediapartners-Google',
            'Spinn3r', 'InAGist', 'Python-urllib', 'NING', 'TencentTraveler', 'Feedfetcher-Google', 'mon.itor.us',
            'spbot', 'Feedly', 'bitlybot', 'ADmantX Platform', 'Niki-Bot', 'python-requests', 'DotBot',
            'HTTP_Request2', 'linkdexbot', 'A6-Indexer', 'Baiduspider', 'TwitterFeed', 'Microsoft Office', 'Pingdom',
            'BTWebClient', 'KatBot', 'SiteCheck', 'proximic', 'Sleuth', 'Abonti', '(BOT for JCE)', 'Baidu',
            'Tiny Tiny RSS', 'newsblur', 'updown_tester', 'linkdex', 'baidu', 'searchmetrics', 'genieo', 'majestic12',
            'spinn3r', 'profound', 'domainappender', 'VegeBot', 'terrykyleseoagency.com', 'CommonCrawler Node',
            'AdlesseBot', 'metauri.com', 'libwww-perl', 'rogerbot-crawler', 'MegaIndex.ru', 'ltx71', 'Qwantify',
            'Traackr.com', 'Re-Animator Bot', 'Pcore-HTTP', 'BoardReader', 'omgili', 'okhttp', 'CCBot', 'Java/1.8',
            'semrush.com', 'feedbot', 'CommonCrawler', 'AdlesseBot', 'MetaURI', 'ibwww-perl', 'rogerbot', 'MegaIndex',
            'BLEXBot', 'FlipboardProxy', 'techinfo@ubermetrics-technologies.com', 'trendictionbot', 'Mediatoolkitbot',
            'trendiction', 'ubermetrics', 'ScooperBot', 'TrendsmapResolver', 'Nuzzel', 'Go-http-client', 'Applebot',
            'LivelapBot', 'GroupHigh', 'SemrushBot', 'ltx71', 'commoncrawl', 'istellabot', 'DomainCrawler',
            'cs.daum.net', 'StormCrawler', 'GarlikCrawler', 'The Knowledge AI', 'getstream.io/winds', 'YisouSpider',
            'archive.org_bot', 'semantic-visions.com', 'FemtosearchBot', '360Spider', 'linkfluence.com',
            'glutenfreepleasure.com', 'Gluten Free Crawler', 'YaK/1.0', 'Cliqzbot', 'app.hypefactors.com', 'axios',
            'semantic-visions.com', 'webdatastats.com', 'schmorp.de', 'SEOkicks', 'DuckDuckBot', 'Barkrowler',
            'ZoominfoBot', 'Linguee Bot', 'Mail.RU_Bot', 'OnalyticaBot', 'Linguee Bot', 'admantx-adform', 'Buck/2.2',
            'Barkrowler', 'Zombiebot', 'Nutch', 'SemanticScholarBot', 'Jetslide', 'scalaj-http', 'XoviBot',
            'sysomos.com', 'PocketParser', 'newspaper', 'serpstatbot', 'MetaJobBot', 'SeznamBot/3.2',
            'VelenPublicWebCrawler/1.0', 'WordPress.com mShots', 'adscanner', 'BacklinkCrawler', 'netEstate NE Crawler',
            'Astute SRM', 'GigablastOpenSource/1.0', 'DomainStatsBot', 'Winds: Open Source RSS & Podcast',
            'dlvr.it', 'BehloolBot', '7Siters', 'AwarioSmartBot', 'Apache-HttpClient/5', 'Seekport Crawler', 'AHC/2.1',
            'eCairn-Grabber', 'mediawords bot', 'PHP-Curl-Class', 'Scrapy', 'curl/7', 'Blackboard', 'NetNewsWire',
            'node-fetch', 'admantx', 'metadataparser', 'Domains Project', 'SerendeputyBot', 'Moreover',
            'monitoring-plugins', 'Selfoss', 'Adsbot', 'acebookexternalhit', 'SpiderLing', 'SerendeputyBot',
            'Cocolyzebot', 'coccoc', 'bot',
        ];

        // True on the first listed marker found in the agent.
        foreach ($bots as $b) {
            if (stripos($agent, $b) !== false) {
                return true;
            }
        }

        return false;
    }
}
