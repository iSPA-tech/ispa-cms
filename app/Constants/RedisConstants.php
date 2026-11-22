<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Constants;

use ReflectionClass;

class RedisConstants
{
    public const META = 'meta';
    public const POSTDETAIL = 'post_detail';
    public const POST_DETAIL = self::POSTDETAIL . ':%s';
    public const POSTS = 'posts';

    public const PLACES = 'places';
    public const PUBLIC = 'public';
    public const PUBLIC_POSTS = 'public_posts';
    public const PUBLIC_PLACES = 'public_places';
    public const SETTINGS = 'settings';
    public const USER = 'user';


    //  TIME TO LIVE
    public const TTL_1_MINUTE = 60;
    public const TTL_1_HOUR = 3600;
    public const TTL_1_DAY = 86400;
    public const TTL_1_WEEK = 604800;
    public const TTL_1_MONTH = 2592000;
    public const TTL_1_YEAR = 31536000;

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
