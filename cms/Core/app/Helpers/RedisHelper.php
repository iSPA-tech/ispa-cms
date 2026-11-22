<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Helpers;

use JsonException;
use App\Constants\Constant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Cms\Core\Interfaces\MenuInterface;
use Cms\Core\Http\Resources\Website\PublicMenuItemCollection;

/**
 * Low-level Redis interaction helper for the CMS cache layer.
 *
 * Wraps the Laravel Redis facade with convenience methods for storing,
 * retrieving, and deleting cache entries (including tag-based bulk
 * invalidation). Also aggregates website settings (menus, branding,
 * social links) into a single Redis hash for fast front-end hydration.
 *
 * @see \Cms\Core\Helpers\CacheHelper
 * @see \Cms\Core\Helpers\CacheKeyHelper
 * @package Cms\Core\Helpers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class RedisHelper
{
    protected static string $separator = '.';

    /**
     * Class constructor
     *
     * @param string $separator  Key segment separator (default ".")
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 11:38 ICT
     */
    public function __construct(string $separator = '.')
    {
        self::$separator = $separator ?: self::$separator;
    }

    /**
     * Get this instance
     *
     * @return self
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-22, 15:08 ICT
     */
    public static function getInstance(): RedisHelper|static
    {
        return new self();
    }

    /**
     * Parse cache key
     *
     * @param string $key  Dot-separated cache key
     * @return array{group: string, type: string, options: array}  Parsed key segments
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 11:35 ICT
     */
    public static function parseKey(string $key)
    {
        $exploded = explode(self::$separator, $key);

        return [
            'group' => $exploded[0],
            'type' => $exploded[1],
            'options' => array_slice($exploded, 2),
        ];
    }

    /**
     * @param array|string $inputKey
     * @param mixed $value
     * @param int|null $ttl
     * @return void
     *
     * @throws JsonException
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 11:25 ICT
     */
    public static function set(array|string $inputKey, mixed $value, int $ttl = null): void
    {
        if (!self::isEnable()) {
            return;
        }

        if (is_array($inputKey)) {
            $key = self::makeKey($inputKey);
        } else {
            $key = $inputKey;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }

        $redis = Redis::connection();

        if (!isset($ttl) || $ttl === 0) {
            $redis->set($key, $value);
        } else {
            $redis->set($key, $value, 'EX', $ttl);
        }

        if (is_array($inputKey)) {
            self::addToTag($redis, $inputKey[0], $key, $ttl);
            if (strpos($inputKey[1], ':')) {
                $exploded = explode(':', $inputKey[1]);
                self::addToTag($redis, $exploded[0], $key, $ttl);
            } else {
                self::addToTag($redis, $inputKey[1], $key, $ttl);
            }
        } else if (strpos($inputKey, ':')) {
            $exploded = explode(':', $inputKey);
            if (count($exploded) > 2) {
                foreach ($exploded as $index => $childKey) {
                    if ($index >= (count($exploded) - 1)) {
                        break;
                    }
                    self::addToTag($redis, $childKey, $key, $ttl);
                }
            }
        }
    }

    /**
     * Add a cache key to a tag set and keep the set alive as long as its members.
     *
     * Tag sets used to be created without an expiry, so they outlived every
     * TTL'd member and grew forever; the shared Redis runs `volatile-lru` and
     * can never evict a key without a TTL. A TTL'd member now gives a new or
     * shorter-lived set its TTL (never shortening a longer one), and a member
     * without a TTL makes the set persistent, so delByTags() still reaches every
     * live member. A set that is already persistent stays persistent.
     *
     * @param mixed       $redis  Redis connection
     * @param string      $tag    Tag set name
     * @param string      $key    Cache key to index under the tag
     * @param int|null    $ttl    TTL of the cache key in seconds (null/0 = none)
     * @return void
     *
     * @author iSPA Team
     * @since  8.1.0 (2026-10-24)
     */
    protected static function addToTag(mixed $redis, string $tag, string $key, ?int $ttl): void
    {
        if (empty($ttl)) {
            $redis->sadd($tag, $key);
            $redis->persist($tag);

            return;
        }

        $tagTtl = (int) $redis->ttl($tag);
        $redis->sadd($tag, $key);

        //  -2: the set did not exist before this SADD; 0..$ttl: it would expire before the new member.
        if ($tagTtl === -2 || ($tagTtl >= 0 && $tagTtl < $ttl)) {
            $redis->expire($tag, $ttl);
        }
    }

    /**
     * Get enable status
     *
     * @return bool
     * @author malayvuong
     * @since 7.160.1175 - 2024-05-02, 08:44 ICT
     */
    protected static function isEnable(): bool
    {
        return config('database.redis.enable');
    }

    /**
     * Make cache key for redis by pattern for tags usage
     *
     * @param array $options
     *
     * @return string
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 11:25 ICT
     */
    public static function makeKey(array $options): string
    {
        //  If every key not empty, make every key concat with value
        $options = array_map(static function ($key, $value) {
            if ((is_int($key) && !empty($value))) {
                return $value;
            }
            if (is_string($key)) {
                return sprintf('%s:%s', $key, $value);
            }
            return null;
        }, array_keys($options), $options);

        return implode(self::$separator, $options);
    }

    /**
     * Get cache by key
     *
     * @param string $key
     *
     * @return mixed|null
     * @throws JsonException
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 11:25 ICT
     */
    public static function get(string $key): mixed
    {
        if (!self::isEnable()) {
            return null;
        }

        $redis = Redis::connection();
        $value = $redis->get($key);

        if (!empty($value) && is_string($value)) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        return $value;
    }

    /**
     * Remove cache by tag
     *
     * @param array $tags
     * @return void
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 13:43 ICT
     */
    public static function delByTags(array $tags): void
    {
        if (!self::isEnable()) {
            return;
        }

        $redis = Redis::connection();

        foreach ($tags as $tag) {
            $keys = $redis->smembers($tag);
            $redis->pipeline(function ($pipe) use ($keys) {
                foreach ($keys as $key) {
                    $pipe->del($key);
                }
            });
        }
    }

    /**
     * Remove by key
     *
     * @param string $key
     * @return void
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-17, 10:18 ICT
     */
    public static function del(string $key): void
    {
        if (!self::isEnable()) {
            return;
        }

        $redis = Redis::connection();
        $redis->del($key);
    }

    /**
     * Setting website
     *
     * @return array
     * @throws JsonException
     * @since 6.62.6 - 2023-03-13, 12:54 ICT
     * @author malayvuong
     */
    public static function websiteSetting(): array
    {
        if (!self::isEnable()) {
            return [];
        }

        $langPathAlias = lang('path_alias', 'vi-VN');
        $menu = app(MenuInterface::class);

        $mainMenuSource = $menu->reset()->getMenuByGroupKey(Constant::CACHE_KEY_MENU_MAIN, lang('id', get_lang_id()));
        $footerMenuSource = $menu->reset()->getMenuByGroupKey(Constant::CACHE_KEY_MENU_FOOTER, lang('id', get_lang_id()));
        $accountMenuSource = $menu->reset()->getMenuByGroupKey(Constant::CACHE_KEY_MENU_ACCOUNT, lang('id', get_lang_id()));

        if ($mainMenuSource && empty(self::normalizeMenuItems($mainMenuSource))) {
            $mainMenuSource = $menu->getMenuDetailByGroupName(Constant::CACHE_KEY_MENU_MAIN, true);
        }
        if ($footerMenuSource && empty(self::normalizeMenuItems($footerMenuSource))) {
            $footerMenuSource = $menu->getMenuDetailByGroupName(Constant::CACHE_KEY_MENU_FOOTER, true);
        }
        if ($accountMenuSource && empty(self::normalizeMenuItems($accountMenuSource))) {
            $accountMenuSource = $menu->getMenuDetailByGroupName(Constant::CACHE_KEY_MENU_ACCOUNT, true);
        }

        $mainMenu = multiLevel(self::normalizeMenuItems($mainMenuSource));
        $footerMenu = multiLevel(self::normalizeMenuItems($footerMenuSource));
        $accountMenu = multiLevel(self::normalizeMenuItems($accountMenuSource));

        $logo = collect(config('setting.image.logo'))->first();

        $data = [
            'brand' => config('setting.basic.brand'),
            'title' => config('setting.basic.title.' . $langPathAlias),
            'description' => config('setting.basic.description.' . $langPathAlias),
            'contactAddress' => config('setting.contact.address.' . $langPathAlias),
            'logo' => json_encode([
                'id' => $logo['id'] ?? null,
                'thumbnailSm' => $logo['thumbnail_sm'] ?? '',
                'thumbnailMd' => $logo['thumbnail_md'] ?? '',
                'thumbnailLg' => $logo['thumbnail_lg'] ?? '',
                'coverLg' => $logo['cover_lg'] ?? '',
                'url' => $logo['url'] ?? '',
            ], JSON_THROW_ON_ERROR),
            'logoFooter' => json_encode(config('setting.logo_footer'), JSON_THROW_ON_ERROR),
            'hashtag' => config('setting.hashtag'),
            'contactEmail' => config('setting.email_contact'),
            'favicon' => json_encode(config('setting.favicon'), JSON_THROW_ON_ERROR),
            'analytic' => config('setting.ANALYTICS_VIEW_ID'),
            'social' => json_encode(config('setting.url'), JSON_THROW_ON_ERROR),
            'chat' => json_encode(config('setting.chat'), JSON_THROW_ON_ERROR),
            'contactPhone' => config('setting.phone'),
            'maintenanceEnable' => config('ispa-cms.maintenance') ? 1 : 0,

            'mainMenu' => json_encode(new PublicMenuItemCollection($mainMenu), JSON_THROW_ON_ERROR),
            'footerMenu' => json_encode(new PublicMenuItemCollection($footerMenu), JSON_THROW_ON_ERROR),
            'accountMenu' => json_encode(new PublicMenuItemCollection($accountMenu), JSON_THROW_ON_ERROR),
        ];

        self::hmset('backend_settings.' . lang('path'), $data);

        return $data;
    }

    /**
     * Normalize a menu source into a plain array of menu items.
     *
     * Accepts Collections, objects with an items() method or 'items'
     * property, and plain arrays. Returns an empty array for any
     * unrecognised input.
     *
     * @param  mixed $source  Menu data from the MenuInterface
     * @return array           Flat array of menu item data
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected static function normalizeMenuItems(mixed $source): array
    {
        if ($source instanceof Collection) {
            return $source->all();
        }

        if (is_object($source)) {
            if (method_exists($source, 'items')) {
                $items = $source->items();
                if ($items instanceof Collection) {
                    return $items->all();
                }
                if (is_array($items)) {
                    return $items;
                }
            }

            $items = data_get($source, 'items');
            if ($items instanceof Collection) {
                return $items->all();
            }
            if (is_array($items)) {
                return $items;
            }
        }

        if (is_array($source)) {
            return $source;
        }

        return [];
    }

    /**
     * @param string $key
     * @param array $data
     *
     * @return void
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 11:25 ICT
     */
    public static function hmset(string $key, array $data): void
    {
        if (!self::isEnable()) {
            return;
        }

        $redis = Redis::connection();

        $redis->hmset($key, $data);
    }
}
