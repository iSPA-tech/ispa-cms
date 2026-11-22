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
 * Contract for MenuItem entity CRUD and group-based item queries.
 *
 * Implemented by: {@see MenuItemRepository}.
 * Used for: {@see MenuItem} data access, including cached retrieval
 * of menu items by their parent menu group.
 *
 * @see \Cms\Core\Repositories\MenuItemRepository
 * @see \Cms\Core\Models\MenuItem
 *
 * @package Cms\Core\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface MenuItemInterface extends RepositoryInterface
{
    /**
     * Get Item List by Menu Group
     *
     * @param string $groupName
     * @param bool $freshCache
     * @param int|null $languageId `languages.id` of the menu; null takes the request language. A site that picks its
     *                             language from the URL passes it, since the request language is resolved once.
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-11, 15:02 ICT
     */
    public function getMenuItemListByMenuGroup(string $groupName, bool $freshCache = false, ?int $languageId = null): mixed;
}
