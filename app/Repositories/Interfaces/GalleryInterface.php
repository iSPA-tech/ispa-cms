<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface as BaseRepositoryInterface;

use Illuminate\Database\Eloquent\Collection;

interface GalleryInterface extends BaseRepositoryInterface
{
    /**
     * Get latest galleries by limit
     *
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-08-26, 20:40 ICT
     */
    public function getLatestGalleries(int $limit, bool $freshCache = false): mixed;

    /**
     * Get all galleries paginated
     *
     * @param int $pageSize
     * @param int $page
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-12-17, 10:35 ICT
     */
    public function getGalleries(int $pageSize, int $page = 1, bool $freshCache = false): mixed;

    /**
     * Get related galleries
     *
     * @param int $exceptId
     *
     * @return Collection|array
     * @author malayvuong
     * @since 6.62.6 - 2022-08-28, 17:31 ICT
     */
    public function getRelatedGalleries(int $exceptId = 0): Collection|array;
}
