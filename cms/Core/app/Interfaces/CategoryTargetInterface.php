<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Interfaces;

use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Repositories\Interfaces\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for CategoryTarget pivot repository helpers to fetch related uploads/posts.
 *
 * @extends RepositoryInterface
 * @package Cms\Core\Interfaces
 */
interface CategoryTargetInterface extends RepositoryInterface
{
    /**
     * Get upload file by type array
     *
     * @param string $type
     * @param array $condition
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-08-21, 21:18 ICT
     */
    public function getUploadedByType(string $type = 'document', array $condition = []): mixed;

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
    public function getRelatedPosts(int $category_id, int $post_id, int $limit = 6, string $type = 'post'): mixed;

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
    public function getPostsByCategoryIds(array $ids = [], int $limit = 12): Collection|LengthAwarePaginator|array|null;
}
