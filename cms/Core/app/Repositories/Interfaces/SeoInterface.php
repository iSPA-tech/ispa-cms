<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories\Interfaces;

/**
 * Contract for SEO metadata persistence and store operations.
 *
 * Used for: storing and managing SEO data associated with models.
 *
 * @package Cms\Core\Repositories\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
interface SeoInterface extends RepositoryInterface
{
    /**
     * Store SEO
     *
     * @param
     * @param  array|null  $data
     * @param  mixed $model
     * @return bool
     *
     * @author malayvuong
     *
     * @since 6.19.6 - 2022-06-19, 17:40 ICT
     */
    public function storeSeo($model, ?array $data = []): bool;
}
