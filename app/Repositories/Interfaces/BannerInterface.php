<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface as BaseRepositoryInterface;

interface BannerInterface extends BaseRepositoryInterface
{

    /**
     * Get banner list by type
     *
     * @param string $type
     * @param array $closedBanners
     * @param int $limit
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-02-17, 11:44 ICT
     */
    public function getBanners(string $type = '', array $closedBanners = [], int $limit = 1): mixed;
}
