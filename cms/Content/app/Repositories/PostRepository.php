<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Repositories;

use Throwable;
use Carbon\CarbonInterface;
use Cms\Content\Services\Agentic\AgentAnswerSearchText;
use Cms\Content\Services\Agentic\AgentReadablePostProjector;
use JsonException;
use App\Constants\Constant;
use App\Enums\StatusEnum;
use Cms\Content\Models\Post;
use Cms\Core\Facades\CMSCache;
use Cms\Core\Helpers\CacheHelper;
use App\Constants\CacheConstants;
use Cms\Core\Helpers\CacheKeyHelper;
use Cms\Content\Interfaces\PostInterface;
use Illuminate\Database\Eloquent\Collection;
use Cms\Content\Services\Cache\ContentPostCacheInvalidationService;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent repository for blog posts and articles with heavy cache usage.
 *
 * Implements {@see PostInterface} for {@see Post} persistence.
 * Provides cache-backed queries for listing, search, detail, category,
 * glossary, featured, and related post retrieval via CMSCache.
 *
 * @see Post
 * @see PostInterface
 * @package Cms\Content\Repositories
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostRepository extends RepositoriesAbstract implements PostInterface
{
    /**
     * Initialize the repository and bind the Post model.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(Post::class);
    }

    public function get(array $params): mixed
    {
        if (($params['latest'] ?? false) === true) {
            $order = $params['order'] ?? [];
            unset($order['published_at'], $order['created_at']);

            $params['latest'] = false;
            $params['order'] = [
                'published_at' => 'desc',
                'created_at' => 'desc',
                ...$order,
            ];
        }

        return parent::get($params);
    }

    private function versionedCacheKey(string $cacheKey, array|string $scopes): string
    {
        return app(ContentPostCacheInvalidationService::class)->versionedKey($cacheKey, $scopes);
    }

    /**
     * Get latest post by category slug
     *
     * @param string $slug
     * @param int $limit
     * @param bool $freshCache
     * @param array $optional
     *
     * @return mixed
     * @throws Throwable
     * @since 7.160.1175 - 2025-01-11, 20:26 ICT
     * @author malayvuong
     */
    public function getLatestPostByCategorySlugAndType(string $slug, int $limit = 6, bool $freshCache = false, array $optional = []): mixed
    {
        $ttl = (int) config('setting.cache.cache.blog', 3600);

        $languageId = lang('id', get_lang_id());

        $cacheKey = sprintf(
            '%s:lang:%s',
            sprintf(CacheConstants::CACHE_LATEST_POSTS_BY_CATEGORY_SLUG, $slug, $limit),
            $languageId
        );
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::latestScope('post', $languageId),
            ContentPostCacheInvalidationService::listScope('post', $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($slug, $limit, $ttl, $optional, $cacheKey, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get latest post by category slug: ' . $slug . ' and limit: ' . $limit, $ttl);

            return $this->get([
                'where' => [
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                    function ($query) use ($slug) {
                        return $query->whereHas('categories', function ($query) use ($slug) {
                            return $query->whereHas('slugs', function ($query) use ($slug) {
                                return $query->where('slug', $slug);
                            });
                        });
                    },
                    ...$optional,
                ],
                'with' => [
                    'slugs:target_id,slug',
                    'images',
                    'category' => function ($a) {
                        $a->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug');
                    },
                ],
                'order' => [
                    'published_at' => 'desc',
                    'created_at' => 'desc',
                ],
                'take' => $limit,
            ]);
        });
    }

    /**
     * Get post by type and category slug
     *
     * @param string $type
     * @param string|null $categorySlug
     * @param int $pageSize
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-09-07, 10:33 ICT
     */
    public function getPostListByTypeAndCategorySlug(string $type = 'post', string $categorySlug = null, int $pageSize = 10, bool $freshCache = false): mixed
    {
        $languageId = lang('id', get_lang_id());
        $cacheKey = sprintf(
            '%s:lang:%s',
            sprintf(CacheConstants::CACHE_POSTS_BY_CATEGORY_SLUG, $type, $categorySlug, $pageSize, request('page', 1)),
            $languageId
        );
        $ttl = (int) config('setting.cache.cache.blog', 3600);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::listScope($type, $languageId),
            ContentPostCacheInvalidationService::latestScope($type, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $type, $categorySlug, $pageSize, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get post by type: ' . $type . ' and category slug: ' . $categorySlug, $ttl);

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                    function ($query) use ($categorySlug) {
                        return $query->whereHas('categories', function ($query) use ($categorySlug) {
                            return $query->whereHas('slugs', function ($query) use ($categorySlug) {
                                return $query->where('slug', $categorySlug);
                            });
                        });
                    },
                ],
                'with' => [
                    'slugs:target_id,slug',
                    'images',
                    'category' => function ($a) {
                        $a->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug');
                    },
                ],
                'order' => [
                    'published_at' => 'asc',
                ],
                'page' => $pageSize,
            ]);
        });
    }

    /**
     * Get post by type and date range
     *
     * @param string $type
     * @param string|null $startDate
     * @param string|null $endDate
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-09-07, 10:33 ICT
     */
    public function getPostByTypeAndDateRange(string $type = 'post', string $startDate = null, string $endDate = null, bool $freshCache = false): mixed
    {
        $ttl = (int) config('setting.cache.cache.blog', 3600);

        $languageId = lang('id', get_lang_id());

        $cacheKey = sprintf(
            '%s:lang:%s',
            sprintf(CacheConstants::CACHE_POSTS_BY_DATE_RANGE, $type, $startDate, $endDate),
            $languageId
        );
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::listScope($type, $languageId),
            ContentPostCacheInvalidationService::latestScope($type, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $type, $startDate, $endDate, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get post by type: ' . $type . ' and date range: ' . $startDate . ' - ' . $endDate, $ttl);

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                    function ($query) use ($startDate, $endDate) {
                        return $query->whereBetween('published_at', [$startDate, $endDate]);
                    },
                ],
                'with' => [
                    'slugs:target_id,slug',
                    'images',
                    'category' => function ($a) {
                        $a->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug');
                    },
                ],
                'order' => [
                    'published_at' => 'asc',
                ],
            ]);
        });
    }

    /**
     * Get Latest pots by limit
     *
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @throws Throwable
     * @since 7.160.1175 - 2024-05-03, 11:16 ICT
     * @author malayvuong
     */
    public function getLatestPosts(int $limit = 5, bool $freshCache = false): mixed
    {
        $ttl = (int) config('setting.cache.cache.blog', 3600);

        $languageId = lang('id', get_lang_id());
        $cacheKey = sprintf(
            '%s:lang:%s',
            sprintf(CacheConstants::CACHE_LATEST_POSTS, $limit),
            $languageId
        );
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::latestScope('post', $languageId),
            ContentPostCacheInvalidationService::listScope('post', $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $limit, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get latest posts by limit: ' . $limit, $ttl);

            return $this->get([
                'select' => ['id', 'name', 'description', 'type', 'status', 'category_id', 'video_url', 'published_at'],
                'where' => [
                    'type' => 'post',
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                ],
                'order' => [
                    'published_at' => 'desc',
                    'created_at' => 'desc',
                ],
                'take' => $limit,
                'with' => [
                    'images',
                    'slugs:target_id,slug',
                    'category' => function ($a) {
                        $a->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug');
                    },
                ],
                'has' => [
                    'category',
                ],
                //  Batch the two per-row probes that fire during serialization:
                //  `image` costs one R2 ledger EXISTS per image path (28 of
                //  post/latest's 29 production queries) and `total_view` one
                //  SUM over `tracks` per row.
                'scopes' => ['withImage', 'withTotalView'],
            ]);
        });
    }

    /**
     * Get posts by type and config key in column `config`
     *
     * @param string $type
     * @param string $custom
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-05-03, 10:35 ICT
     */
    public function getPostByTypeAndConfig(string $type = 'post', string $custom = 'featured', int $limit = 5, bool $freshCache = false): mixed
    {
        $ttl = (int) config('setting.cache.cache.blog', 3600);

        $languageId = lang('id', get_lang_id());

        $cacheKey = sprintf(
            '%s:lang:%s',
            sprintf(CacheConstants::CACHE_POSTS_BY_CONFIG, $type, $custom, $limit),
            $languageId
        );
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::configScope($type, $custom, $languageId),
            ContentPostCacheInvalidationService::listScope($type, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $type, $custom, $limit, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get posts by type: ' . $type . ' and config key: ' . $custom . ' and limit: ' . $limit, $ttl);

            return $this->get([
                'select' => ['id', 'name', 'description', 'type', 'status', 'category_id', 'video_url', 'published_at'],
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    'config->' . $custom => true,
                    'language_id' => $languageId,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                ],
                'order' => [
                    'published_at' => 'desc',
                    'created_at' => 'desc',
                ],
                'take' => $limit,
                'with' => [
                    'images',
                    'slugs:target_id,slug',
                    'category' => function ($a) {
                        $a->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug');
                    },
                ],
            ]);
        });
    }

    /**
     * Get featured posts
     *
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-05-03, 10:35 ICT
     */
    public function getFeaturedPosts(int $limit = 5, bool $freshCache = false): mixed
    {
        $languageId = CacheKeyHelper::getLangId();

        return CMSCache::rememberRandomWithTracking(
            $this->versionedCacheKey(CacheKeyHelper::featuredPosts($limit), [
                ContentPostCacheInvalidationService::featuredScope('post', $languageId),
                ContentPostCacheInvalidationService::configScope('post', 'featured', $languageId),
            ]),
            function () use ($limit, $languageId) {
                return $this->get([
                    'select' => [
                        'id', 'name', 'description', 'type', 'status', 'category_id', 'video_url', 'published_at',
                    ],
                    'with' => [
                        'images',
                        'slugs:target_id,slug',
                        'category' => function ($a) {
                            $a->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug');
                        },
                    ],
                    'where' => [
                        'type' => 'post',
                        'status' => StatusEnum::PUBLISHED->value,
                        'config->featured' => true,
                        'language_id' => $languageId,
                        function ($query) {
                            return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                        },
                    ],
                    'order' => [
                        'published_at' => 'desc',
                        'created_at' => 'desc',
                    ],
                    'take' => $limit,
                    'has' => ['categories'],
                ]);
            });
    }

    /**
     * Get Post detail by UUID
     *
     * @param string $uuid
     * @param bool $freshCache
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-21, 10:37 ICT
     */
    public function getPostDetailByUuid(string $uuid, bool $freshCache = false): mixed
    {
        $languageId = lang('id', get_lang_id());
        $cacheKey = sprintf(
            '%s:lang:%s',
            sprintf(Constant::CACHE_POSTS[Constant::POST_DETAIL_BY_ID], 'post', $uuid),
            $languageId
        );
        $ttl = (int) config('setting.cache.cache.blog', 3600);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::uuidScope($uuid),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($uuid, $ttl, $cacheKey, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get post detail by UUID: ' . $uuid, $ttl);

            return $this->model()->where([
                'uuid' => $uuid,
                'status' => StatusEnum::PUBLISHED->value,
                'language_id' => $languageId,
            ])->where(function ($query) {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            })->with([
                'slugs',
                'seos',
                'categories' => function ($query) {
                    return $query->whereHas('slugs')->published();
                },
                'attachments',
                'images',
            ])->first();
        });
    }

    /**
     * Advanced search by filter condition
     *
     * @param array $filter
     * @param bool $freshCache
     *
     * @return mixed
     * @throws JsonException
     * @since 7.160.1175 - 2024-04-21, 09:58 ICT
     * @author malayvuong
     */
    public function advancedSearch(array $filter = [], bool $freshCache = false): mixed
    {
        $languageId = lang('id', get_lang_id());
        $filterJson = json_encode($filter, JSON_THROW_ON_ERROR);
        $cacheKey = sprintf(Constant::CACHE_POSTS[Constant::ADVANCED_SEARCH], $languageId, $filterJson);
        $ttl = (int) config('setting.cache.cache.blog', 3600);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::searchScope((string) ($filter['type'] ?? 'post'), $languageId),
            ContentPostCacheInvalidationService::listScope((string) ($filter['type'] ?? 'post'), $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $filter, $filterJson, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Advanced search by filter: ' . $filterJson, $ttl);

            $keyword = trim((string) ($filter['keyword'] ?? ''));

            $page = $filter['page'] ?? 1;
            $pageSize = $filter['pageSize'] ?? 10;

            return $this->model()
                ->select(['id', 'name', 'uuid', 'description', 'type', 'category_id', 'user_id', 'published_at', 'status'])
                ->where([
                    'type' => $filter['type'] ?? 'post',
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                ])
                ->where(function ($query) {
                    $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                ->with([
                    'slugs:target_id,slug',
                    'seos:target_id,title,description,hashtag',
                    //  `has_children` is appended on Category, so without the
                    //  projection the accessor runs one EXISTS per category
                    //  row — 19 of the 60 queries behind category/{uid}/post.
                    'categories' => fn($query) => $query->with([
                        'slugs',
                    ])->select('id', 'name', 'uid', 'parent_id', 'type', 'status', 'config')
                        ->withChildrenExists()
                        ->published(),
                    'attachments',
                    'images',
                ])
                //  Both appended attributes fall back to a query per row while
                //  the response serializes: `image` probes the R2 ledger once
                //  per preset derivative, `total_view` sums `tracks`. Measured
                //  at 8 and 10 of those 60 queries.
                ->withImage()
                ->withTotalView()
                ->when(!empty($filter['type']), fn($q) => $q->where('type', $filter['type']))
                ->when($keyword !== '', function ($q) use ($keyword) {
                    // Tiered relevance search with wildcard escaping and
                    // Vietnamese diacritic folding (Core standard builder).
                    app(\Cms\Core\Services\Search\OrderedKeywordSearch::class)->apply(
                        $q,
                        $keyword,
                        ['posts.name'],
                        static fn ($query) => $query->orderByDesc('posts.published_at'),
                        'posts.id',
                        'desc',
                    );
                })
                ->when(!empty($filter['category']), fn($q) => $q->whereHas('categories', fn($q) => $q->where('id', $filter['category'])))
                ->when(!empty($filter['category_ids']) && is_array($filter['category_ids']), fn($q) => $q->whereHas('categories', fn($q) => $q->whereIn('id', $filter['category_ids'])))
                ->when(!empty($filter['tag']), fn($q) => $q->whereHas('tags', fn($q) => $q->where('slug', $filter['tag'])))
                ->paginate($pageSize, ['*'], 'page', $page);
        });
    }

    /**
     * Get post list by type and page
     *
     * @param string $type
     * @param int $pageSize
     * @param int $page
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-18, 11:10 ICT
     */
    public function getPostListByType(string $type = 'post', int $pageSize = 12, int $page = 1, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $languageId = lang('id', get_lang_id());
        $cacheKey = sprintf(Constant::CACHE_POSTS[Constant::CACHE_BY_POSTS], $languageId, $type, $pageSize, $page);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::listScope($type, $languageId),
            ContentPostCacheInvalidationService::latestScope($type, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($ttl, $cacheKey, $type, $page, $pageSize, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get post list by type: ' . $type . ' and page: ' . $page . ' and page size: ' . $pageSize, $ttl);

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                    'language_id' => $languageId,
                ],
                'with' => [
                    'slugs:target_id,slug',
                    'images',
                    'category' => fn($query) => $query->select('id', 'name')
                        ->withChildrenExists()
                        ->with('slugs:target_id,slug'),
                ],
                'order' => [
                    'published_at' => 'desc',
                    'created_at' => 'desc',
                ],
                'page' => $pageSize,
                'current_page' => $page,
            ]);
        });
    }

    /**
     * Get latest post required login
     *
     * @param string $type
     * @param int $pageSize
     * @param int $page
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-23, 19:56 ICT
     */
    public function getLatestPostRequiredLoginByType(string $type = 'post', int $pageSize = 12, int $page = 1, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;

        $languageId = lang('id', get_lang_id());
        $cacheKey = sprintf(Constant::CACHE_POSTS[Constant::POSTS_BY_REQUIRED_LOGIN], $languageId, $type, $pageSize, $page);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::listScope($type, $languageId),
            ContentPostCacheInvalidationService::latestScope($type, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $type, $pageSize, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get latest post required login by type: ' . $type . ' and limit: ' . $pageSize, $ttl);

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    'config->requiredLogin' => true,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                    'language_id' => $languageId,
                ],
                'order' => [
                    'published_at' => 'desc',
                    'created_at' => 'desc',
                ],
                'page' => $pageSize,
            ]);
        });
    }

    /**
     * Get Glossary Posts
     *
     * @param string $glossarySlug
     * @param string $character
     * @param string $type
     * @param int $pageSize
     * @param int $page
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-13, 00:44 ICT
     */
    public function getGlossaryPostList(string $glossarySlug, string $character = 'a', string $type = 'post', int $pageSize = 12, int $page = 1, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;

        $languageId = lang('id', get_lang_id());
        $cacheKey = sprintf(Constant::CACHE_POSTS[Constant::POSTS_BY_CATEGORIES_IDS], $languageId, $type, 'glossary:' . $character, $pageSize, $page);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::listScope($type, $languageId),
            ContentPostCacheInvalidationService::latestScope($type, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $type, $character, $pageSize, $page, $glossarySlug, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get posts by glossary: ' . $character . ' and type: ' . $type . ' and limit: ' . $pageSize . ' and page: ' . $page, $ttl);

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                    'language_id' => $languageId,
                ],
                'when' => [
                    $character !== 'all',
                    fn($q) => $q->where('name', 'like', $character . '%'),
                ],
                'has' => [
                    'category' => fn($query) => $query->whereHas('slugs', function ($q) use ($glossarySlug) {
                        return $q->where('slug', $glossarySlug);
                    }),
                ],
                'page' => $pageSize,
            ]);
        });
    }

    /**
     * Get post detail by slug and category slug and type
     *
     * @param string $slug
     * @param string $categorySlug
     * @param string $type
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-07, 20:59 ICT
     */
    public function getPostDetailBySlugAndCategorySlug(string $slug, string $categorySlug, string $type, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $languageId = lang('id', get_lang_id());

        $cacheKey = sprintf(
            '%s:lang:%s',
            sprintf(Constant::CACHE_POSTS[Constant::POST_DETAIL_BY_ID], $type, $slug),
            $languageId
        );
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::slugScope($slug, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $type, $slug, $categorySlug, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get post detail by slug: ' . $slug . ' and type: ' . $type . ' and category slug: ' . $categorySlug, $ttl);

            return $this->get([
                'take' => 1,
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                ],
                'with' => [
                    'slugs:target_id,slug',
                    'images',
                    'attachments',
                    'category' => fn($q) => $q->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug'),
                    'author' => fn($q) => $q->select('id', 'name')->with('avatar'),
                ],
                'has' => [
                    'slugs' => function ($query) use ($slug) {
                        return $query->where('slug', $slug);
                    },
                ],
            ]);
        });
    }

    /**
     * Get post detail by slug
     *
     * @param string $slug
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-07, 20:59 ICT
     */
    public function getPostDetailBySlug(string $slug, bool $freshCache = false): mixed
    {
        $languageId = lang('id', get_lang_id());

        return CMSCache::rememberWithTracking(
            $this->versionedCacheKey(CacheKeyHelper::postBySlug($slug, $languageId), [
                ContentPostCacheInvalidationService::slugScope($slug, $languageId),
            ]),
            function () use ($slug, $languageId) {
                return $this->model()
                    ->whereHas('slugs', fn($q) => $q->where('slug', $slug))
                    ->where([
                        'status' => StatusEnum::PUBLISHED->value,
                        'language_id' => $languageId,
                    ])
                    ->where(function ($query) {
                        return $query->whereNull('published_at')
                            ->orWhere('published_at', '<=', now());
                    })
                    ->with([
                        'author:id,username,name',
                        'slugs:target_id,slug',
                        'seos:target_id,title,description,hashtag,language_id',
                        'images',
                        'tags',
                        'category' => fn($q) => $q->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug'),
                        'categories' => fn($q) => $q->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug')
                            ->published(),
                    ])
                    ->first();
            });
    }

    /**
     * Get post list by categories id list
     *
     * @param array $categoriesIds
     * @param int $page
     * @param int $pageSize
     * @param string $type
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-07, 20:05 ICT
     */
    public function getPostsByCategoriesIds(array $categoriesIds = [], int $page = 1, int $pageSize = 12, string $type = 'post', bool $freshCache = false): mixed
    {
        $languageId = CacheKeyHelper::getLangId();

        return CMSCache::rememberRandomWithTracking(
            $this->versionedCacheKey(
                CacheKeyHelper::postByCategoryIds($categoriesIds, $page, $pageSize, $type),
                [
                    ContentPostCacheInvalidationService::listScope($type, $languageId),
                    ContentPostCacheInvalidationService::latestScope($type, $languageId),
                    ...array_map(
                        static fn ($categoryId): string => ContentPostCacheInvalidationService::categoryScope($categoryId, $type, $languageId),
                        $categoriesIds
                    ),
                ]
            ),
            function () use ($categoriesIds, $type, $pageSize, $languageId) {
                return $this->get([
                    'select' => ['id', 'name', 'description', 'status', 'published_at', 'type', 'category_id', 'user_id', 'video_url'],
                    'where' => [
                        'type' => $type,
                        'status' => StatusEnum::PUBLISHED->value,
                        function ($query) {
                            return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                        },
                        'language_id' => $languageId,
                    ],
                    'has' => [
                        'target' => function ($query) use ($categoriesIds) {
                            return $query->whereIn('category_id', $categoriesIds);
                        },
                    ],
                    'with' => [
                        'slugs:target_id,slug',
                        'images',
                        'category' => function ($q) {
                            $q->select('id', 'name')->withChildrenExists()->with('slugs:target_id,slug');
                        },
                    ],
                    //  Batch the two per-row probes that fire during
                    //  serialization. On v4 public/category/featured the R2
                    //  ledger EXISTS behind `image` was 60 of 149 cold queries
                    //  and the `total_view` SUM over `tracks` another 36.
                    'scopes' => ['withImage', 'withTotalView'],
                    'page' => $pageSize,
                    'latest' => true,
                ]);
            });
    }

    /**
     * Get detail of post by slug and type
     *
     * @param string $slug
     * @param string $type
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-02, 21:26 ICT
     */
    public function getPostDetailBySlugAndType(string $slug, string $type, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $languageId = lang('id', get_lang_id());

        $cacheKey = sprintf(
            '%s:lang:%s',
            sprintf(Constant::CACHE_POSTS[Constant::POST_DETAIL_BY_ID], $type, $slug),
            $languageId
        );
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::slugScope($slug, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $slug, $type, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get post detail by slug: ' . $slug . ' and type: ' . $type, $ttl);

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                ],
                'with' => [
                    'slugs',
                    'seos',
                    'categories' => function ($query) {
                        return $query->whereHas('slugs');
                    },
                    'attachments',
                    'images',
                ],
                'has' => [
                    'slugs' => function ($query) use ($slug) {
                        return $query->where('slug', $slug);
                    },
                ],
                'take' => 1,
            ]);
        });
    }

    /**
     * Search by type and keywords
     *
     * @param string|null $keywords
     * @param int $pageSize
     * @param int $page
     * @param string $articleType
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-01, 20:09 ICT
     */
    public function searchByType(string $keywords = null, int $pageSize = 12, int $page = 1, string $articleType = 'post', bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $languageId = lang('id', get_lang_id());

        $cacheKey = sprintf(Constant::CACHE_POSTS[Constant::SEARCH_BY_TYPE], $languageId, $articleType, $pageSize, $page, $keywords);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::searchScope($articleType, $languageId),
            ContentPostCacheInvalidationService::listScope($articleType, $languageId),
        ]);

        return cache()->remember(
            $cacheKey, $ttl,
            function () use ($cacheKey, $ttl, $articleType, $keywords, $page, $pageSize, $languageId) {
                CacheHelper::getInstance()->store($cacheKey, 'Search by type: ' . $articleType . ' and keywords: ' . $keywords . ' and page: ' . $page . ' and page size: ' . $pageSize, $ttl);

                return $this->model()->where([
                    'type' => $articleType,
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                ])->where(function ($query) {
                    return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                })
                    ->with([
                        'slugs',
                        'seos',
                        'categories' => function ($query) {
                            return $query->whereHas('slugs');
                        },
                        'attachments',
                        'images',
                    ])
                    ->whereHas('slugs')
                    ->whereFulltext(['name', 'description', 'hashtag', 'content'], $keywords)
                    ->paginate($pageSize, ['*'], 'page', $page);
            }
        );
    }

    /**
     * Get related post by categoryId and postId
     *
     * @param int $categoryId
     * @param int $postId
     * @param int $limit
     * @param string $type
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-29, 11:18 ICT
     */
    public function getRelatedPosts(int $categoryId, int $postId, int $limit = 6, string $type = 'post', bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $languageId = lang('id', get_lang_id());

        $cacheKey = sprintf(Constant::CACHE_POSTS[Constant::RELATED_POSTS], $languageId, $categoryId, $postId, $limit);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::categoryScope($categoryId, $type, $languageId),
            ContentPostCacheInvalidationService::postScope($postId),
            ContentPostCacheInvalidationService::latestScope($type, $languageId),
        ]);

        $posts = cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $categoryId, $postId, $limit, $type, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get related posts by category id: ' . $categoryId . ' and post id: ' . $postId . ' and limit: ' . $limit, $ttl);

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                    ['id', '!=', $postId],
                    'language_id' => $languageId,
                ],
                'has' => [
                    'categories' => function ($query) use ($categoryId) {
                        return $query->where('id', $categoryId)->published();
                    },
                    'category' => function ($query) {
                        return $query->published();
                    },
                    'slugs',
                ],
                'with' => [
                    'images', 'slugs', 'seos', 'category',
                ],
                'order' => [
                    'published_at' => 'desc',
                    'created_at' => 'desc',
                ],
                'take' => $limit,
            ]);
        });

        return $posts;
    }

    /**
     * Get Featured post by type (for client)
     *
     * @param string $type
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-25, 22:47 ICT
     */
    public function getFeaturedPostByType(string $type = 'post', int $limit = 10, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $languageId = lang('id', get_lang_id());

        $typeConstant = match ($type) {
            'audio' => Constant::LATEST_WITH_AUDIO,
            'video' => Constant::LATEST_WITH_VIDEO,
            'document' => Constant::LATEST_WITH_DOCUMENT,
            default => Constant::LATEST_WITH_POST,
        };

        $cacheKey = sprintf(Constant::CACHE_POSTS[$typeConstant], $languageId, $limit);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::featuredScope($type, $languageId),
            ContentPostCacheInvalidationService::configScope($type, 'featured', $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $type, $limit, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get featured post by type: ' . $type . ' and limit: ' . $limit, $ttl);

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                    'config->featured' => true,
                    'language_id' => $languageId,
                ],
                'order' => [
                    'published_at' => 'desc',
                    'created_at' => 'desc',
                ],
                'when' => [
                    [
                        $limit > 0,
                        fn($query) => $query->take($limit),
                    ],
                ],
            ]);
        });
    }

    /**
     * Get latest post by type (for client)
     *
     * @param string $type
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-24, 22:32 ICT
     */
    public function getLatestPostByType(string $type = 'post', int $limit = 6, bool $freshCache = false): mixed
    {
        $languageId = CacheKeyHelper::getLangId();

        return CMSCache::rememberRandomWithTracking(
            $this->versionedCacheKey(CacheKeyHelper::latestPostByType($type, $limit), [
                ContentPostCacheInvalidationService::latestScope($type, $languageId),
                ContentPostCacheInvalidationService::listScope($type, $languageId),
            ]),
            function () use ($type, $limit, $languageId) {
                return $this->get([
                    'where' => [
                        'type' => $type,
                        'status' => StatusEnum::PUBLISHED->value,
                        function ($query) {
                            return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                        },
                        'language_id' => $languageId,
                    ],
                    'order' => [
                        'published_at' => 'desc',
                        'created_at' => 'desc',
                    ],
                    'when' => [
                        [
                            $type === 'document',
                            fn($query) => $query->with([
                                'attachments:type,file_size,custom',
                            ]),
                        ],
                    ],
                    'page' => $limit,
                ]);
            });
    }

    /**
     * Get all categories by post type (for client)
     *
     * @param string $type
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-24, 15:03 ICT
     */
    public function getAllCategoriesByType(string $type = 'post', bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $languageId = lang('id', get_lang_id());

        $typeConstant = match ($type) {
            'audio' => Constant::ALL_WITH_AUDIO,
            'video' => Constant::ALL_WITH_VIDEO,
            'document' => Constant::ALL_WITH_DOCUMENT,
            default => Constant::ALL_WITH_POST,
        };
        $cacheKey = sprintf(Constant::CACHE_CATEGORIES[$typeConstant], $languageId);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::listScope($type, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($type, $cacheKey, $ttl, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Get all categories by type: ' . $type . ' and language ' . $languageId, $ttl);

            //  Get all id of post by type
            $postIds = $this->get([
                'select' => ['id'],
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    'language_id' => $languageId,
                ],
                'with' => [
                    'categories' => fn($query) => $query->with([
                        'slugs', 'seo', 'images',
                    ])->published(),
                ],
            ]);

            //  Get all categories id and return
            $categories = $postIds->pluck('categories')->flatten();

            $idUniques = array_unique($categories->pluck('id')->toArray());

            //  Get all categories by $idUniques from $categories -> must be unique after filter
            return $categories->filter(function ($value, $key) use ($idUniques) {
                return in_array($value->id, $idUniques, true);
            })->unique('id');
        });
    }

    /**
     * Get posts list by user id (for client)
     *
     * @param int $userId
     * @param array $params
     * @param int $languageId
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-03-10, 01:18 ICT
     */
    public function getByUserId(int $userId, array $params = [], int $languageId = 1): mixed
    {
        return $this->get([
            'where' => [
                'user_id' => $userId,
                'status' => StatusEnum::PUBLISHED->value,
                'type' => $params['type'] ?? 'post',
                'language_id' => $languageId,
                static function ($query) {
                    return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                },
            ],
            'when' => [
                [
                    !empty($params['query']),
                    fn($query) => $query->where('name', 'like', '%' . $params['query'] . '%'),
                ],
            ],
            'page' => $params['perPage'] ?? 10,
            'with' => [
                'category' => fn($query) => $query->select('id', 'name'),
                'language' => fn($query) => $query->select('id', 'name', 'path', 'flag'),
            ],
        ]);
    }

    /**
     * Get the latest video
     *
     * @param int $limit
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-09-10, 14:58 ICT
     */
    public function getLatestVideo(int $limit = 6): mixed
    {
        $languageId = lang()->id ?? 1;
        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_POSTS,
            Constant::CACHE_KEY_POSTS => 'latest-video',
            'limit' => $limit,
            'language_id' => $languageId,
        ]);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::latestScope('post', $languageId),
            ContentPostCacheInvalidationService::listScope('post', $languageId),
        ]);

        $videos = cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($cacheKey, $limit) {

            return $this->get([
                'select' => [
                    'id', 'name', 'video_url',
                ],
                'where' => [
                    'type' => 'post',
                    'status' => StatusEnum::PUBLISHED->value,
                    static function ($q) {
                        return $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                    'language_id' => (lang()->id ?? 1),
                    ['category_id', '!=', null],
                    ['video_url', '!=', null],
                ],
                'when' => [
                    [
                        $limit > 0,
                        function ($q) use ($limit) {
                            $q->take($limit);
                        },
                    ],
                ],
                'order' => [
                    'published_at' => 'desc',
                    'created_at' => 'desc',
                ],
            ]);
        });

        return $videos;
    }

    /**
     * Get total post by root or not (website/APIs use)
     *
     * @param bool $root
     * @param array $select
     *
     * @return Collection|LengthAwarePaginator|array
     * @author malayvuong
     * @since 6.19.6 - 2022-08-03, 12:48 ICT
     */
    public function get_total_post(bool $root = false, array $select = []): Collection|LengthAwarePaginator|array
    {
        $cacheKey = make_key([
            'type' => Constant::CACHE_KEY_POSTS, Constant::CACHE_KEY_POSTS => 'count',
            'count' => 'total', 'root' => $root,
        ]);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::listScope('post', null),
        ]);

        return cache()->remember($cacheKey, config('setting.cache.blog', 3600), function () use ($cacheKey, $select) {

            return $this->get([
                'select' => $select,
                'where' => [
                    'type' => 'post',
                    'status' => StatusEnum::PUBLISHED->value,
                    static function ($q) {
                        return $q->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                ],
            ]);
        });
    }

    /**
     * Search by keywords
     *
     * @param string $type
     * @param string|null $categorySlug
     * @param ?string $keywords
     * @param int $pageSize
     * @param int $page
     * @param bool $freshCache
     *
     * @return Collection|LengthAwarePaginator|array
     * @author malayvuong
     * @since 6.19.6 - 2022-08-02, 12:56 ICT
     */
    public function search(string $type, ?string $categorySlug = null, ?string $keywords = null, int $pageSize = 10, int $page = 1, bool $freshCache = false): Collection|LengthAwarePaginator|array
    {
        $ttl = $freshCache ? config('setting.cache.cache.blog', 3600) : 1;
        $languageId = lang('id', get_lang_id());

        $cacheKey = sprintf(Constant::CACHE_POSTS[Constant::SEARCH], $languageId, $type, $categorySlug, $keywords, $pageSize, $page);
        $cacheKey = $this->versionedCacheKey($cacheKey, [
            ContentPostCacheInvalidationService::searchScope($type, $languageId),
            ContentPostCacheInvalidationService::listScope($type, $languageId),
        ]);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $type, $categorySlug, $keywords, $pageSize, $page, $languageId) {
            CacheHelper::getInstance()->store($cacheKey, 'Search by type: ' . $type . ' and category slug: ' . $categorySlug . ' and keywords: ' . $keywords . ' and page: ' . $page . ' and page size: ' . $pageSize, $ttl);

            $keywords = str($keywords)
                ->replace(['+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '\\', '/', ' '], '%')
                ->trim()->__toString();

            return $this->get([
                'where' => [
                    'type' => $type,
                    'status' => StatusEnum::PUBLISHED->value,
                    function ($query) {
                        return $query->whereNull('published_at')->orWhere('published_at', '<=', now());
                    },
                    'language_id' => $languageId,
                ],
                'when' => [
                    [
                        !empty($categorySlug),
                        fn($query) => $query->whereHas('category', fn($q) => $q->whereHas('slugs', fn($q) => $q->where('slug', $categorySlug))),
                    ],
                    [
                        !empty($keywords),
                        fn($query) => $query->where(function ($q) use ($keywords) {
                            $q->where('name', 'like', '%' . $keywords . '%')
                                ->orWhere('description', 'like', '%' . $keywords . '%')
                                ->orWhere('content', 'like', '%' . $keywords . '%');
                        }),
                    ],
                ],
                'page' => $pageSize,
                'order' => [
                    'published_at' => 'desc',
                    'created_at' => 'desc',
                ],
                'with' => [
                    'images', 'slugs',
                    'categories' => fn($query) => $query->with([
                        'slugs',
                    ]),
                ],
                'has' => ['slugs', 'category'],
//                'sql' => true,
            ]);
        });
    }

    /**
     * Show the list paginate
     *
     * @param string $type
     * @param mixed $category
     *
     * @return Collection|LengthAwarePaginator|array
     * @author malayvuong
     * @since 6.19.6 - 2022-06-17, 21:17 ICT
     */
    public function index(string $type = 'post', mixed $category = null): Collection|LengthAwarePaginator|array
    {
        $request = request();
        $filters = $request->all();
        unset($filters['categoryId']);
        unset($filters['keywords']);

        $keywords = trim((string)$request->input('keywords', ''));
        $types = [];
        //  If $type has a ",", split it to array and put to condition
        if (str($type)->contains(',')) {
            $types = array_map('trim', explode(',', $type));
        }
        $isQaType = $type === 'qa' || in_array('qa', $types, true);
        $select = [
            'id', 'uuid', 'name', 'language_id', 'category_id', 'user_id', 'created_at', 'published_at',
            'status', 'config', 'video_url', 'deleted_at', 'type',
        ];
        if ($isQaType) {
            $select[] = 'content';
        }

        return $this->get([
            'select' => $select,
            'order' => ['id' => 'desc'],
            'filter' => $filters,
            'where' => [
                'type' => $type,
            ],
            'page' => max(1, min((int) request('pageSize', 10), 100)),
            'when' => [
                [
                    count($types) > 1,
                    fn($q) => $q->whereIn('type', $types),
                ],
                [
                    count($types) === 1,
                    fn($q) => $q->where('type', $type),
                ],
                [
                    !empty($keywords),
                    fn($q) => $q->where(fn($query) => $query
                        ->where('name', 'like', '%' . $keywords . '%')
                        ->orWhere('content', 'like', '%' . $keywords . '%')),
                ],
                [
                    $this->hasPostIndexCategoryFilter($request->input('categoryId')),
                    function ($q) use ($request, $category) {
                        $last = $this->lastPostIndexCategoryValue($request->input('categoryId'));

                        if ($this->isNoCategoryFilterValue($last)) {
                            return $q->whereNull('category_id');
                        }

                        if (is_numeric((string)$last) && (int)$last > 0) {
                            //  Get all children categories ids
                            $ids = $category->getChildrenIds((int)$last);
                            $ids[] = (int)$last;

                            return $q->whereIn('category_id', $ids);
                        }

                        return $q;
                    },
                ],
            ],
//            'when' => [
//                [
//                    request('startDate') && request('endDate'),
//                    function ($q) {
//                        $startDate = now()->parse(request('startDate'))->setTimeFromTimeString('00:00');
//                        $endDate = now()->parse(request('endDate'))->setTimeFromTimeString('23:59');
//
//                        return $q->whereBetween('created_at', [$startDate, $endDate]);
//                    },
//                ],
//            ],
            'with' => [
                'language:id,name,path,flag',
                'categories:id,name',
                'category:id,name',
                'slugs:target_id,slug',
                'author:id,username,name',
                'attachs:id,target_id',
            ],
        ]);
    }

    private function hasPostIndexCategoryFilter(mixed $categoryId): bool
    {
        $last = $this->lastPostIndexCategoryValue($categoryId);

        return $last !== null && $last !== 'all';
    }

    private function lastPostIndexCategoryValue(mixed $categoryId): mixed
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $values = is_array($categoryId)
            ? collect($categoryId)->flatten()
            : collect(explode(',', (string)$categoryId));

        return $values
            ->map(static fn($value) => is_string($value) ? trim($value) : $value)
            ->filter(static fn($value) => $value !== null && $value !== '' && $value !== [])
            ->last();
    }

    private function isNoCategoryFilterValue(mixed $value): bool
    {
        return in_array($value, [0, '0', 'no-category'], true);
    }

    /**
     * {@inheritDoc}
     *
     * Deliberately not routed through searchByType(): that path caches raw keywords in its cache key and
     * CacheHelper record. This query is never cached.
     *
     * Natural questions: the question's topic words (stop words and punctuation dropped, diacritics folded,
     * any order) are matched against the projected title, description and body, the text the model sees. A post needs
     * at least half of the words; each word scores 4 in the title, 2 in the description, 1 in the body (best field);
     * ties go to the newest post, then the highest id. No fuzzy matching: a typo matches nothing.
     */
    public function searchAgentEligible(string $keywords, CarbonInterface $asOf, int $limit = 5): Collection
    {
        $limit = max(0, $limit);
        $uuids = array_values(array_filter(
            array_keys((array) config('content.agent_answers.approved_posts', [])),
            static fn (mixed $uuid): bool => is_string($uuid) && $uuid !== '',
        ));
        $text = app(AgentAnswerSearchText::class);
        $terms = $text->terms($keywords);

        if ($limit === 0 || $uuids === [] || $terms === []) {
            return new Collection();
        }

        $languageId = (int) lang('id', get_lang_id());
        // Appended-relation eager loads (slug, image, ...) are not needed: callers project only plain fields.
        $eligible = static fn ($query) => $query
            ->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)
            ->publicAt($asOf)
            ->where('posts.type', 'post')
            ->where('posts.language_id', $languageId)
            ->whereIn('posts.uuid', $uuids);

        // First read: ids of the eligible approved posts only (the approved map bounds the corpus).
        $ids = $eligible(Post::query()->select('posts.id'))
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id')
            ->limit(max(1, (int) config('content.agent_answers.max_candidates', 1000)))
            ->pluck('posts.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return new Collection();
        }

        // Second read: re-apply every predicate so a row changed since the first read drops out.
        $rows = $eligible(Post::query())->whereIn('posts.id', $ids)->get();
        $projector = app(AgentReadablePostProjector::class);
        $needed = (int) ceil(count($terms) / 2);
        $scored = [];
        foreach ($rows as $post) {
            $document = $projector->project($post);
            $fields = [
                4 => $text->words((string) $document['title']),
                2 => $text->words((string) $document['summary']),
                1 => $text->words(implode(' ', array_column((array) $document['sections'], 'text'))),
            ];
            $score = 0;
            $matched = 0;
            foreach ($terms as $term) {
                foreach ($fields as $weight => $words) {
                    if (isset($words[$term])) {
                        $score += $weight;
                        $matched++;
                        break;
                    }
                }
            }
            if ($matched >= $needed) {
                $scored[] = [$score, (string) ($post->getRawOriginal('published_at') ?? ''), (int) $post->id, $post];
            }
        }

        usort($scored, static fn (array $left, array $right): int => [$right[0], $right[1], $right[2]] <=> [$left[0], $left[1], $left[2]]);

        return new Collection(array_column(array_slice($scored, 0, $limit), 3));
    }
}
