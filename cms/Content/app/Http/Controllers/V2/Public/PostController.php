<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Public;

use Cms\Core\Responses\AppResponse;

use App\Enums\StatusEnum;
use Cms\Content\Models\Post;
use Cms\Core\Models\Category;
use App\Constants\RedisConstants;
use App\Http\Controllers\Controller;
use Cms\Core\Helpers\RedisHelper;
use Cms\Content\Interfaces\PostInterface;
use Cms\Core\Http\Resources\Website\PublicPostResource;
use Cms\Core\Http\Resources\Website\PublicPostCollection;
use Cms\Core\Http\Resources\Website\PublicCategoryResource;
use Cms\Core\Repositories\Interfaces\SlugInterface;

class PostController extends Controller
{
    /**
     * PostController constructor
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-15, 21:52 ICT
     */
    public function __construct(
        protected PostInterface $post,
        protected SlugInterface $slug,
    )
    {
        parent::__construct();
    }

    /**
     * Get post list
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-15, 21:52 ICT
     */
    public function index(): mixed
    {
        $posts = $this->post->get([
            'select' => ['id', 'uuid', 'name', 'description', 'category_id', 'published_at'],
            'where' => [
                'status' => StatusEnum::PUBLISHED->value,
                function ($query) {
                    $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                },
            ],
            'page' => request('limit', 10),
            'with' => [
                'category.slugs',
                'language',
                'slugs',
            ],
            //  The collection prints a cover, and `image` is appended, so the
            //  accessor asks the R2 ledger whether each preset derivative was
            //  replicated — 40 of this endpoint's 54 production queries were
            //  that one EXISTS per image path. withImage() answers the batch.
            'scopes' => ['withImage'],
            'order' => [
                'published_at' => 'desc',
            ],
        ]);

        $posts = new PublicPostCollection($posts);

        RedisHelper::set([RedisConstants::POSTS, RedisConstants::PUBLIC_POSTS, lang('path')], $posts->toArray(request()));

        return ires()->data($posts);
    }

    /**
     * Show article detail
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-03-17, 15:34 ICT
     */
    public function show(string $categorySlug, string $postSlug = null)
    {
        //  Make cache key
        $cacheKey = [
            RedisConstants::POSTS,
        ];
        if (empty($postSlug)) {
            $cacheKey[] = $categorySlug;
        } else {
            $cacheKey[] = sprintf(RedisConstants::POST_DETAIL, $postSlug);
        }
        $cacheKey[] = lang('path');

        //  Process to get data by slug or category slug
        if (empty($postSlug)) {
            $where = [
                'slug' => $categorySlug,
                'target_type' => Category::class,
            ];
            $with = [
                'target' => fn($query) => $query->published()->with([
                    'language:id,name,path,flag',
                    'slugs:target_id,slug',
                    'seo:target_id,title,description,hashtag,facebook,twitter,custom,config',
                ]),
            ];
            //  Add more detail into cache key
            if (request()->has('page') || request()->has('limit') || request()->has('keywords')) {
                $cacheKey[] = request('page', 1);
                $cacheKey[] = request('limit', 10);
                $cacheKey[] = request('keywords', '');
            }
        } else {
            $where = [
                'slug' => $postSlug,
                'target_type' => Post::class,
            ];
            $with = [
                'target' => fn($query) => $query->published()->with([
                    'language:id,name,path,flag',
                    'slugs:target_id,slug',
                    'seo:target_id,title,description,hashtag,facebook,twitter,custom,config',
                    'category' => fn($query) => $query->with([
                        'slugs:target_id,slug',
                    ])->select('id', 'name'),
                ]),
            ];
        }

        $slug = $this->slug->get([
            'where' => $where,
            'take' => 1,
            'order' => [
                'id' => 'desc',
            ],
            'when' => [
                [
                    'language_id IS NOT NULL',
                    fn($query) => $query->where('language_id', lang('id')),
                ],
            ],
            'with' => $with,
        ]);

        if (empty($slug)) {
            return (new AppResponse())->message(__('mgs.url.404'));
        }

        if (empty($postSlug)) {
            $data = new PublicCategoryResource($slug->target);
        } else {
            $data = new PublicPostResource($slug->target);
        }

        RedisHelper::set($cacheKey, $data->toJson());

        return (new AppResponse())->message('ok');
    }
}
