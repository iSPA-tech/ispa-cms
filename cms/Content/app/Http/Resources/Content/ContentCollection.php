<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Resources\Content;

use JsonSerializable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Paginated resource collection for content entries.
 *
 * Transforms a paginated Content model collection into a structured response
 * with pagination metadata.
 *
 * Output: { data, total, count, perPage, currentPage, totalPages }
 * Used by: ContentController::index()
 *
 * @see \Cms\Content\Models\Content
 * @see \Cms\Content\Http\Controllers\V2\Admin\ContentController::index()
 * @package Cms\Content\Http\Resources\Content
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ContentCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param Request $request
     *
     * @return array|Arrayable|JsonSerializable
     */
    public function toArray(Request $request): array|JsonSerializable|Arrayable
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
