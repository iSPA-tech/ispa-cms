<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use Closure;
use JsonException;
use Cms\Core\Facades\CMSCache;
use App\Constants\Constant;
use App\Enums\StatusEnum;
use Illuminate\Http\Request;
use Cms\Core\Helpers\CacheHelper;
use Cms\Content\Models\Post;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Core\Models\Category;
use Illuminate\Cache\CacheManager;
use Cms\Core\Services\Cache\CategoryCacheInvalidationService;
use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Interfaces\CategoryInterface;
use Illuminate\Contracts\Foundation\Application;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Class CategoryRepository
 * Eloquent repository for Category with rich query helpers and cached public endpoints.
 *
 * @extends RepositoriesAbstract
 * @implements CategoryInterface
 * @see Category
 *
 * @method \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Builder|array|null
 *     find(int $id, array $select = ['*'], array $with = [])
 * @method mixed get(array $params)
 * @package Cms\Core\Repositories
 */
class CategoryRepository extends RepositoriesAbstract implements CategoryInterface
{
    public const PUBLIC_POST_CARD_COLUMNS = ['id', 'posts.category_id', 'name', 'description', 'published_at', 'video_url'];
    public const PUBLIC_POST_CARD_CACHE_VERSION = 'v3';

    /**
     * Initialize the repository and bind the Category model.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(Category::class);
    }

    private function versionedCacheKey(string $cacheKey, array|string $scopes): string
    {
        return app(CategoryCacheInvalidationService::class)->versionedKey($cacheKey, $scopes);
    }

    /**
     * Get category detail by UID
     *
     * @param string $uid
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-20, 16:44 ICT
     */
    /**
     * Resolve a published category id from its uid, with no relations.
     *
     * @param string $uid
     *
     * @return int|null
     */
    public function getIdByUid(string $uid): ?int
    {
        $cacheKey = $this->versionedCacheKey(
            sprintf(Constant::CACHE_CATEGORIES[Constant::CATEGORY_DETAIL_BY_ID], 'id', $uid),
            [CategoryCacheInvalidationService::categoryListScope()]
        );

        $id = cache()->remember($cacheKey, 3600, static fn (): int => (int) Category::query()
            //  No row is serialized here, so the appended-relation scope would
            //  eager load a slug and an image for a value that is discarded.
            ->withoutGlobalScope(Category::APPENDED_RELATIONS_SCOPE)
            ->where('uid', $uid)
            ->where('status', StatusEnum::PUBLISHED->value)
            ->value('id'));

        return $id > 0 ? $id : null;
    }

    public function getByUid(string $uid, bool $freshCache = false): mixed
    {
        $cacheKey = sprintf(Constant::CACHE_CATEGORIES[Constant::CATEGORY_DETAIL_BY_ID], 'null', $uid);
        $ttl = (int) config('setting.cache.cache.blog', 3600);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            CategoryCacheInvalidationService::categoryListScope(),
            CategoryCacheInvalidationService::categoryTreeScope(),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $uid) {
            CacheHelper::getInstance()->store($cacheKey, 'Category detail by uid ' . $uid, 3600);

            return $this->get([
                'where' => [
                    'uid' => $uid,
                    'status' => StatusEnum::PUBLISHED->value,
                ],
                'with' => [
                    'images',
                    'slugs:target_id,slug',
                    'seo:target_id,title,description',
                    'children' => function ($q) {
                        $q->select('id', 'name', 'parent_id', 'type', 'uid')
                            ->published()
                            ->with([
                                'slugs:target_id,slug',
                                'seo:target_id,title,description',
                            ]);
                    },
                ],
                'take' => 1,
            ]);
        });
    }

    /**
     * Get category tree by slug
     *
     * @param string $slug
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-01-08, 20:12 ICT
     */
    public function getTreeBySlug(string $slug, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $cacheKey = sprintf(Constant::CACHE_CATEGORIES[Constant::AlL_TREE_BY_SLUG], 0, $slug);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            CategoryCacheInvalidationService::categorySlugScope($slug),
            CategoryCacheInvalidationService::categoryTreeScope(),
            CategoryCacheInvalidationService::categoryListScope(0),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $slug) {
            CacheHelper::getInstance()->store($cacheKey, 'Category tree by slug ' . $slug, $ttl);

            return $this->get([
                'select' => [
                    'id', 'name', 'parent_id', 'status', 'featured', 'config',
                ],
                'where' => [
                    'status' => StatusEnum::PUBLISHED->value,
                ],
                'has' => [
                    'slugs' => function ($q) use ($slug) {
                        $q->where('slug', $slug);
                    },
                ],
                'with' => [
                    'images',
                    'slugs:target_id,slug',
                    'public_children' => function ($q) {
                        $q->select('parent_id', 'id', 'name')
                            ->orderBy('priority', 'asc')
                            ->with('slugs:target_id,slug')
                            ->withCount('public_children');
                    },
                ],
                'take' => 1,
            ]);
        });
    }

    /**
     * Get category by slug and type with post by limit
     * - because POST has type = 0 is default, we don't need to check type
     *
     * @param string $slug
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-12, 20:17 ICT
     */
    public function getCategoryBySlugWithPosts(string $slug, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $page = is_numeric(request('page')) ? max(1, (int) request('page')) : 1;
        $pageSize = is_numeric(request('limit')) ? max(1, (int) request('limit')) : 12;
        $cacheKey = sprintf(Constant::CACHE_CATEGORIES[Constant::CATEGORY_DETAIL_BY_ID], 0, $slug)
            . ':cards-' . self::PUBLIC_POST_CARD_CACHE_VERSION
            . ":page-{$page}:size-{$pageSize}";
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            CategoryCacheInvalidationService::categorySlugScope($slug),
            CategoryCacheInvalidationService::categoryTreeScope(),
            CategoryCacheInvalidationService::categoryListScope(0),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $slug) {
            CacheHelper::getInstance()->store($cacheKey, 'Category detail by type 0 and slug ' . $slug, $ttl);

            $category = $this->get([
                'select' => [
                    'id', 'name', 'parent_id', 'status', 'featured', 'config', 'status', 'type',
                ],
                'where' => [
                    'status' => StatusEnum::PUBLISHED->value,
                ],
                'has' => [
                    'slugs' => function ($q) use ($slug) {
                        $q->where('slug', $slug);
                    },
                ],
                'with' => [
                    'images',
                    'slugs:target_id,slug',
                    'public_children' => function ($q) {
                        $q->select('parent_id', 'id', 'name', 'status', 'type')->withCount('public_children');
                    },
                ],
                'take' => 1,
            ]);

            if (empty($category)) {
                return null;
            }

            $postLoader = static function ($q) {
                return $q->posts()->select(self::PUBLIC_POST_CARD_COLUMNS)->where([
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => lang('id', get_lang_id()),
                    'type' => 'post',
                ])->where(function ($query) {
                    $query->whereNull('published_at')
                        ->orWhere('published_at', '<=', now());
                })->with([
                    'slugs:target_id,slug',
                    'category:id,name',
                    'category.slugs:target_id,slug',
                ])->whereHas('slugs')
                    ->orderBy('published_at', 'DESC')->orderBy('id', 'desc')->paginate(request('limit', 12));
            };

            if (!empty($category->public_children) && count($category->public_children) > 0) {
                //  One batched traversal for the whole child row. Calling
                //  getChildrenIds() per child built a cache key and a
                //  breadth-first walk each.
                $idsByChild = $this->getChildrenIdsForMany(
                    collect($category->public_children)
                        ->filter(static fn ($child): bool => (bool) $child->has_children)
                        ->pluck('id')
                        ->filter()
                        ->map(static fn ($id): int => (int) $id)
                        ->all()
                );

                $children = [];
                foreach ($category->public_children as $child) {
                    if ($child->has_children) {
                        //  Get all child ids
                        $childIds = $idsByChild[(int) $child->id] ?? $this->getChildrenIds($child->id);
                        $childPosts = Post::query()->select(self::PUBLIC_POST_CARD_COLUMNS)->where([
                            'status' => StatusEnum::PUBLISHED->value,
                            'language_id' => lang('id', get_lang_id()),
                            'type' => 'post',
                        ])->where(function ($query) {
                            $query->whereNull('published_at')
                                ->orWhere('published_at', '<=', now());
                        })->with([
                            'slugs:target_id,slug',
                            'category:id,name',
                            'category.slugs:target_id,slug',
                        ])->whereHas('slugs')
                            ->whereHas('categories', function ($q) use ($childIds) {
                                $q->whereIn('category_id', $childIds);
                            })
                            ->orderBy('published_at', 'DESC')->orderBy('id', 'desc')
                            ->take(12)->get();
                    } else {
                        $childPosts = $postLoader($child);
                    }

                    $child->setAttribute('append_posts', $childPosts);
                    $children[] = $child;
                }
                $category->setAttribute('child', $children);
            } else {
                $categoryPosts = $postLoader($category);

                $category->setAttribute('append_posts', $categoryPosts);
            }

            return $category;
        });
    }

    /**
     * Get children ids
     *
     * @param int $id
     *
     * @return array
     * @author malayvuong
     * @since 6.62.6 - 2023-05-13, 14:38 ICT
     */
    public function getChildrenIds(int $id): array
    {
        return $this->getChildrenIdsForMany([$id])[$id] ?? [$id];
    }

    /**
     * Get children ids for many roots in a batched traversal.
     *
     * @param array<int, int|string> $ids
     * @return array<int, array<int, int>>
     */
    public function getChildrenIdsForMany(array $ids): array
    {
        $ids = collect($ids)
            ->filter(static fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $scopes = [CategoryCacheInvalidationService::categoryTreeScope()];
        foreach ($ids as $id) {
            $scopes[] = CategoryCacheInvalidationService::categoryScope($id);
            $scopes[] = CategoryCacheInvalidationService::categoryChildrenScope($id);
        }

        return CMSCache::rememberWithTracking(
            $this->versionedCacheKey(
                CacheKeyHelper::categoryChildren(0) . ':many:' . sha1(implode(',', $ids)),
                $scopes
            ),
            fn () => $this->loadChildrenIdsForMany($ids)
        );
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<int, int>>
     */
    private function loadChildrenIdsForMany(array $ids): array
    {
        $roots = [];
        $frontierByRoot = [];

        foreach ($ids as $id) {
            $roots[$id] = [$id => true];
            $frontierByRoot[$id] = [$id];
        }

        while ($frontierByRoot !== []) {
            $parentToRoots = [];
            foreach ($frontierByRoot as $rootId => $parentIds) {
                foreach ($parentIds as $parentId) {
                    $parentToRoots[$parentId][] = $rootId;
                }
            }

            $children = Category::query()
                //  Three columns for a graph walk — none of these rows is ever
                //  serialized, so the appended-relation scope would eager load
                //  a slug and an image per level for nobody to read. Measured
                //  at 2 of the 4 queries this traversal ran.
                ->withoutGlobalScope(Category::APPENDED_RELATIONS_SCOPE)
                ->select('id', 'parent_id', 'priority')
                ->where('status', StatusEnum::PUBLISHED->value)
                ->whereIn('parent_id', array_keys($parentToRoots))
                ->orderBy('priority', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($children->isEmpty()) {
                break;
            }

            $nextFrontierByRoot = [];
            foreach ($children as $child) {
                $childId = (int) $child->id;
                $parentId = (int) $child->parent_id;

                foreach ($parentToRoots[$parentId] ?? [] as $rootId) {
                    if (isset($roots[$rootId][$childId])) {
                        continue;
                    }

                    $roots[$rootId][$childId] = true;
                    $nextFrontierByRoot[$rootId][] = $childId;
                }
            }

            $frontierByRoot = $nextFrontierByRoot;
        }

        return collect($roots)
            ->map(static fn (array $idsByKey): array => array_map('intval', array_keys($idsByKey)))
            ->all();
    }

    /**
     * Get category by slug and type
     *
     * @param string $slug
     * @param int $type
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-07, 19:31 ICT
     */
    public function getCategoryBySlugAndType(string $slug, int $type = 0, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;

        $cacheKey = sprintf(Constant::CACHE_CATEGORIES[Constant::CATEGORY_DETAIL_BY_ID], $type, $slug);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            CategoryCacheInvalidationService::categorySlugScope($slug),
            CategoryCacheInvalidationService::categoryTreeScope(),
            CategoryCacheInvalidationService::categoryListScope($type),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $type, $slug) {
            CacheHelper::getInstance()->store($cacheKey, 'Category detail by slug and type ' . $type, $ttl);

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                ],
                'has' => [
                    'slugs' => function ($q) use ($slug) {
                        $q->where('slug', $slug);
                    },
                ],
                'with' => [
                    'images',
                    'slugs:target_id,slug',
                    'children' => fn($q) => $q->select('parent_id', 'name', 'id')->published(),
                ],
                'take' => 1,
            ]);
        });
    }

    /**
     * Get all public categories by type
     *
     * @param string $type
     * @param int $langId
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-03-06, 12:43 ICT
     */
    public function getAllPublicCategoriesByType(string $type, int $langId = 1): mixed
    {
        $typeInt = getCategoryTypeIdByString($type);

        return $this->get([
            'where' => [
                'type' => $typeInt,
                'status' => StatusEnum::PUBLISHED->value,
                'language_id' => $langId,
            ],
            'with' => [
                'slugs:target_id,slug',
                'language:id,name,path,flag',
            ],
            'order' => [
                'priority' => 'asc',
            ],
        ]);
    }

    /**
     * get featured categories
     *
     * @param int $type
     * @param int $articleLimit
     * @param bool $freshCache
     * @param int $noPosts
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-09-10, 15:53 ICT
     */
    public function getFeaturedCategoriesWithLimitedArticles(int $type = 0, int $articleLimit = 6, bool $freshCache = false, int $noPosts = 0): mixed
    {
        return CMSCache::rememberRandomWithTracking(
            $this->versionedCacheKey(CacheKeyHelper::featuredCategories($type), [
                CategoryCacheInvalidationService::categoryListScope($type),
                CategoryCacheInvalidationService::categoryTreeScope(),
            ]),
            function () use ($type, $articleLimit, $noPosts) {//  First get all featured categories
                $featuredCategories = $this->get([
                    'select' => ['id', 'name'],
                    'where' => [
                        'status' => StatusEnum::PUBLISHED->value,
                        'language_id' => lang('id', get_lang_id()),
                        'type' => $type,
                        'featured' => true,
                    ],
                    'has' => [
                        'slugs',
                    ],
                    'with' => [
                        'slugs:target_id,slug',
                        'public_children' => fn($q) => $q->select('parent_id', 'name', 'id'),
                    ],
                    'order' => [
                        'priority' => 'asc',
                    ],
                ]);

                if ($noPosts < 1) {
                    //  One batched traversal for every featured category.
                    //  getChildrenIds() is getChildrenIdsForMany() with a single
                    //  id, so calling it inside the loop built one cache key and
                    //  one breadth-first walk per category.
                    $idsByRoot = $this->getChildrenIdsForMany(
                        collect($featuredCategories)->pluck('id')->filter()->map(static fn ($id): int => (int) $id)->all()
                    );

                    foreach ($featuredCategories as $key => $category) {
                        $categoryIds = [$category->id, ...($idsByRoot[(int) $category->id] ?? $this->getChildrenIds($category->id))];
                        $posts = Post::query()->select('id', 'name', 'description')
                            ->where([
                                'status' => StatusEnum::PUBLISHED->value,
                                'language_id' => lang('id', get_lang_id()),
                                'type' => 'post',
                            ])
                            ->where(function ($q) {
                                $q->where('published_at', '<=', now())
                                    ->orWhereNull('published_at');
                            })
                            ->with([
                                'slugs:target_id,slug',
                                'images:id,target_id,path,name,mime_type,type,config',
                            ])
                            //  `image` and `total_view` are appended, and both
                            //  fall back to a query per row at serialization.
                            ->withImage()
                            ->withTotalView()
                            ->whereHas('slugs')
                            ->orderBy('published_at', 'desc')
                            ->orderBy('id', 'desc')
                            ->whereHas('categories', fn($q) => $q->whereIn('id', $categoryIds))
                            ->take($articleLimit)
                            ->get();

                        //  Replace with this posts
                        $featuredCategories[$key]['posts'] = $posts;
                    }
                }

                return $featuredCategories;
            });
    }

    /**
     * Get all featured category by type
     *
     * @param int $type
     * @param int $childLimit
     *
     * @return CacheManager|Application|mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-09-10, 14:00 ICT
     */
    public function getFeaturedCategories(int $type = 0, int $childLimit = 0): mixed
    {
        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_CATEGORIES,
            Constant::CACHE_KEY_CATEGORIES => 'featured',
            'category_type' => $type,
            'language_id' => lang('id', get_lang_id()),
        ]);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            CategoryCacheInvalidationService::categoryListScope($type),
            CategoryCacheInvalidationService::categoryTreeScope(),
        ]);

        return cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($cacheKey, $type, $childLimit) {

            return $this->get([
                'select' => ['id', 'name', 'uid'],
                'where' => [
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => lang('id', get_lang_id()),
                    'parent_id' => null,
                    'type' => $type,
                    'featured' => true,
                ],
                //  Both the roots and their children are serialized, and
                //  `has_children` is appended, so resolve it in the same query
                //  instead of one EXISTS per row.
                'when' => [
                    [true, static fn ($query) => $query->withChildrenExists()],
                ],
                'with' => [
                    'seo:target_id,title,description',
                    'public_children' => function ($q) use ($childLimit) {
                        $q->select('id', 'name', 'parent_id')->withChildrenExists()->with([
                            'slugs:target_id,slug',
                            'seo:target_id,title,description',
                        ])->where([
                            'status' => StatusEnum::PUBLISHED->value,
                            'language_id' => lang('id', get_lang_id()),
                        ])->take($childLimit > 0 ? $childLimit : 8)->orderBy('id', 'desc')->get();
                    },
                ],
                'order' => [
                    'priority' => 'asc',
                    'created_at' => 'desc',
                ],
            ]);
        });
    }

    /**
     * Get all categories with relationships (WEBSITE)
     *
     * @param array $condition
     *
     * @return array|LengthAwarePaginator|Collection
     * @throws JsonException
     * @since 6.47.5 - 2022-08-13, 02:14 ICT
     * @author malayvuong
     */
    public function getAllWithRelationship(array $condition = []): Collection|LengthAwarePaginator|array
    {
        $condition = array_merge([
            'status' => StatusEnum::PUBLISHED->value,
            'language_id' => lang('id', get_lang_id()),
            'parent_id' => null,
        ], $condition);

        //  Make cache key by make_key helper with key is condition
        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_CATEGORIES, Constant::CACHE_KEY_CATEGORIES => Constant::CACHE_KEY_DOCUMENTS,
            'language_id' => lang('id', get_lang_id()),
            'condition' => json_encode($condition, JSON_THROW_ON_ERROR),
        ]);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            CategoryCacheInvalidationService::categoryListScope($condition['type'] ?? null),
            CategoryCacheInvalidationService::categoryTreeScope(),
        ]);

        return cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($condition, $cacheKey) {
            CacheHelper::getInstance()->store($cacheKey, 'Category tree by all ', config('setting.cache.blog', 3600));

            return $this->get([
                'select' => [
                    'id', 'name', 'type', 'uid',
                ],
                'where' => $condition,
                'with' => [
                    'children' => function ($q) {
                        $q->select('id', 'name', 'parent_id', 'type', 'uid')
                            ->published()
                            ->with([
                                'children' => function ($q) {
                                    $q->select('id', 'name', 'parent_id', 'type', 'uid')
                                        ->published()
                                        ->with([
                                            'slugs:target_id,slug',
                                            'seo:target_id,title,description',
                                        ]);
                                },
                                'slugs:target_id,slug',
                                'seo:target_id,title,description',
                            ]);
                    },
                    'slugs:target_id,slug',
                    'seo:target_id,title,description',
                ],
            ]);
        });
    }

    /**
     * Document with relationship
     *
     * @param int $take
     *
     * @return Closure
     * @author malayvuong
     * @since 6.47.5 - 2022-08-11, 01:19 ICT
     */
    public function documentRelation(int $take = 6): Closure
    {
        return static function ($q) use ($take) {
            $q->select('id', 'path', 'name', 'name_alias', 'alt_text', 'file_size', 'type', 'mime_type', 'category', 'file_size', 'custom', 'config')
                ->with('content:id,target_id,name,description')
                ->whereIn('type', get_file_type_by_group('document'))
                ->where('category', 'files');
            //  CPL: check if $take > 0 then set q->take($take), else call q->paginate()
            if ($take > 1) {
                $q->take($take);
            } else {
                $q->paginate();
            }
        };
    }

    /**
     * Admin: Get list
     *
     * @param  Request $request
     * @return Collection|LengthAwarePaginator|array
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-19, 15:43 ICT
     */
    public function AdminIndex(Request $request): Collection|LengthAwarePaginator|array
    {
        return $this->get([
            'select' => [
                'id', 'parent_id', 'name', 'config', 'language_id', 'priority', 'featured', 'type', 'status', 'config',
            ],
            'with' => [
                'language:id,name,path,flag',
                'slugs:target_id,slug,category',
            ],
            'when' => [
                [
                    $request->get('query') !== null,
                    function ($q) use ($request) {
                        return $q->whereFulltext(['name', 'description', 'content', 'hashtag'], $request->get('query'));
                    },
                ],
                [
                    $request->input('type', 0) > -1,
                    function ($q) use ($request) {
                        return $q->where('type', $request->input('type'));
                    },
                ],
                [
                    !empty($request->input('typeStr')),
                    fn($q) => $q->where('type', getCategoryTypeIdByString($request->input('typeStr', 'article'))),
                ],
            ],
            'order' => [
                'parent_id' => 'asc',
                'priority' => 'asc',
            ],
        ]);
    }

    /**
     * Get categories list by type
     *
     * @param string $type
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-07, 19:31 ICT
     */
    public function getCategoriesByType(string $type = 'post'): mixed
    {
        $typeInt = getCategoryTypeIdByString($type);

        return CMSCache::rememberRandomWithTracking(
            $this->versionedCacheKey(CacheKeyHelper::categoriesByType($typeInt), [
                CategoryCacheInvalidationService::categoryListScope($typeInt),
                CategoryCacheInvalidationService::categoryTreeScope(),
            ]),
            function () use ($typeInt) {
                return $this->get([
                    'select' => [
                        'id', 'name', 'parent_id', 'status', 'featured', 'config',
                    ],
                    'where' => [
                        'status' => StatusEnum::PUBLISHED->value,
                        'language_id' => CacheKeyHelper::getLangId(),
                        'type' => $typeInt,
                    ],
                    'with' => [
                        'images',
                        'slugs:target_id,slug',
                        'children' => function ($q) {
                            $q->select('parent_id', 'id', 'name')
                                ->orderBy('priority', 'asc')
                                ->with('slugs:target_id,slug')
                                ->withCount('public_children');
                        },
                    ],
                    'order' => [
                        'priority' => 'asc',
                    ],
                ]);
            });
    }

    /**
     * Post loader
     *
     * @author malayvuong
     * @since 7.160.1175 - 2024-01-28, 14:43 ICT
     */
    protected function postLoader($q): Closure
    {
        return $q->posts()->select('id', 'name', 'description')->where([
            'status' => StatusEnum::PUBLISHED->value,
            'language_id' => lang('id', get_lang_id()),
            'type' => 'post',
        ])
            ->where(function ($q) {
                $q->where('published_at', '<=', now())
                    ->orWhereNull('published_at');
            })
            ->with([
                'slugs:target_id,slug',
            ])->whereHas('slugs')
            ->orderBy('published_at', 'DESC')->orderBy('id', 'desc')->paginate(request('limit', 12));
    }
}
