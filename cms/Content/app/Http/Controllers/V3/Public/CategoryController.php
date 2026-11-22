<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V3\Public;

use App\Http\Controllers\V3\Public\Concerns\DecoratesPostPreviewImages;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Models\Upload;
use Cms\Core\Services\ImageVariantService;

use JsonException;
use App\Enums\StatusEnum;
use Illuminate\Http\Request;
use Cms\Core\Models\Category;
use App\Constants\CacheConstants;
use App\Http\Controllers\Controller;
use Cms\Core\Helpers\RedisHelper;
use Cms\Content\Interfaces\PostInterface;
use Cms\Core\Interfaces\CategoryInterface;
use Cms\Core\Http\Requests\V3\GetFeaturedCategoriesRequest;
use Illuminate\Support\Collection;
use Throwable;

class CategoryController extends Controller
{
    use \App\Http\Controllers\V3\Public\Concerns\SerializesPublicPayloadOnce;

    use DecoratesPostPreviewImages;

    /**
     * Category controller constructor
     *
     * @param CategoryInterface $category
     * @param PostInterface $post
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-20, 16:22 ICT
     */
    public function __construct(
        protected CategoryInterface $category,
        protected PostInterface     $post,
    )
    {
        parent::__construct();
    }

    /**
     * Get all Categories
     *
     *
     * @return mixed
     * @throws JsonException
     * @author malayvuong
     * @since 7.160.1175 - 2024-05-04, 09:58 ICT
     */
    public function getAll(): mixed
    {
        $allCategories = $this->category->get([
            'select' => ['id', 'name', 'parent_id', 'type', 'status'],
            'where' => [
                'status' => StatusEnum::PUBLISHED->value,
            ],
            'with' => [
                'slugs:slug,target_id',
            ],
            //  `has_children` is appended, and this endpoint returns the whole
            //  tree, so without the inline projection the accessor runs one
            //  EXISTS per category — measured at ~105 on a small install.
            'when' => [
                [true, static fn ($query) => $query->withChildrenExists()],
            ],
        ]);

        //  Store to Redis
        $payload = $this->serializeOnce($allCategories);
        $this->cachePublicPayload(CacheConstants::CACHE_CATEGORY_ALL, $payload, 86400);

        return ires()->data($payload);
    }

    /**
     * Get featured category & all child, then get latest post of all categories ids
     *
     * @param GetFeaturedCategoriesRequest $request
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2024-05-03, 08:59 ICT
     * @author malayvuong
     */
    public function featuredCategories(GetFeaturedCategoriesRequest $request)
    {
        //  Get featured categories
        $categories = collect($this->category->getFeaturedCategories(
            getCategoryTypeIdByString($request->input('type', 'article')),
            $request->input('childLimit', 0)
        ) ?: []);

        $categoryIdsByRoot = $this->category->getChildrenIdsForMany(
            $categories
                ->map(fn (mixed $category): mixed => $this->categoryId($category))
                ->filter()
                ->map(static fn ($categoryId): int => (int) $categoryId)
                ->unique()
                ->values()
                ->all()
        );

        //  Get all post by category tree
        //  Set post into category
        $categories = $categories->map(function (mixed $category) use ($request, $categoryIdsByRoot) {
            $categoryId = $this->categoryId($category);

            if (!$categoryId) {
                return $category;
            }

            $categoryTreeIds = $categoryIdsByRoot[(int) $categoryId]
                ?? $this->category->getChildrenIds((int) $categoryId);

            $posts = $this->post->getPostsByCategoriesIds(
                $categoryTreeIds,
                (int)$request->input('page', 1),
                (int)$request->input('limit', 12),
                (string)$request->input('type', 'post')
            );
            $posts = $this->decoratePostPreviewImages($posts);

            if (is_array($category)) {
                $category['posts'] = $posts;
            } else {
                $category->posts = $posts;
            }

            return $category;
        });

        //  Store to Redis cache
        $cacheKey = sprintf(CacheConstants::CACHE_FEATURED_CATEGORIES, http_build_query($request->all()) ?? '');
        $payload = $this->serializeOnce($categories);
        $this->cachePublicPayload($cacheKey, $payload, 3600);

        return ires()->data($payload);
    }

    /**
     * Get post list by category UID
     *
     * @param string $uid
     * @param Request $request
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-21, 09:16 ICT
     */
    public function post(string $uid, Request $request): mixed
    {
        //  Only the key is needed here. getByUid() hydrates the row plus its
        //  images, slugs, seo and children, and the appended-relation scope
        //  adds a slug and an image load per level on top — 11 queries for a
        //  model this method reads one integer out of.
        $categoryId = $this->category->getIdByUid($uid);

        if (!$categoryId) {
            return ires()->error()->message(__('mgs.category.not_found'));
        }

        $categoryIds = $this->category->getChildrenIds($categoryId);
        //  Set filter params
        $filter = $request->all();
        $filter['category_ids'] = $categoryIds;

        //  Get post list by category UID
        $posts = $this->post->advancedSearch($filter);
        return ires()->data($this->decoratePostPreviewImages($posts));
    }

    /**
     * Show detail of category by UID
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-20, 16:42 ICT
     */
    public function show(string $uid): mixed
    {
        //  Get category by UID
        $category = $this->category->getByUid($uid);

        if (!$category) {
            return ires()->error()->message(__('mgs.category.not_found'));
        }

        return ires()->data($category);
    }

    /**
     * Get category list
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-20, 15:37 ICT
     */
    public function index(): mixed
    {
        //  Get post type by string
        $typeId = getCategoryTypeIdByString(request('type', 'article'));

        //  Get category list
        $categories = $this->category->getAllWithRelationship([
            'type' => $typeId,
        ]);

        return ires()->data($categories);
    }

    /**
     * Show Post detail by slug
     *
     * @param string $slug
     * @param Request $request
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2024-04-30 13:55 ICT
     * @author malayvuong
     */
    public function treeWithPost(string $slug, Request $request): mixed
    {
        if (!$slug) {
            return ires()->error()->message(__('mgs.category.not_found'));
        }
        //  Get category by UID
        $category = $this->findPublishedCategoryTreeBySlug($slug);

        if (!$category) {
            return ires()->error()->message(__('mgs.category.not_found'));
        }

        //  If this category haven't any children => get all post by category id
        if (!$category->has_children) {
            //  Get all post by category tree
            $posts = $this->post->getPostsByCategoriesIds(
                [$category->id],
                $request->input('page', 1),
                $request->input('pageSize', 15),
                $request->input('type', 'post')
            );

            $category->posts = $this->decoratePostPreviewImages($posts);
        } //  else get posts by each category & its children
        else if ($category->has_children) {
            $childCategoryIds = collect($category->public_children ?? [])
                ->pluck('id')
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $childCategoryIdsByRoot = $this->category->getChildrenIdsForMany($childCategoryIds);

            $category->public_children->map(function ($child) use ($request, $childCategoryIdsByRoot) {
                $categoryIds = $childCategoryIdsByRoot[(int) $child->id]
                    ?? $this->category->getChildrenIds((int) $child->id);
                //  Get all post by category tree
                $posts = $this->post->getPostsByCategoriesIds(
                    $categoryIds,
                    $request->input('page', 1),
                    $request->input('pageSize', 6),
                    $request->input('type', 'post')
                );

                $child->posts = $this->decoratePostPreviewImages($posts);

                return $child;
            });
        }

        //  Store cache for a day
        $cacheKey = sprintf(CacheConstants::CACHE_CATEGORY_BY_SLUG, $slug, http_build_query($request->all()) ?? '');
        $payload = $this->serializeOnce($category);
        $this->cachePublicPayload($cacheKey, $payload, 86400);

        return ires()->data($payload);
    }

    /**
     * get all posts in category tree
     *
     * @param string $slug
     * @param Request $request
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2024-05-01, 10:51 ICT
     * @author malayvuong
     */
    public function allPostsOfTree(string $slug, Request $request): mixed
    {
        //  Get category by slug
        $category = $this->category->getCategoryBySlugAndType($slug, getCategoryTypeIdByString($request->input('type', 'article')));

        if (!$category) {
            return ires()->error()->message(__('mgs.category.not_found'));
        }

        //  Get Ids of category tree
        $categoryIds = $this->category->getChildrenIds($category->id);

        //  Get all post by category tree
        $posts = $this->post->getPostsByCategoriesIds($categoryIds, $request->input('page', 1), $request->input('limit', 12), $request->input('type', 'post'));

        //  Store to Redis cache
        $cacheKey = sprintf(CacheConstants::CACHE_ALL_POSTS_OF_CATEGORY_TREE, $slug, http_build_query($request->all()) ?? '');
        $payload = $this->serializeOnce($posts);
        $this->cachePublicPayload($cacheKey, $payload, 86400);

        return ires()->data($payload);
    }

    protected function categoryId(mixed $category): mixed
    {
        return is_array($category)
            ? ($category['id'] ?? null)
            : (is_object($category) ? ($category->id ?? null) : null);
    }

    /**
     * Load the published category tree used by category detail pages.
     */
    protected function findPublishedCategoryTreeBySlug(string $slug): mixed
    {
        return Category::query()->whereHas('slugs', fn($q) => $q->where('slug', $slug))
            ->select('id', 'name', 'type', 'parent_id')
            ->where([
                'status' => StatusEnum::PUBLISHED->value,
            ])
            ->with([
                'parent' => fn($q) => $q->select('id', 'name')->with('slugs:slug,target_id'),
                'images',
                'slugs:slug,target_id',
                'seos:target_id,title,description,hashtag,language_id',
                //  Every node of the tree is serialized, and `has_children` is
                //  appended, so resolve it in the same query — the accessor
                //  otherwise runs one EXISTS per node.
                'public_children' => fn($q) => $q
                    ->select('id', 'name', 'parent_id')
                    ->withChildrenExists()
                    ->with([
                        'slugs:slug,target_id',
                        'images',
                    ]),
            ])->withChildrenExists()->first();
    }

    /**
     * Cache writes are opportunistic for public V3 category endpoints.
     */
    protected function cachePublicPayload(string $cacheKey, mixed $payload, ?int $ttl = null, array $debugContext = []): void
    {
        try {
            RedisHelper::getInstance()->set($cacheKey, $payload, $ttl);
        } catch (Throwable $exception) {
            logger()->warning('Skip public V3 category cache write to avoid 500 from cache layer', [
                'cache_key' => $cacheKey,
                'context' => $debugContext,
                'error' => $exception->getMessage(),
            ]);
        }
    }

}
