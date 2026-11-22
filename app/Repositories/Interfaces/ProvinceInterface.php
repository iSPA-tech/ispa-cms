<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface as BaseRepositoryInterface;

interface ProvinceInterface extends BaseRepositoryInterface
{
    /**
     * Get parent data via UUID with children relationship
     *
     * @param string|null $uuid
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-14, 17:33 ICT
     */
    public function getProvince(string $uuid = null): mixed;

    /**
     * Get all provinces list
     *
     * @param int $parentId
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-04-14, 14:18 ICT
     */
    public function getProvinces(int $parentId = 0): mixed;
}
