<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Paginated collection of User resources.
 *
 * Output: { data, total, count, perPage, currentPage, totalPages }
 * Used by: User admin controllers
 *
 * @package Cms\Core\Http\Resources\User
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UserCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  Request $request
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'total' => $this->resource->total(),
            'count' => $this->resource->count(),
            'perPage' => $this->resource->perPage(),
            'currentPage' => $this->resource->currentPage(),
            'totalPages' => $this->resource->lastPage(),
        ];
    }
}
