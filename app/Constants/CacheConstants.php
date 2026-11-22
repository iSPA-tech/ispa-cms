<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Constants;

use ReflectionClass;

class CacheConstants
{
    public const CACHE_BASIC_TOKEN = 'cache:basic_token';
    public const CACHE_SETTINGS = 'cache:static:settings';
    public const CACHE_MAIN_MENU = 'cache:static:main-menu';
    public const CACHE_FOOTER_MENU = 'cache:static:footer-menu';
    public const CACHE_ABOUT_BY_SLUG = 'cache:about:%s';
    public const CACHE_POST_BY_SLUG = 'cache:post:slug:%s';
    public const CACHE_POST_ALL = 'cache:post:all';
    public const CACHE_CATEGORY_BY_SLUG = 'cache:category:%s:tree-with-post:%s';
    public const CACHE_CATEGORY_CHILDREN = 'cache:category-children:%s';
    public const CACHE_CATEGORIES_BY_TYPE = 'cache:categories-by-type:%s';
    public const CACHE_POSTS_BY_CONFIG = 'cache:post:%s:config:%s:limit:%s';
    public const CACHE_POSTS_BY_DATE_RANGE = 'cache:%s:date:%s-%s';
    public const CACHE_POSTS_BY_CATEGORY_SLUG = 'cache:post:%s:category:%s:pageSize:%s:page:%s';
    public const CACHE_POST_BY_TYPE_AND_UUID = 'cache:post:%s:slug:%s';
    public const CACHE_LATEST_POSTS_BY_CATEGORY_SLUG = 'cache:post:latest:category:%s:pageSize:%s';
    public const CACHE_CATEGORY_ALL = 'cache:category:all';
    public const CACHE_ALL_POSTS_OF_CATEGORY_TREE = 'cache:category:%s:all-posts-of-tree:%s';
    public const CACHE_FEATURED_CATEGORIES = 'cache:category:featured:%s';
    public const CACHE_FEATURED_POSTS = 'cache:post:featured:%s';
    public const CACHE_LATEST_POSTS = 'cache:post:latest:%s';
    public const CACHE_TOP_VIEW = 'cache:top-view:%s:%s:%s';

    /**
     * Seconds a client `users:{uuid}` snapshot stays in Redis. Nothing reads it
     * back, and without a TTL the shared `volatile-lru` Redis can never evict it.
     */
    public const CLIENT_USER_SNAPSHOT_TTL = 86400;

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
