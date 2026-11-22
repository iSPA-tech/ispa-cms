<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface as BaseRepositoryInterface;

interface CommentInterface extends BaseRepositoryInterface
{
    /**
     * Get root comment by target_id and target_type
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-29, 22:44 ICT
     */
    public function getCommentList(string $targetType, int $targetId, int $replyTo = 0, bool $freshCache = false): mixed;
}
