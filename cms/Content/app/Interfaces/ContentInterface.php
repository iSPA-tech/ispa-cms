<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Interfaces;

use Illuminate\Support\Facades\Request;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Database\Eloquent\Collection;
use Cms\Core\Repositories\Interfaces\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for generic content persistence and query operations.
 *
 * Provides paginated content retrieval with dynamic filter conditions.
 * Implemented by {@see \Cms\Content\Repositories\ContentRepository}.
 *
 * @package Cms\Content\Interfaces
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 *
 * @api Content public API; signature changes follow the module notes.
 */
interface ContentInterface extends RepositoryInterface
{
    /**
     * Get contents list by target input
     *
     * @param  Request|FormRequest $request
     * @param  array $targetIds
     * @param  string $targetType
     * @return Collection|LengthAwarePaginator|array|null
     * @author vuongnm
     * @since 6.62.6 - 2023-02-08, 15:25 ICT
     */
    public function getContents(Request|FormRequest $request, array $targetIds = [], string $targetType = ''): Collection|LengthAwarePaginator|array|null;

    /**
     * Get "when" condition
     *
     * @param Request|FormRequest $request
     *
     * @return array
     * @author vuongnm
     * @since 6.62.6 - 2023-02-08, 15:10 ICT
     */
    public function getWhenCondition(Request|FormRequest $request): array;
}
