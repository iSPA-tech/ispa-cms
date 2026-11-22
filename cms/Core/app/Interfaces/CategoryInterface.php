<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Interfaces;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Foundation\Application;
use Cms\Core\Repositories\Interfaces\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for Category repository queries including trees, featured sets, and admin listings.
 *
 * @extends RepositoryInterface
 * @package Cms\Core\Interfaces
 */
interface CategoryInterface extends RepositoryInterface
{
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
    public function getByUid(string $uid, bool $freshCache = false): mixed;

    /**
     * Resolve a published category id from its uid, with no relations.
     *
     * getByUid() hydrates the row plus its images, slugs, seo and children —
     * and the appended-relation scope adds a slug and an image load per level
     * on top, measured at 11 queries. Callers that only need the key should not
     * pay for a payload they discard.
     *
     * @param string $uid
     *
     * @return int|null
     */
    public function getIdByUid(string $uid): ?int;

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
    public function getTreeBySlug(string $slug, bool $freshCache = false): mixed;

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
    public function getCategoryBySlugWithPosts(string $slug, bool $freshCache = false): mixed;

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
    public function getCategoryBySlugAndType(string $slug, int $type = 0, bool $freshCache = false): mixed;

    /**
     * Get children ids
     *
     * @param int $id
     *
     * @return array
     * @author malayvuong
     * @since 6.62.6 - 2023-05-13, 14:38 ICT
     */
    public function getChildrenIds(int $id): array;

    /**
     * Get children ids for many roots in one batched traversal.
     *
     * @param array<int, int|string> $ids
     * @return array<int, array<int, int>>
     */
    public function getChildrenIdsForMany(array $ids): array;

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
    public function getFeaturedCategoriesWithLimitedArticles(int $type = 0, int $articleLimit = 6, bool $freshCache = false, int $noPosts = 0): mixed;

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
    public function getFeaturedCategories(int $type = 0, int $childLimit = 0): mixed;

    /**
     * Get all categories with relationships (WEBSITE)
     *
     * @param array $condition
     *
     * @return array|LengthAwarePaginator|Collection
     * @author malayvuong
     * @since 6.47.5 - 2022-08-13, 02:14 ICT
     */
    public function getAllWithRelationship(array $condition = []): Collection|LengthAwarePaginator|array;

    /**
     * Document with relationship
     *
     * @param int $take
     *
     * @return Closure
     * @author malayvuong
     * @since 6.47.5 - 2022-08-11, 01:19 ICT
     */
    public function documentRelation(int $take = 6): Closure;

    /**
     * Admin: Get list
     *
     * @param  Request $request
     * @return Collection|LengthAwarePaginator|array
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-19, 15:43 ICT
     */
    public function AdminIndex(Request $request): Collection|LengthAwarePaginator|array;

    /**
     * Get categories list by type
     *
     * @param string $type
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-07, 19:31 ICT
     */
    public function getCategoriesByType(string $type = 'post'): mixed;
}
