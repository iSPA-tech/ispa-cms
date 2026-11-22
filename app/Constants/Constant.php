<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Constants;

use ReflectionClass;

/**
 * Class Constant
 *
 * @package App\Constants
 *
 * Cache key will be used in this project
 * Cache split by ":" (colon) character
 * - First part is group name
 * - Second part is type
 * - Third part is options / params
 * - First part and second part will be tags store in redis and can be used to flush cache
 * - Legacy note: cache tag tracking via database has been removed alongside CacheTag model
 *
 * @last_updated 7.160.1175 - 2023-10-22, 17:48 ICT
 */
class Constant
{
    public const SEARCH = 'search';
    //  POST CACHE KEY
    public const CACHE_BY_POSTS = 'cache_posts';
    public const NEW_BY_LIMIT = 'new_by_limit';
    public const POST_DETAIL_BY_ID = 'post_detail:id';
    public const LATEST_WITH_AUDIO = 'latest_with_audio';
    public const LATEST_WITH_VIDEO = 'latest_with_video';
    public const LATEST_WITH_DOCUMENT = 'latest_with_document';
    public const LATEST_WITH_NOTIFICATION = 'latest_with_notification';
    public const LATEST_WITH_POST = 'latest_with_post';
    public const LATEST_WITH_ADMISSION = 'latest_with_admission';
    public const LATEST_WITH_EVENT = 'latest_with_event';
    public const LATEST_WITH_PAGE = 'latest_with_page';
    public const RELATED_POSTS = 'related_posts';
    public const SEARCH_BY_TYPE = 'search_by_type';
    public const POSTS_BY_CATEGORIES_IDS = 'category_ids_by_type';
    public const POSTS_BY_REQUIRED_LOGIN = 'posts_by_required_login';
    public const ADVANCED_SEARCH = 'advanced_search';
    public const CACHE_POSTS = [
        self::CACHE_BY_POSTS => self::CACHE_BY_POSTS . ':post_list:lang_%s:type_%s:pageSize_%s:page_%s',
        self::NEW_BY_LIMIT => self::CACHE_BY_POSTS . ':new_post:lang_%s:pageSize_%s',
        self::POST_DETAIL_BY_ID => self::CACHE_BY_POSTS . ':post_detail:type_%s:by_id_%s',
        self::LATEST_WITH_AUDIO => self::CACHE_BY_POSTS . ':latest_with_audio:lang_%s:pageSize_%s',
        self::LATEST_WITH_EVENT => self::CACHE_BY_POSTS . ':latest_with_events:lang_%s:pageSize_%s',
        self::LATEST_WITH_VIDEO => self::CACHE_BY_POSTS . ':latest_with_video:lang_%s:pageSize_%s',
        self::LATEST_WITH_DOCUMENT => self::CACHE_BY_POSTS . ':latest_with_document:lang_%s:pageSize_%s',
        self::LATEST_WITH_POST => self::CACHE_BY_POSTS . ':latest_with_post:lang_%s:pageSize_%s',
        self::RELATED_POSTS => self::CACHE_BY_POSTS . ':related_posts:lang_%s:category_%s:post_%s:pageSize_%s',
        self::SEARCH_BY_TYPE => self::CACHE_BY_POSTS . ':search_by_type:lang_%s:type_%s:keywords_%s:pageSize_%s:page_%s',
        self::POSTS_BY_CATEGORIES_IDS => self::CACHE_BY_POSTS . ':category_ids_by_type:lang_%s:type_%s:category_ids_%s:pageSize_%s:page_%s',
        self::SEARCH => self::CACHE_BY_POSTS . ':search:lang_%s:type_%s:category_ids_%s:keywords_%s:pageSize_%s:page_%s',
        self::POSTS_BY_REQUIRED_LOGIN => self::CACHE_BY_POSTS . ':posts_by_required_login:lang_%s:type_%s:pageSize_%s:page_%s',
        self::ADVANCED_SEARCH => self::CACHE_BY_POSTS . ':advanced_search:lang_%s:filter_%s',
    ];

    //  CATEGORY CACHE KEY
    public const CACHE_BY_CATEGORIES = 'cache_categories';
    public const CATEGORY_DETAIL_BY_ID = 'category_detail:id';
    public const ALL_WITH_POST = 'all_with_post';
    public const ALL_WITH_VIDEO = 'all_with_video';
    public const ALL_WITH_AUDIO = 'all_with_audio';
    public const ALL_WITH_DOCUMENT = 'all_with_document';
    public const AlL_TREE_BY_SLUG = 'all_tree_by_slug';
    public const FEATURED_CATEGORY = 'featured_category';
    public const CACHE_CATEGORIES = [
        self::ALL_WITH_AUDIO => self::CACHE_BY_CATEGORIES . ':all_with_audio:lang_%s',
        self::ALL_WITH_VIDEO => self::CACHE_BY_CATEGORIES . ':all_with_video:lang_%s',
        self::ALL_WITH_DOCUMENT => self::CACHE_BY_CATEGORIES . ':all_with_document:lang_%s',
        self::ALL_WITH_POST => self::CACHE_BY_CATEGORIES . ':all_with_post:lang_%s',
        self::CATEGORY_DETAIL_BY_ID => self::CACHE_BY_CATEGORIES . ':category_detail:by_type_%s:by_id_%s',
        self::AlL_TREE_BY_SLUG => self::CACHE_BY_CATEGORIES . ':all_tree_by_slug:by_type_%s:lang_%s',
        self::FEATURED_CATEGORY => self::CACHE_BY_CATEGORIES . ':featured_category:by_type_%s:lang_%s:limit_%s',
    ];

    //  PRODUCT CACHE KEY
    public const CACHE_BY_PRODUCTS = 'cache_products';
    public const CACHE_BY_PRODUCT_VARIANT_OPTIONS = 'cache_product_variant_options';
    public const PRODUCT_DETAIL_BY_ID = 'product_detail:id';
    public const PRODUCT_TOP_SELLER = 'product_top_seller';
    public const CACHE_PRODUCTS = [
        self::PRODUCT_DETAIL_BY_ID => self::CACHE_BY_PRODUCTS . ':product_detail:by_id_%s',
        self::PRODUCT_TOP_SELLER => self::CACHE_BY_PRODUCTS . ':product_top_seller:lang_%s:pageSize_%s:page_%s',

    ];

    //  GALLERY CACHE KEY
    public const CACHE_BY_GALLERIES = 'cache_galleries';
    public const GALLERY_DETAIL_BY_ID = 'gallery_detail:id';
    public const GALLERY_LATEST = 'gallery_latest';
    public const CACHE_GALLERIES = [
        self::CACHE_BY_GALLERIES => self::CACHE_BY_GALLERIES . ':gallery_list:lang_%s:pageSize_%s:page_%s',
        self::GALLERY_LATEST => self::CACHE_BY_GALLERIES . ':gallery_list:latest:lang_%s:pageSize_%s:page_%s',
        self::GALLERY_DETAIL_BY_ID => self::CACHE_BY_GALLERIES . ':gallery_detail:by_id_%s',
    ];

    //  PLACE CACHE KEY
    public const CACHE_BY_PLACES = 'cache_places';
    public const PLACE_DETAIL_BY_ID = 'place_detail:id';
    public const CACHE_PLACES = [
        self::PLACE_DETAIL_BY_ID => self::CACHE_BY_PLACES . ':place_detail:by_id_%s',
    ];

    //  ITEM CACHE KEY
    public const CACHE_BY_ITEMS = 'cache_items';
    public const ITEM_DETAIL_BY_ID = 'item_detail:id';
    public const CACHE_ITEMS = [
        self::ITEM_DETAIL_BY_ID => self::CACHE_BY_ITEMS . ':item_detail:by_id_%s',
    ];

    //  BRAND CACHE KEY
    public const CACHE_BY_BRANDS = 'cache_brands';
    public const BRAND_DETAIL_BY_ID = 'brand_detail:id';
    public const CACHE_BRANDS = [
        self::BRAND_DETAIL_BY_ID => self::CACHE_BY_BRANDS . ':brand_detail:by_id_%s',
    ];

    //  PRODUCT BUNDLE CACHE KEY
    public const CACHE_BY_PRODUCT_BUNDLES = 'cache_product_bundles';
    public const PRODUCT_BUNDLE_DETAIL_BY_ID = 'product_bundle_detail:id';
    public const CACHE_PRODUCT_BUNDLES = [
        self::PRODUCT_BUNDLE_DETAIL_BY_ID => self::CACHE_BY_PRODUCT_BUNDLES . ':product_bundle_detail:by_id_%s',
    ];
    //  FIELD CACHE KEY
    public const CACHE_BY_FIELDS = 'cache_fields';
    public const FIELD_DETAIL_BY_ID = 'field_detail:id';
    public const CACHE_FIELDS = [
        self::FIELD_DETAIL_BY_ID => self::CACHE_BY_FIELDS . ':field_detail:by_id_%s',
    ];
    //  COMMENT CACHE KEY
    public const CACHE_BY_COMMENTS = 'cache_comments';
    public const COMMENT_DETAIL_BY_ID = 'comment_detail:id';
    public const COMMENT_BY_TARGET = 'comment_by_target';
    public const CACHE_COMMENTS = [
        self::COMMENT_DETAIL_BY_ID => self::CACHE_BY_COMMENTS . ':comment_detail:by_id_%s',
        self::COMMENT_BY_TARGET => self::CACHE_BY_COMMENTS . ':comment_by_target:target_id_%s:target_type_%s:reply_to_%s',
    ];
    //  MENU CACHE KEY
    public const CACHE_BY_MENUS = 'cache_menus';
    public const MENU_DETAIL_BY_ID = 'menu_detail:id';
    public const MENU_BY_GROUP_KEY = 'menu_by_group_key';
    public const CACHE_MENUS = [
        self::MENU_DETAIL_BY_ID => self::CACHE_BY_MENUS . ':menu_detail:by_id_%s',
        self::MENU_BY_GROUP_KEY => self::CACHE_BY_MENUS . ':menu_by_group_key:group_key_%s:lang_id_%s',
    ];
    //  BOOKMARK CACHE KEY
    public const CACHE_BY_BOOKMARKS = 'cache_bookmarks';
    public const BOOKMARK_DETAIL_BY_ID = 'bookmark_detail:id';
    public const BOOKMARK_BY_USER = 'bookmark_by_user';
    public const BOOKMARK_BY_LATEST = 'bookmark_by_latest';
    public const CACHE_BOOKMARKS = [
        self::CACHE_BY_BOOKMARKS => self::CACHE_BY_BOOKMARKS . ':bookmark_list:user_id_%s:target_type_%s:page_%s:pageSize_%s',
        self::BOOKMARK_DETAIL_BY_ID => self::CACHE_BY_BOOKMARKS . ':bookmark_detail:by_id_%s',
        self::BOOKMARK_BY_USER => self::CACHE_BY_BOOKMARKS . ':bookmark_by_user:user_id_%s:target_type_%s',
        self::BOOKMARK_BY_LATEST => self::CACHE_BY_BOOKMARKS . ':bookmark_by_latest:user_id_%s:target_type_%s:limit_%s',
    ];
    //  BANNER CACHE KEY
    public const CACHE_BY_BANNERS = 'cache_banners';
    public const CACHE_BY_LUGS = 'cache_slugs';
    public const BANNER_DETAIL_BY_ID = 'banner_detail:id';
    public const BANNER_BY_TYPE = 'banner_by_type';
    public const CACHE_BANNERS = [
        self::CACHE_BY_BANNERS => self::CACHE_BY_BANNERS . ':banner_list:lang_%s:pageSize_%s:page_%s',
        self::BANNER_DETAIL_BY_ID => self::CACHE_BY_BANNERS . ':banner_detail:by_id_%s',
        self::BANNER_BY_TYPE => self::CACHE_BY_BANNERS . ':banner_by_type:lang_%s:type_%s',
    ];
    //  BANNER TYPE
    public const BANNER_TYPE_POPUP = 'popup';
    public const BANNER_TYPE_ADS_336_280 = '336_280';
    //  AUDIT LOG CACHE KEY
    public const CACHE_BY_AUDIT_LOG_USER_ID = 'cache_audit_logs:user_id_%s:page_%s:pageSize_%s';

    public const CATEGORY_TYPE_ARTICLE = 0;
    public const CATEGORY_TYPE_PLACE = 1;
    public const CATEGORY_TYPE_GALLERY = 2;
    public const CATEGORY_TYPE_EVENT = 3;
    public const CATEGORY_TYPE_PRODUCT = 4;
    public const CATEGORY_TYPE_UPLOAD = 5;
    public const CATEGORY_TYPE_ROLE = 6;
    /** @var int Document limited */
    public const DOCUMENT_LIMIT = 6;
    /** @var int Document per page */
    public const DOCUMENT_PER_PAGE = 10;
    // UPLOAD/DOCUMENT/IMAGE
    /** @var string cache key banner */
    public const CACHE_KEY_BANNER = 'banners';
    /** @var string cache key calendars */
    public const CACHE_KEY_CALENDARS = 'calendars';
    /** @var string cache key categories */
    public const CACHE_KEY_CATEGORIES = 'categories';
    /** @var string cache key categories-target */
    public const CACHE_KEY_CATEGORIES_TARGET = 'categories_target';
    /** @var string cache key counter */
    public const CACHE_KEY_COUNTER = 'counter';
    /** @var string cache key documents */
    public const CACHE_KEY_DOCUMENTS = 'documents';
    /** @var string cache key featured-category */
    public const CACHE_KEY_FEATURED_CATEGORY = 'feature_category';
    /** @var string cache key galleries */
    public const CACHE_KEY_GALLERIES = 'galleries';
    /** @var string cache key media-video */
    public const CACHE_KEY_MEDIA_VIDEO = 'media_video';
    /** @var string cache key media-audio */
    public const CACHE_KEY_MEDIA_AUDIO = 'media_audio';
    /** @var string cache key media-power-point */
    public const CACHE_KEY_MEDIA_POWERPOINT = 'media_powerpoint';
    /** @var string cache key menus */
    public const CACHE_KEY_MENUS = 'menus';
    /** @var string cache key menu-footer */
    public const CACHE_KEY_MENU_FOOTER = 'footer_menu';
    public const CACHE_KEY_MENU_FOOTER_SECONDARY = 'secondary_footer';
    /** @var string cache key category_ids_by_type_%s */
    public const CACHE_KEY_CATEGORY_IDS_BY_TYPE = 'category_ids_by_type_%s';
    public const CACHE_KEY_POST_IDS_BY_TYPE = 'post_ids_by_type_%s';
    public const CACHE_KEY_MENU_MAIN = 'main_menu';
    public const CACHE_KEY_MENU_ACCOUNT = 'account_menu';
    /** @var string cache key news */
    public const CACHE_KEY_NEWS = 'news';
    /** @var string cache key latest-document */
    public const CACHE_KEY_LATEST_DOCUMENT = 'latest_document';
    /** @var string cache key posts */
    public const CACHE_KEY_POSTS = 'posts';
    /** @var string cache key posts detail table + id */
    public const CACHE_KEY_DETAIL = 'detail_%s_%s';
    /** @var string cache key place detail */
    public const CACHE_KEY_PLACE_DETAIL = 'place_detail_%s';
    /** @var string cache key posts-slider */
    public const CACHE_KEY_POSTS_SLIDER = 'posts_slider';
    /** @var string cache key posts-list */
    public const CACHE_KEY_POSTS_LIST = 'posts_list';
    /** @var string cache key products */
    public const CACHE_KEY_PRODUCTS = 'products';
    /** @var string cache key product detail */
    public const CACHE_KEY_PRODUCT_DETAIL = 'product_detail_%s';
    /** @var string cache key product variant detail */
    public const CACHE_KEY_PRODUCT_VARIANT_DETAIL = 'product_detail_%s_variant';
    /** @var string cache key search */
    public const CACHE_KEY_SEARCH = 'search';
    /** @var string cache key slugs */
    public const CACHE_KEY_SLUGS = 'slugs';
    /** @var string cache key uploads */
    public const CACHE_KEY_UPLOADS = 'uploads';

    // TRACKING TYPE
    public const TRACKING_TYPE_DOCUMENT = 'documents';
    public const TRACKING_TYPE_AUDIO = 'audios';
    public const TRACKING_TYPE_CATEGORY = 'categories';
    public const TRACKING_TYPE_PAGE = 'pages';
    public const TRACKING_TYPE_PRODUCT = 'products';
    public const TRACKING_TYPE_PRODUCT_DETAIL = 'product_detail';
    public const TRACKING_TYPE_PRODUCT_CATEGORY = 'product_categories';
    public const TRACKING_TYPE_ARTICLE = 'articles';
    public const TRACKING_TYPE_ARTICLE_CATEGORY = 'article_categories';
    public const TRACKING_TYPE_DOWNLOAD = 'downloads';

    //VIEW/HOMEPAGE/SINGLE PAGE
    public const PAGE_VIEW = 'page_view';
    public const DOWNLOAD = 'download';
    public const HOMEPAGE = 'homepage';
    public const POSTS = 'posts';
    public const DOCUMENT = 'document';
    public const GALLERY = 'gallery';
    public const HTTP_NOT_FOUND = '404';
    // TAG tracking
    public const TAG_SCAN = 'scan';

    /**
     * Get all constants defined in the class
     *
     * @return array
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-23, 23:25 ICT
     */
    public static function getConstants(): array
    {
        // Get the class name
        $className = static::class;

        // Create a ReflectionClass instance for the class
        // Get all constants defined in the class
        return (new ReflectionClass($className))->getConstants();
    }
}
