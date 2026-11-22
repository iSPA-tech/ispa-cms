<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories\Interfaces;

use Illuminate\Database\Eloquent\Builder;

/**
 * Contract for URL slug persistence, lookup, and entity-by-slug resolution.
 *
 * Provides slug creation/update, slug-based entity lookups for brands,
 * products, categories, posts, and glossary content.
 *
 * @package Cms\Core\Repositories\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface SlugInterface extends RepositoryInterface
{
    /**
     * Get brand by slug
     *
     * @param  string|null $slug
     * @param  array $with
     * @param  string $suffix
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-03-13, 20:53 ICT
     */
    public function getBrandBySlug(?string $slug, array $with = [], string $suffix = ''): mixed;

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
    public function getProductBySlug(?string $slug, array $with = [], string $suffix = ''): mixed;

    /**
     * glossary + posts paginate by character
     *
     * @param int $limit
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-10-08, 23:34 ICT
     */
    public function latestGlossary(int $limit = 4): mixed;

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
    public function glossary(string $slug, string $character = 'a'): mixed;

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
    public function getCategoryBySlug(?string $slug, array $with = [], string $suffix = ''): mixed;

    /**
     * Get post by slug
     *
     * @param string $slug
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-08-20, 16:32 ICT
     */
    public function getPostBySlug(string $slug): mixed;

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
    public function getSameSlug(string $slug, string $target_type = null): object|array|null;

    /**
     * Create or Update slug
     *
     * @param
     * @param  string|null  $slug
     * @param  int|null  $language_id
     * @param  string|null  $category
     * @param  mixed $model
     * @return mixed
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-25, 11:6
     */
    public function storeSlug($model, ?string $slug = null, ?int $language_id = null, ?string $category = null): mixed;
}
