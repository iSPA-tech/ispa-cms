<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use JsonException;
use App\Constants\Constant;
use Cms\Core\Helpers\CacheHelper;
use Cms\Content\Models\Post;
use Cms\Core\Models\Upload;
use Cms\Core\Models\CategoryTarget;
use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Cms\Core\Interfaces\CategoryTargetInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Class CategoryTargetRepository
 * Eloquent repository for the CategoryTarget pivot with helpers to fetch related uploads and posts.
 *
 * @extends RepositoriesAbstract
 * @implements CategoryTargetInterface
 * @see CategoryTarget
 *
 * @method \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Builder|array|null find(int $id, array $select = ['*'], array $with = [])
 * @method mixed get(array $params)
 * @package Cms\Core\Repositories
 */
class CategoryTargetRepository extends RepositoriesAbstract implements CategoryTargetInterface
{
    /**
     * Initialize the repository and bind the CategoryTarget model.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(CategoryTarget::class);
    }

    /**
     * Get upload file by type array
     *
     * @param string $type
     * @param array $condition
     *
     * @return mixed
     * @throws JsonException
     * @since 6.62.6 - 2022-08-21, 21:18 ICT
     * @author malayvuong
     */
    public function getUploadedByType(string $type = 'document', array $condition = []): mixed
    {
        $whereType = match ($type) {
            'document' => ['zip', 'rar', 'pdf', 'doc', 'docx', 'xls', 'xlsx'],
            'image' => ['jpg', 'jpeg', 'png', 'gif'],
            'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'wma'],
            'powerpoint' => ['ppt', 'pptx'],
        };

        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_CATEGORIES_TARGET, 'limit' => Constant::DOCUMENT_PER_PAGE,
            'page' => request('page', 1),
            Constant::CACHE_KEY_UPLOADS => implode('-', $whereType),
            'condition' => json_encode($condition, JSON_THROW_ON_ERROR),
        ]);

        $uploads = cache()->remember($cacheKey, config('setting.cache.upload', 3600), function () use ($type, $cacheKey, $whereType, $condition) {

            return $this->get([
                'select' => ['target_id', 'target_type'],
                'where' => [
                    'target_type' => Upload::class,
                ],
                'when' => [
                    [
                        isset($condition['IN']) && is_array($condition['IN']) && !empty($condition['IN']),
                        function ($q) use ($condition) {
                            $q->whereIn('category_id', $condition['IN']);
                        },
                    ],
                    [
                        isset($condition['id']) && $condition['id'] > 0,
                        function ($q) use ($condition) {
                            $q->where('category_id', $condition['id']);
                        },
                    ],
                ],
                'with' => [
                    'target' => function ($q) use ($whereType) {
                        $q->whereIn('type', $whereType)
                            ->select('id', 'name', 'path', 'type', 'name_alias', 'alt_text', 'mime_type', 'file_size', 'created_at', 'custom', 'config');
                    },
                ],
                'page' => $condition['limit'] ?? request('limit', 12),
            ]);
        });

        //  Set target value into its item in every post
        foreach ($uploads as $key => $upload) {
            if (!empty($upload->target)) {
                $uploads[$key] = $upload->target;
            }
        }

        return $uploads;
    }

    /**
     * Get related posts by category id and except current post id
     *
     * @param int $category_id
     * @param int $post_id
     * @param int $limit
     * @param string $type
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-08-21, 14:12 ICT
     */
    public function getRelatedPosts(int $category_id, int $post_id, int $limit = 6, string $type = 'post'): mixed
    {
        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_POSTS,
            Constant::CACHE_KEY_POSTS => 'related',
            'related' => $category_id . '-' . $post_id,
            'limit' => $limit,
            'article_type' => $type,
        ]);

        return cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($cacheKey, $category_id, $post_id, $limit, $type) {
            CacheHelper::getInstance()->store($cacheKey, 'Get related post by Category Id: ' . $category_id, config('setting.cache.blog', 3600));


            $posts = $this->get([
                'select' => ['target_id', 'target_type'],
                'where' => [
                    'category_id' => $category_id,
                    ['target_id', '!=', $post_id],
                    'target_type' => Post::class,
                ],
                'order' => [
                    'target_id' => 'DESC',
                    'category_id' => 'DESC',
                ],
                'take' => $limit,
                'with' => [
                    'target' => function ($q) {
                        return $q
                            ->where(function ($q) {
                                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                            })
                            ->published()->whereType('post')->select(
                                'id', 'name', 'description', 'video_url', 'config', 'user_id', 'published_at'
                            )->with([
                                'slugs:target_id,slug',
                                'author:id,name',
                            ]);
                    },
                ],
                'has' => [
                    'target' => function ($q) use ($type) {
                        return $q->published()->whereType($type);
                    },
                ],
            ]);

            //  Set target value into its item in every post
            foreach ($posts as $key => $post) {
                $posts[$key] = $post->target;
            }

            return $posts;
        });
    }

    /**
     * Get posts list by target_id
     *
     * @param array $ids
     * @param int $limit
     *
     * @return Collection|LengthAwarePaginator|array|null
     * @author malayvuong
     * @since 6.62.6 - 2022-08-21, 12:08 ICT
     */
    public function getPostsByCategoryIds(array $ids = [], int $limit = 12): Collection|LengthAwarePaginator|array|null
    {
        if (empty($ids)) {
            return null;
        }

        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_POSTS,
            Constant::CACHE_KEY_POSTS => 'category-ids',
            'ids' => implode('-', $ids),
            'limit' => $limit,
            'page' => request('page', 1),
        ]);

        return cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($cacheKey, $limit, $ids) {

            $posts = $this->get([
                'select' => ['target_id', 'target_type'],
                'where' => [
                    'target_type' => Post::class,
                    ['category_id', 'IN', $ids],
                ],
                'order' => [
                    'target_id' => 'DESC',
                    'category_id' => 'DESC',
                ],
                'page' => request('limit', $limit),
                'with' => [
                    'post' => function ($q) {
                        $q
                            ->where(function ($q) {
                                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                            })
                            ->published()->where('type', 'post')->select(
                                'id', 'name', 'description', 'video_url', 'config', 'user_id', 'published_at'
                            )->with([
                                'slugs:target_id,slug',
                                'author:id,name',
                            ])->orderBy('published_at', 'desc');
                    },
                ],
            ]);

            //  Set target value into its item in every post
            foreach ($posts as $key => $post) {
                if (count($ids) > 0) {
                    //  Check if postId not exists in posts
                    $check = $posts->firstWhere('id', $post->target_id);
                    if (!$check) {
                        $posts[$key] = $post->target;
                    } else {
                        $posts->forget($key);
                    }
                } else {
                    $posts[$key] = $post->target;
                }
            }

            return $posts;
        });
    }
}
