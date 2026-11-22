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
 * Contract for AuditLog repository operations.
 *
 * @extends RepositoryInterface
 * @package Cms\Core\Interfaces
 */
interface AuditLogInterface extends RepositoryInterface
{

    /**
     * Get audit log by userId
     *
     * @param int $userId
     * @param int $page
     * @param int $pageSize
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-24, 21:24 ICT
     */
    public function getAuditLogListByUserId(int $userId, int $page, int $pageSize, bool $freshCache = false): mixed;
}
