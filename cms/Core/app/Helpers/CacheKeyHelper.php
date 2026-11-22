<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Helpers;

use App\Constants\Constant;
use App\Constants\CacheConstants;
use Cms\Core\Services\Cache\CacheVersionService;

/**
 * Centralised cache-key factory for all CMS entity types.
 *
 * Every public method produces a deterministic, language-aware cache key
 * string for a specific entity or listing query. Using a single factory
 * guarantees that cache writes and reads always agree on key format, and
 * the built-in language tagging prevents cross-locale cache collisions.
 *
 * @see \Cms\Core\Helpers\CacheHelper
 * @see \Cms\Core\Helpers\RedisHelper
 * @see \App\Constants\CacheConstants
 * @package Cms\Core\Helpers
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CacheKeyHelper
{
    /**
     * Build a cache key for banners by their display type.
     *
     * Validates the type against a whitelist of known banner formats
     * before generating the key. Falls back to a custom key for
     * unrecognised types.
     *
     * @param  string $type  Banner type identifier (e.g. 'slider_main', 'popup', '970_90')
     * @return string        Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function bannerType(string $type = ''): string
    {
        /** Type must be in this value list
         *[
         * { value: 'slider_main', label: 't.banner.slider_main' },
         * { value: 'slider_secondary', label: 't.banner.slider_secondary' },
         * { value: 'target', label: 't.banner.target' },
         * { value: 'popup', label: 't.banner.popup' },
         * { value: '970_90', label: 't.banner.970_90' },
         * { value: '728_90', label: 't.banner.728_90' },
         * { value: '336_280', label: 't.banner.336_280' },
         * { value: '300_600', label: 't.banner.300_600' },
         * { value: '320_50', label: 't.banner.320_50' },
         * ]
         */
        if (!in_array($type, [
            'slider_main', 'slider_secondary', 'target', 'popup', '970_90', '728_90', '336_280', '300_600', '320_50',
        ], true)) {
            return app(CacheVersionService::class)->versionedKey(self::custom([
                'type' => Constant::CACHE_KEY_BANNER,
                'banner_type' => $type,
                'lang' => self::getLangId(),
            ]), Constant::CACHE_BY_BANNERS);
        }

        return app(CacheVersionService::class)->versionedKey(self::custom([
            'type' => Constant::CACHE_KEY_BANNER,
            'banner_type' => $type,
            'lang' => self::getLangId(),
        ]), Constant::CACHE_BY_BANNERS);
    }

    /**
     * Generate a dynamic cache key from key:value pairs joined by a separator.
     *
     * This is the flexible fallback used when no predefined constant pattern
     * exists. Each array entry is formatted as "key:value" and the entries
     * are concatenated with the given separator.
     *
     * @param  array  $data       Associative array of key-value segments
     * @param  string $separator  Delimiter between segments (default '|')
     * @return string             Composed cache key string
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function custom(array $data = [], string $separator = '|'): string
    {
        return implode($separator, array_map(
            static fn($v, $k) => sprintf('%s:%s', $k, $v),
            $data,
            array_keys($data)
        ));
    }

    /**
     * Resolve the current language ID from the application context.
     *
     * Delegates to the global `lang()` helper with a `get_lang_id()` fallback.
     *
     * @return int  Current language ID
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function getLangId(): int
    {
        return lang('id', get_lang_id());
    }

    /**
     * Build a cache key for the top-viewed posts within a date range.
     *
     * @param  string $range  Date range shorthand (e.g. '1y', '6m', '30d')
     * @param  int    $limit  Maximum number of posts to cache
     * @return string         Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function topViewOfPostByRange(string $range = '1y', int $limit = 4): string
    {
        return self::withLang(
            self::format(CacheConstants::CACHE_TOP_VIEW, 'post:' . self::getLangId(), $range, $limit)
        );
    }

    /**
     * Format a cache key by applying sprintf arguments to a constant pattern.
     *
     * Array parameters are imploded to comma-separated strings before
     * being passed to sprintf, preventing type errors.
     *
     * @param  string $pattern  sprintf-compatible pattern string
     * @param  mixed  ...$params  Values to interpolate into the pattern
     * @return string             Formatted cache key string
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function format(string $pattern, mixed ...$params): string
    {
        $safeParams = array_map(static function ($param) {
            return is_array($param) ? implode(',', $param) : $param;
        }, $params);

        return sprintf($pattern, ...$safeParams);
    }

    /**
     * Ensure a cache key includes an explicit language tag suffix.
     *
     * If the key already contains a "lang:" segment, it is returned
     * unchanged; otherwise a ":lang:{id}" suffix is appended.
     *
     * @param  string          $key     The cache key to tag
     * @param  int|string|null $langId  Language ID override; defaults to current language
     * @return string                   Language-tagged cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    protected static function withLang(string $key, int|string|null $langId = null): string
    {
        $langId ??= self::getLangId();

        return str_contains($key, 'lang:') ? $key : sprintf('%s:lang:%s', $key, $langId);
    }

    /**
     * Build a cache key for a single post looked up by its slug.
     *
     * @param  string   $slug        The post slug
     * @param  int|null $languageId  Explicit language ID; defaults to current language
     * @return string                Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function postBySlug(string $slug, ?int $languageId = null): string
    {
        $languageId ??= self::getLangId();

        return self::withLang(self::format(
            CacheConstants::CACHE_POST_BY_SLUG,
            sprintf('%s:lang:%s', $slug, $languageId)
        ), $languageId);
    }

    // === Convenient shortcuts using CacheConstants (optional, readable) ===

    /**
     * Build a cache key for posts filtered by category IDs with pagination.
     *
     * @param  array  $ids   Category IDs to filter by
     * @param  int    $page  Current page number
     * @param  int    $limit Number of posts per page
     * @param  string $type  Post type (e.g. 'post', 'audio', 'video')
     * @return string        Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function postByCategoryIds(array $ids = [], int $page = 1, int $limit = 12, string $type = 'post'): string
    {
        return self::withLang(self::format(Constant::CACHE_POSTS[Constant::POSTS_BY_CATEGORIES_IDS],
            self::getLangId(), $type,
            implode(',', $ids), $limit, $page
        ));
    }

    /**
     * Extract tag components from a colon-delimited cache key.
     *
     * @param  string $key  The full cache key string
     * @return array        Array of the first two key segments (group and type)
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function tagFromKey(string $key): array
    {
        // For example: "cache_posts:new_post:lang_en:pageSize_10"
        $parts = explode(':', $key);
        if (count($parts) < 2) {
            return [$key]; // Return the original key if it doesn't match the expected format
        }

        return [$parts[0], $parts[1]]; // e.g., ['cache_posts', 'new_post']
    }

    /**
     * Parse a colon-delimited cache key into its structural components.
     *
     * @param  string $key  The full cache key string
     * @return array{group: string|null, type: string|null, params: array}
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function parse(string $key): array
    {
        $parts = explode(':', $key);
        return [
            'group' => $parts[0] ?? null,
            'type' => $parts[1] ?? null,
            'params' => array_slice($parts, 2),
        ];
    }

    /**
     * Build a cache key for a category looked up by slug.
     *
     * @param  string $categorySlug  The category slug
     * @param  int    $limit         Number of items per page
     * @return string                Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function categoryBySlug(string $categorySlug, int $limit = 12): string
    {
        return self::withLang(self::format(
            CacheConstants::CACHE_CATEGORY_BY_SLUG,
            $categorySlug,
            sprintf('%s:lang:%s', $limit, self::getLangId())
        ));
    }

    /**
     * Build a cache key for all categories of a given type.
     *
     * @param  string $type  Category type identifier
     * @return string        Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function categoriesByType(string $type): string
    {
        return self::withLang(self::format(CacheConstants::CACHE_CATEGORIES_BY_TYPE, $type . '|' . self::getLangId()));
    }

    /**
     * Build a cache key for posts filtered by a configuration key.
     *
     * @param  string $postType   Post type (e.g. 'post', 'audio')
     * @param  string $configKey  Configuration key for the filter
     * @param  int    $limit      Maximum number of posts
     * @return string             Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function postsByConfig(string $postType, string $configKey, int $limit): string
    {
        return self::withLang(self::format(
            CacheConstants::CACHE_POSTS_BY_CONFIG,
            $postType,
            $configKey,
            sprintf('%s:lang:%s', $limit, self::getLangId())
        ));
    }

    /**
     * Build a cache key for posts within a date range.
     *
     * @param  string $postType  Post type identifier
     * @param  string $from      Start date (Y-m-d or similar)
     * @param  string $to        End date (Y-m-d or similar)
     * @return string            Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function postsByDateRange(string $postType, string $from, string $to): string
    {
        return self::withLang(self::format(
            CacheConstants::CACHE_POSTS_BY_DATE_RANGE,
            sprintf('%s|lang:%s', $postType, self::getLangId()),
            $from,
            $to
        ));
    }

    /**
     * Build a cache key for posts under a category slug with pagination.
     *
     * @param  string $postType  Post type identifier
     * @param  string $slug      Category slug
     * @param  int    $pageSize  Number of posts per page
     * @param  int    $page      Current page number
     * @return string            Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function postsByCategorySlug(string $postType, string $slug, int $pageSize, int $page): string
    {
        return self::withLang(self::format(
            CacheConstants::CACHE_POSTS_BY_CATEGORY_SLUG,
            sprintf('%s|lang:%s', $postType, self::getLangId()),
            $slug,
            $pageSize,
            $page
        ));
    }

    /**
     * Build a cache key for featured posts.
     *
     * @param  int    $limit  Maximum number of featured posts
     * @return string         Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function featuredPosts(int $limit = 6): string
    {
        return self::withLang(self::format(
            CacheConstants::CACHE_FEATURED_POSTS,
            sprintf('lang:%s:limit:%s', self::getLangId(), $limit)
        ));
    }

    /**
     * Build a cache key for the latest posts listing.
     *
     * @param  int    $limit  Maximum number of latest posts
     * @return string         Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function latestPosts(int $limit = 6): string
    {
        return self::withLang(self::format(
            CacheConstants::CACHE_LATEST_POSTS,
            sprintf('%s:lang:%s', $limit, self::getLangId())
        ));
    }

    /**
     * Build a cache key for the latest posts of a specific content type.
     *
     * Maps the type string to its corresponding constant before generating
     * the cache key, supporting audio, video, document, notification, and more.
     *
     * @param  string $type   Content type ('post', 'audio', 'video', 'document', etc.)
     * @param  int    $limit  Maximum number of posts
     * @return string         Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function latestPostByType(string $type = 'post', int $limit = 6): string
    {
        $typeConstant = match ($type) {
            'audio' => Constant::LATEST_WITH_AUDIO,
            'video' => Constant::LATEST_WITH_VIDEO,
            'document' => Constant::LATEST_WITH_DOCUMENT,
            'notification' => Constant::LATEST_WITH_NOTIFICATION,
            'admission' => Constant::LATEST_WITH_ADMISSION,
            'events' => Constant::LATEST_WITH_EVENT,
            'page' => Constant::LATEST_WITH_PAGE,
            default => Constant::LATEST_WITH_POST,
        };

        return self::withLang(self::format(Constant::CACHE_POSTS[$typeConstant], self::getLangId(), $limit));
    }

    /**
     * Build a cache key for the complete posts listing.
     *
     * @return string  Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function allPosts(): string
    {
        return self::withLang(CacheConstants::CACHE_POST_ALL);
    }

    /**
     * Build a cache key for the complete categories listing.
     *
     * @return string  Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function categoryAll(): string
    {
        return self::withLang(CacheConstants::CACHE_CATEGORY_ALL);
    }

    /**
     * Build a cache key for child categories of a given parent.
     *
     * @param  int    $parentId  Parent category ID (0 for root)
     * @return string            Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function categoryChildren(int $parentId = 0): string
    {
        return self::withLang(self::format(CacheConstants::CACHE_CATEGORY_CHILDREN, $parentId));
    }

    /**
     * Build a cache key for the basic authentication token.
     *
     * @return string  Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function basicToken(): string
    {
        return self::withLang(CacheConstants::CACHE_BASIC_TOKEN);
    }

    /**
     * Build a cache key for the application settings.
     *
     * @return string  Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function settings(): string
    {
        return self::withLang(CacheConstants::CACHE_SETTINGS);
    }

    /**
     * Build a cache key for the main navigation menu.
     *
     * @return string  Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function mainMenu(): string
    {
        return self::withLang(CacheConstants::CACHE_MAIN_MENU);
    }

    /**
     * Build a cache key for the footer navigation menu.
     *
     * @return string  Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function footerMenu(): string
    {
        return self::withLang(CacheConstants::CACHE_FOOTER_MENU);
    }

    /**
     * Build a cache key for featured categories by configuration key.
     *
     * @param  string $key  Configuration key identifying the featured group
     * @return string       Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function featuredCategories(string $key): string
    {
        return self::withLang(self::format(CacheConstants::CACHE_FEATURED_CATEGORIES, $key));
    }

    /**
     * Build a cache key for a single product by its ID.
     *
     * @param  int    $id  Product ID
     * @return string      Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function productDetailById(int $id): string
    {
        return self::withLang(self::format(Constant::CACHE_PRODUCTS[Constant::PRODUCT_DETAIL_BY_ID], $id));
    }

    // === Product, Gallery, Brand, Place, Item Helpers based on Constant mappings ===

    /**
     * Build a cache key for the top-selling products listing with pagination.
     *
     * @param  int|string|null $lang      Language ID override; defaults to current language
     * @param  int             $pageSize  Number of products per page
     * @param  int             $page      Current page number
     * @return string                     Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function topSellingProducts(int|string|null $lang = null, int $pageSize = 10, int $page = 1): string
    {
        $lang ??= self::getLangId();

        return self::withLang(self::format(Constant::CACHE_PRODUCTS[Constant::PRODUCT_TOP_SELLER], $lang, $pageSize, $page), $lang);
    }

    /**
     * Build a cache key for a single gallery by its ID.
     *
     * @param  int    $id  Gallery ID
     * @return string      Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function galleryDetailById(int $id): string
    {
        return self::withLang(self::format(Constant::CACHE_GALLERIES[Constant::GALLERY_DETAIL_BY_ID], $id));
    }

    /**
     * Build a cache key for the gallery listing with pagination.
     *
     * @param  int|string|null $lang      Language ID override; defaults to current language
     * @param  int             $pageSize  Number of galleries per page
     * @param  int             $page      Current page number
     * @return string                     Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function galleryList(int|string|null $lang = null, int $pageSize = 10, int $page = 1): string
    {
        $lang ??= self::getLangId();

        return self::withLang(self::format(Constant::CACHE_GALLERIES[Constant::CACHE_BY_GALLERIES], $lang, $pageSize, $page), $lang);
    }

    /**
     * Build a cache key for the latest galleries listing with pagination.
     *
     * @param  int|string|null $lang      Language ID override; defaults to current language
     * @param  int             $pageSize  Number of galleries per page
     * @param  int             $page      Current page number
     * @return string                     Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function latestGallery(int|string|null $lang = null, int $pageSize = 10, int $page = 1): string
    {
        $lang ??= self::getLangId();

        return self::withLang(self::format(Constant::CACHE_GALLERIES[Constant::GALLERY_LATEST], $lang, $pageSize, $page), $lang);
    }

    /**
     * Build a cache key for a single brand by its ID.
     *
     * @param  int    $id  Brand ID
     * @return string      Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function brandDetailById(int $id): string
    {
        return self::withLang(self::format(Constant::CACHE_BRANDS[Constant::BRAND_DETAIL_BY_ID], $id));
    }

    /**
     * Build a cache key for a single place by its ID.
     *
     * @param  int    $id  Place ID
     * @return string      Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function placeDetailById(int $id): string
    {
        return self::withLang(self::format(Constant::CACHE_PLACES[Constant::PLACE_DETAIL_BY_ID], $id));
    }

    /**
     * Build a cache key for a single item by its ID.
     *
     * @param  int    $id  Item ID
     * @return string      Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function itemDetailById(int $id): string
    {
        return self::withLang(self::format(Constant::CACHE_ITEMS[Constant::ITEM_DETAIL_BY_ID], $id));
    }

    /**
     * Build a cache key for a single product bundle by its ID.
     *
     * @param  int    $id  Product bundle ID
     * @return string      Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function productBundleDetailById(int $id): string
    {
        return self::withLang(self::format(Constant::CACHE_PRODUCT_BUNDLES[Constant::PRODUCT_BUNDLE_DETAIL_BY_ID], $id));
    }

    /**
     * Build a cache key for a single custom field by its ID.
     *
     * @param  int    $id  Field ID
     * @return string      Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function fieldDetailById(int $id): string
    {
        return self::withLang(self::format(Constant::CACHE_FIELDS[Constant::FIELD_DETAIL_BY_ID], $id));
    }

    /**
     * Build a cache key for a single comment by its ID.
     *
     * @param  int    $id  Comment ID
     * @return string      Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function commentDetailById(int $id): string
    {
        return self::withLang(self::format(Constant::CACHE_COMMENTS[Constant::COMMENT_DETAIL_BY_ID], $id));
    }

    /**
     * Build a cache key for comments on a specific target entity.
     *
     * @param  int    $targetId    ID of the target entity (post, product, etc.)
     * @param  string $targetType  Polymorphic target type
     * @param  int    $replyTo     Parent comment ID for threaded replies (0 for top-level)
     * @return string              Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function commentByTarget(int $targetId, string $targetType, int $replyTo = 0): string
    {
        return self::withLang(self::format(Constant::CACHE_COMMENTS[Constant::COMMENT_BY_TARGET], $targetId, $targetType, $replyTo));
    }

    /**
     * Build a cache key for the banner listing with pagination.
     *
     * @param  int|string|null $lang      Language ID override; defaults to current language
     * @param  int             $pageSize  Number of banners per page
     * @param  int             $page      Current page number
     * @return string                     Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function bannerList(int|string|null $lang = null, int $pageSize = 10, int $page = 1): string
    {
        $lang ??= self::getLangId();

        return self::withLang(self::format(Constant::CACHE_BANNERS[Constant::CACHE_BY_BANNERS], $lang, $pageSize, $page), $lang);
    }

    /**
     * Build a cache key for a single banner by its ID.
     *
     * @param  int    $id  Banner ID
     * @return string      Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function bannerDetailById(int $id): string
    {
        return self::withLang(self::format(Constant::CACHE_BANNERS[Constant::BANNER_DETAIL_BY_ID], $id));
    }

    /**
     * Build a cache key for banners filtered by display type.
     *
     * @param  int|string|null $lang  Language ID override; defaults to current language
     * @param  string          $type  Banner type (e.g. 'slider_main', 'popup', '970_90')
     * @return string                 Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function bannerByType(int|string|null $lang = null, string $type = ''): string
    {
        $lang ??= self::getLangId();

        return self::withLang(self::format(Constant::CACHE_BANNERS[Constant::BANNER_BY_TYPE], $lang, $type), $lang);
    }

    /**
     * Build a cache key for a menu identified by its group key.
     *
     * @param  string          $groupKey  Menu group identifier (e.g. 'main', 'footer')
     * @param  int|string|null $langId    Language ID; defaults to the current language
     * @return string                     Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function menuByGroupKey(string $groupKey, int|string|null $langId = null): string
    {
        $langId ??= self::getLangId();

        return self::withLang(self::format(Constant::CACHE_MENUS[Constant::MENU_BY_GROUP_KEY], $groupKey, $langId), $langId);
    }

    /**
     * Build a cache key for all bookmarks of a user by target type.
     *
     * @param  int    $userId      User ID
     * @param  string $targetType  Polymorphic target type being bookmarked
     * @return string              Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function bookmarkByUser(int $userId, string $targetType): string
    {
        return self::withLang(self::format(Constant::CACHE_BOOKMARKS[Constant::BOOKMARK_BY_USER], $userId, $targetType));
    }

    /**
     * Build a cache key for the latest bookmarks of a user by target type.
     *
     * @param  int    $userId      User ID
     * @param  string $targetType  Polymorphic target type being bookmarked
     * @param  int    $limit       Maximum number of bookmarks
     * @return string              Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function bookmarkByLatest(int $userId, string $targetType, int $limit): string
    {
        return self::withLang(self::format(Constant::CACHE_BOOKMARKS[Constant::BOOKMARK_BY_LATEST], $userId, $targetType, $limit));
    }

    /**
     * Build a cache key for a paginated bookmark listing of a user.
     *
     * @param  int    $userId      User ID
     * @param  string $targetType  Polymorphic target type being bookmarked
     * @param  int    $page        Current page number
     * @param  int    $pageSize    Number of bookmarks per page
     * @return string              Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function bookmarkList(int $userId, string $targetType, int $page, int $pageSize): string
    {
        return self::withLang(self::format(Constant::CACHE_BOOKMARKS[Constant::CACHE_BY_BOOKMARKS], $userId, $targetType, $page, $pageSize));
    }

    /**
     * Build a cache key for paginated audit logs of a user.
     *
     * @param  int    $userId    User ID
     * @param  int    $page      Current page number
     * @param  int    $pageSize  Number of log entries per page
     * @return string            Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function auditLogsByUserId(int $userId, int $page, int $pageSize): string
    {
        return self::withLang(self::format(Constant::CACHE_BY_AUDIT_LOG_USER_ID, $userId, $page, $pageSize));
    }

    /**
     * Build a cache key for a slug detail lookup.
     *
     * @param  string $slug  The URL slug
     * @return string        Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function slugDetail(string $slug, int|string|null $langId = null): string
    {
        return self::withLang(self::format(Constant::CACHE_BY_LUGS . '|slug:%s', $slug), $langId);
    }

    /**
     * Build a cache key for all product variant options.
     *
     * @return string  Language-aware cache key
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function allProductVariantOption(): string
    {
        return self::withLang(self::format(Constant::CACHE_BY_PRODUCT_VARIANT_OPTIONS));
    }

    /**
     * Count all posts
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2025-04-27, 08:10 ICT
     */
    public static function countAllPosts(): string
    {
        return self::withLang(self::format('public:post:count'));
    }

    /**
     * Get all product-related cache keys for bulk invalidation.
     *
     * @return array  List of product cache key strings
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public static function getAllProductKeys(): array
    {
        return [
            self::allProductVariantOption(),
            self::topSellingProducts(),
        ];
    }
}
