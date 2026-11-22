<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Repositories;

use App\Constants\Constant;
use Cms\Core\Helpers\CacheHelper;
use Cms\Content\Models\BookMark;
use Cms\Content\Models\Post;
use Cms\Content\Interfaces\BookmarkInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Eloquent repository for user bookmarks with cache-backed queries.
 *
 * Implements {@see BookmarkInterface} for {@see BookMark} persistence.
 * Provides paginated and latest bookmark retrieval filtered by target type and user.
 *
 * @see BookMark
 * @see BookmarkInterface
 * @package Cms\Content\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class BookmarkRepository extends RepositoriesAbstract implements BookmarkInterface
{
    /**
     * Initialize the repository and bind the BookMark model.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(BookMark::class);
    }

    /**
     * Get bookmark list by target
     *
     * @param string $type
     * @param int $page
     * @param int $pageSize
     * @param int $userId
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-24, 20:16 ICT
     */
    public function getBookmarkListByTarget(string $type, int $page, int $pageSize, int $userId, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;

        $cacheKey = sprintf(Constant::CACHE_BOOKMARKS[Constant::CACHE_BY_BOOKMARKS], $userId, $type, $page, $pageSize);

        return cache()->remember(
            $cacheKey,
            $ttl,
            function () use ($cacheKey, $ttl, $type, $userId, $page, $pageSize) {
                CacheHelper::getInstance()->store($cacheKey, 'Get Bookmark By Target: ' . $type . ' and user ' . $userId . ' by Page: ' . $page . ' and pageSize: ' . $pageSize, $ttl);

                return $this->get([
                    'where' => [
                        'target_type' => $this->resolveTargetType($type),
                        'user_id' => $userId,
                    ],
                    'with' => [
                        'target' => function ($query) {
                            $query->select('id', 'name', 'category_id', 'user_id', 'type')->with([
                                'slugs:target_id,slug',
                                'category' => fn($q) => $q->select('id', 'name')->with('slugs:target_id,slug'),
                                'author:id,name',
                            ]);
                        },
                    ],
                    'page' => $pageSize,
                    'latest' => true,
                ]);
            }
        );
    }

    /**
     * Get latest bookmark
     *
     * @param string $type
     * @param int $userId
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-20, 20:48 ICT
     */
    public function getLatestBookmark(string $type, int $userId, int $limit = 10, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;

        $cacheKey = sprintf(Constant::CACHE_BOOKMARKS[Constant::BOOKMARK_BY_LATEST], $userId, $type, $limit);

        return cache()->remember(
            $cacheKey,
            $ttl,
            function () use ($cacheKey, $ttl, $type, $userId, $limit) {
                CacheHelper::getInstance()->store($cacheKey, 'Get Bookmark By Target: ' . $type . ' and user ' . $userId, $ttl);

                return $this->get([
                    'where' => [
                        'target_type' => $this->resolveTargetType($type),
                        'user_id' => $userId,
                    ],
                    'with' => [
                        'target' => function ($query) {
                            $query->select('id', 'name', 'category_id', 'user_id', 'type')->with([
                                'slugs:target_id,slug',
                                'category' => fn($q) => $q->select('id', 'name')->with('slugs:target_id,slug'),
                                'author:id,name',
                            ]);
                        },
                    ],
                    'hasMorph' => [
                        'target' => [
                            $this->resolveTargetType($type),
                        ],
                    ],
                    'order' => [
                        'created_at' => 'desc',
                    ],
                    'take' => $limit,
                ]);
            }
        );
    }

    /**
     * Get bookmark list
     *
     * @param string $type
     * @param int $userId
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-19, 11:22 ICT
     */
    public function getBookmarkByTarget(string $type, int $userId, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;

        $cacheKey = sprintf(Constant::CACHE_BOOKMARKS[Constant::BOOKMARK_BY_USER], $userId, $type);

        return cache()->remember(
            $cacheKey,
            $ttl,
            function () use ($cacheKey, $ttl, $type, $userId) {
                CacheHelper::getInstance()->store($cacheKey, 'Get Bookmark By Target: ' . $type . ' and user ' . $userId, $ttl);

                return $this->get([
                    'where' => [
                        'target_type' => $this->resolveTargetType($type),
                        'user_id' => $userId,
                    ],
                    'with' => [
                        'target' => function ($query) {
                            $query->select('id', 'name')->with('slugs:target_id,slug');
                        },
                    ],
                ]);
            }
        );
    }

    private function resolveTargetType(string $type): string
    {
        $type = ltrim(trim($type), '\\');

        $contentPostTypes = [
            'post',
            strtolower(Post::class),
            'app\\models\\post',
            'app\\models\\articles\\post',
        ];

        if (in_array(strtolower($type), $contentPostTypes, true)) {
            return Post::class;
        }

        if (class_exists($type)) {
            return $type;
        }

        return 'App\Models\\' . str($type)->ucfirst();
    }
}
