<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Interfaces;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use App\Repositories\Interfaces\JsonException;
use Cms\Core\Repositories\Interfaces\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for blog post / article persistence and query operations.
 *
 * Defines cache-aware methods for post listing, search, detail retrieval,
 * category filtering, glossary navigation, and featured/latest queries.
 * Implemented by {@see \Cms\Content\Repositories\PostRepository}.
 *
 * @package Cms\Content\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 *
 * @api Content public API; signature changes follow the module notes.
 */
interface PostInterface extends RepositoryInterface
{
    /**
     * Get latest post by category slug
     *
     * @param string $slug
     * @param int $limit
     * @param bool $freshCache
     * @param array $optional
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2025-01-11, 20:26 ICT
     */
    public function getLatestPostByCategorySlugAndType(string $slug, int $limit = 6, bool $freshCache = false, array $optional = []): mixed;

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
    public function getPostListByTypeAndCategorySlug(string $type = 'post', string $categorySlug = null, int $pageSize = 10, bool $freshCache = false): mixed;


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
    public function getPostByTypeAndDateRange(string $type = 'post', string $startDate = null, string $endDate = null, bool $freshCache = false): mixed;

    /**
     * Get Latest pots by limit
     *
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-05-03, 11:16 ICT
     */
    public function getLatestPosts(int $limit = 5, bool $freshCache = false): mixed;

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
    public function getPostByTypeAndConfig(string $type = 'post', string $custom = 'featured', int $limit = 5, bool $freshCache = false): mixed;

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
    public function getFeaturedPosts(int $limit = 5, bool $freshCache = false): mixed;

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
    public function getPostDetailByUuid(string $uuid, bool $freshCache = false): mixed;

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
    public function advancedSearch(array $filter = [], bool $freshCache = false): mixed;

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
    public function getPostListByType(string $type = 'post', int $pageSize = 12, int $page = 1, bool $freshCache = false): mixed;

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
    public function getLatestPostRequiredLoginByType(string $type = 'post', int $pageSize = 12, int $page = 1, bool $freshCache = false): mixed;

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
    public function getGlossaryPostList(string $glossarySlug, string $character = 'a', string $type = 'post', int $pageSize = 12, int $page = 1, bool $freshCache = false): mixed;

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
    public function getPostDetailBySlugAndCategorySlug(string $slug, string $categorySlug, string $type, bool $freshCache = false): mixed;

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
    public function getPostDetailBySlug(string $slug, bool $freshCache = false): mixed;

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
    public function getPostsByCategoriesIds(array $categoriesIds = [], int $page = 1, int $pageSize = 12, string $type = 'post', bool $freshCache = false): mixed;

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
    public function getPostDetailBySlugAndType(string $slug, string $type, bool $freshCache = false): mixed;

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
    public function searchByType(string $keywords = null, int $pageSize = 12, int $page = 1, string $articleType = 'post', bool $freshCache = false): mixed;

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
    public function getRelatedPosts(int $categoryId, int $postId, int $limit = 6, string $type = 'post', bool $freshCache = false): mixed;

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
    public function getFeaturedPostByType(string $type = 'post', int $limit = 10, bool $freshCache = false): mixed;

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
    public function getLatestPostByType(string $type = 'post', int $limit = 6, bool $freshCache = false): mixed;

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
    public function getAllCategoriesByType(string $type = 'post', bool $freshCache = false): mixed;

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
    public function getByUserId(int $userId, array $params = [], int $languageId = 1): mixed;

    /**
     * Get the latest video
     *
     * @param int $limit
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-09-10, 14:58 ICT
     */
    public function getLatestVideo(int $limit = 6): mixed;


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
    public function search(string $type, ?string $categorySlug = null, ?string $keywords = null, int $pageSize = 10, int $page = 1, bool $freshCache = false): Collection|LengthAwarePaginator|array;

    /**
     * Show the list paginate
     *
     * @param  string $type
     * @param  mixed $category
     * @return Collection|LengthAwarePaginator|array
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-17, 21:17 ICT
     */
    public function index(string $type = 'post', mixed $category = null): Collection|LengthAwarePaginator|array;

    /**
     * Agent answer corpus search: approved (`content.agent_answers.approved_posts`), public at `$asOf`, type
     * `post`, current language. Uncached. The question's topic words (stop words and punctuation dropped, diacritics
     * folded, any order) are matched in the projected title, description and body; ranked title > description >
     * body, then newest, then id. Two reads: eligible ids, then a hydration that re-applies every predicate. A
     * question with no topic word returns nothing.
     */
    public function searchAgentEligible(string $keywords, CarbonInterface $asOf, int $limit = 5): Collection;
}
