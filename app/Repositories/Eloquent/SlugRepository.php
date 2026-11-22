<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Eloquent;

use App\Models\Slug;
use App\Constants\Constant;
use App\Helpers\AppHelpers;
use Cms\Content\Models\Post;
use Cms\Core\Models\Category;
use Cms\Product\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Cms\Core\Repositories\Interfaces\SlugInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

class SlugRepository extends RepositoriesAbstract implements SlugInterface
{
    public function __construct()
    {
        $this->setModel(Slug::class);
    }

    /**
     * Get brand by slug
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-13, 20:53 ICT
     */
    public function getBrandBySlug(?string $slug, array $with = [], string $suffix = ''): mixed
    {

    }

    /**
     * Get product by slug
     *
     * @param string|null $slug
     * @param array $with
     * @param string $suffix
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-02-27, 00:58 ICT
     */
    public function getProductBySlug(?string $slug, array $with = [], string $suffix = ''): mixed
    {
        //  Make cache key
        $cacheKey = make_key(['type' => Constant::CACHE_KEY_SLUGS, 'slug' => $slug, 'language_id' => lang()->id]) . $suffix;
        //  Get category info by slug
        return cache()->remember($cacheKey, config('setting.cache.product', 3600), function () use ($slug, $cacheKey, $with) {

            return $this->findBy(
                [
                    'slug' => $slug,
                    'target_type' => Product::class,
                ],
                ['target_type', 'target_id'],
                [
                    'target' => function ($q) {
                        $q->select('id', 'name', 'content', 'published_at')->published()->with([
                            'slugs:target_id,slug',
                            'images:target_id,name,path,mime_type,file_size,category,disk',
                            'variants',
                        ]);
                    },
                ]
            );
        });
    }

    /**
     * glossary + posts paginate by character
     *
     * @param int $limit
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-10-08, 23:34 ICT
     */
    public function latestGlossary(int $limit = 4): mixed
    {
        $cacheKey = make_key([
            'type' => 'latest-glossary',
            'limit' => $limit,
            'language' => lang()->path,
        ]);

        return cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($cacheKey, $limit) {

            return $this->findBy([
                'slug' => lang()->path === 'en' ? 'glossary' : 'thuat-ngu',
                'language_id' => lang()->id,
                'target_type' => Category::class,
            ], [
                'target_type', 'target_id',
            ], [
                'target' => [
                    'slugs:target_id,slug',
                    'posts' => function ($query) use ($limit) {
                        $query
                            ->select('id', 'name', 'description')
                            ->published()
                            ->where('language_id', lang()->id)
                            ->with([
                                'slugs:target_id,slug',
                            ])
                            ->latest('id')
                            ->take($limit);
                    },
                ],
            ]);
        });
    }

    /**
     * glossary + posts paginate by character
     *
     * @param string $slug
     * @param string $character
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-10-08, 23:34 ICT
     */
    public function glossary(string $slug, string $character = 'a'): mixed
    {
        $cacheKey = make_key([
            'type' => 'glossary',
            'slug' => $slug,
            'character' => $character,
            'page' => request('page', 1),
            'limit' => request('limit', 20),
            'language' => lang()->path,
        ]);

        return cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($cacheKey, $slug, $character) {

            $category = $this->findBy([
                'slug' => $slug,
                'target_type' => Category::class,
            ],
                ['target_type', 'target_id'],
                [
                    'target' => [
                        'slugs:target_id,slug',
                    ],
                ]);
            if (!empty($category) && !empty($category->target)) {
                $posts = $category->target->posts()
                    ->select('id', 'name', 'description')
                    ->published()
                    ->where('language_id', lang()->id)
                    ->where('name', 'like', $character . '%')
                    ->with([
                        'slugs:target_id,slug',
                    ])
                    ->latest('id')
                    ->paginate(request('limit', 20));

//                dd($posts);

                $category->target->setAttribute('posts', $posts);
            }

            return $category;
        });
    }

    /**
     * Get category by slug
     *
     * @param string|null $slug
     * @param array $with
     * @param string $suffix
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 16:38 ICT
     */
    public function getCategoryBySlug(?string $slug, array $with = [], string $suffix = ''): mixed
    {
        //  Make cache key
        $cacheKey = make_key(['type' => Constant::CACHE_KEY_SLUGS, 'slug' => $slug, 'language_id' => lang()->id]) . $suffix;
        //  Get category info by slug
        return cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($slug, $cacheKey, $with) {

            return $this->findBy(
                [
                    'slug' => $slug,
                    //                'language_id' => lang()->id,
                    'target_type' => Category::class,
                ],
                ['target_type', 'target_id'],
                [
                    'target' => function ($q) use ($with) {
                        $q->select('id', 'name')->published()->with([
                            'slugs:target_id,slug',
                            ...AppHelpers::modelRelationshipHelper($with),
                            'children' => function ($q) use ($with) {
                                $q->select('id', 'name', 'parent_id')->published()->with([
                                    'slugs:target_id,slug',
                                    ...AppHelpers::modelRelationshipHelper($with),
                                    'children' => function ($q) use ($with) {
                                        $q->select('id', 'name', 'parent_id')->published()->with([
                                            'slugs:target_id,slug',
                                            ...AppHelpers::modelRelationshipHelper($with),
                                        ]);
                                    },
                                ]);
                            },
                        ]);
                    },
                ]
            );
        });
    }

    /**
     * Get post by slug
     *
     * @param string $slug
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 16:32 ICT
     */
    public function getPostBySlug(string $slug): mixed
    {
        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_POSTS,
            Constant::CACHE_KEY_POSTS => 'detail-by-slug',
            'slug' => sprintf(Constant::CACHE_KEY_DETAIL, Constant::CACHE_KEY_POSTS, $slug),
            'lang' => lang()->id,
        ]);

        return cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($slug, $cacheKey) {

            return $this->findBy(
                [
                    'slug' => $slug,
                    'target_type' => Post::class,
                ],
                ['target_type', 'target_id'],
                [
                    'target' => function ($q) {
                        $q->published()
//                            ->where(function ($q) {
//                                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
//                            })
                            ->select('id', 'name', 'description', 'content', 'hashtag', 'video_url', 'type', 'category_id', 'language_id', 'user_id', 'published_at', 'config')
                            ->with([
                                'slugs:target_id,slug',
                                'seo:target_id,title,description,hashtag,facebook,twitter',
                                'category' => function ($e) {
                                    $e->select('id', 'name', 'description')->with([
                                        'slugs:target_id,slug',
                                        'children:id,parent_id',
                                    ]);
                                },
                                'author' => function ($e) {
                                    $e->select('id', 'name')->with('avatar:target_id,name,path,mime_type,id,category');
                                },
                                'language:id,name,path',
                                'attachs:target_id,name,path,mime_type,id,category,custom',
                            ]);
                    },
                ]
            );
        });
    }

    /**
     * Get slug info by slug %slug% (Website)
     *
     * @param string $slug
     * @param string|null $target_type
     *
     * @return object|array|null
     * @author malayvuong
     * @since 6.62.6 - 2022-08-14, 10:38 ICT
     */
    public function getSameSlug(string $slug, string $target_type = null): object|array|null
    {
        //  Make cache key
        $cacheKey = make_key(['type' => Constant::CACHE_KEY_SLUGS, 'slug' => $slug, 'target_type' => $target_type]);

        return cache()->remember($cacheKey, 3600, function () use ($slug, $target_type, $cacheKey) {
            $where = [
                ['slug', 'like', '%' . $slug . '%'],
            ];
            //  CPL: check if $target_type exists then add where clause
            if ($target_type) {
                $where[] = ['target_type', '=', $target_type];
            }


            $target = $this->findBy($where, ['target_id', 'target_type'], [
                'target' => function ($q) use ($target_type) {
                    $q->select('id', 'name')->when(str($target_type)->containsAll(['Category', 'Post']), function ($q) {
                        $q->with([
                            'slugs:target_id,slug',
                            'seo:target_id,title,description',
                        ]);
                    });
                },
            ]);

            return $target ?? [];
        });
    }

    /**
     * Create or Update slug
     *
     * @param
     * @param string|null $slug
     * @param int|null $language_id
     * @param string|null $category
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-25, 11:6
     */
    public function storeSlug($model, ?string $slug = null, ?int $language_id = null, ?string $category = null): mixed
    {
        $languageId = $language_id > 0 ? $language_id : null;
        $targetType = get_class($model);
        $targetId = $model->id ?? 0;

        $payload = [
            'language_id' => $languageId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'category' => $category,
            'slug' => $slug,
        ];

        $existing = $this->model()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('language_id', $languageId)
            ->where('category', $category)
            ->first();

        if (!$existing && !empty($slug)) {
            $existing = $this->model()
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->where('slug', $slug)
                ->first();
        }

        if (!$existing) {
            $existing = $this->model()
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->latest('id')
                ->first();
        }

        if ($existing) {
            $existing->fill($payload);
            $existing->save();

            return $existing;
        }

        return $this->create($payload);
    }
}
