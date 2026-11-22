<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface;

/**
 * Contract for bookmark persistence operations.
 *
 * Defines cache-aware queries for user bookmarks filtered by target type.
 * Implemented by {@see \Cms\Content\Repositories\BookmarkRepository}.
 *
 * @package Cms\Content\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 *
 * @api Content public API; signature changes follow the module notes.
 */
interface BookmarkInterface extends RepositoryInterface
{

    /**
     * Get bookmark list by target
     *
     * @param string $type
     * @param int $page
     * @param int $pageSize
     * @param int $userId
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-24, 20:16 ICT
     */
    public function getBookmarkListByTarget(string $type, int $page, int $pageSize, int $userId, bool $freshCache = false): mixed;

    /**
     * Get latest bookmark
     *
     * @param string $type
     * @param int $userId
     * @param int $limit
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-20, 20:48 ICT
     */
    public function getLatestBookmark(string $type, int $userId, int $limit = 10, bool $freshCache = false): mixed;

    /**
     * Get bookmark list
     *
     * @param string $type
     * @param int $userId
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-19, 11:22 ICT
     */
    public function getBookmarkByTarget(string $type, int $userId, bool $freshCache = false): mixed;
}
