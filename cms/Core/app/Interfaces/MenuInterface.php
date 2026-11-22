<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface;

/**
 * Contract for Menu entity CRUD and specialized navigation queries.
 *
 * Implemented by: {@see MenuRepository}.
 * Used for: {@see Menu} data access, including cached group-key lookups
 * and language-aware menu list retrieval.
 *
 * @see \Cms\Core\Repositories\MenuRepository
 * @see \Cms\Core\Models\Menu
 *
 * @package Cms\Core\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface MenuInterface extends RepositoryInterface
{

    /**
     * Get menu for client
     *
     * @param string $groupName
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-11, 08:53 ICT
     */
    public function getMenuDetailByGroupName(string $groupName, bool $freshCache = false): mixed;

    /**
     * Get menu by group key (first one latest)
     *
     * @param string $groupKey
     * @param int $langId
     *
     * @return object|null
     * @author malayvuong
     * @since 6.62.6 - 2023-03-03, 08:30 ICT
     */
    public function getMenuByGroupKey(string $groupKey): ?object;

    /**
     * Get menu list
     *
     * @param array $query
     * @param int $langId
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-03-03, 08:30 ICT
     */
    public function getMenuList(array $query, int $langId): mixed;
}
