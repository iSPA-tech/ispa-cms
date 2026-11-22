<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Website;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a Category for public website display with recursive children.
 *
 * Output: { node, parent, name, slug, language, children }
 * Used by: Public website category endpoints, PublicPostResource
 *
 * @see PublicCategoryCollection
 * @package Cms\Core\Http\Resources\Website
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PublicCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'node' => $this->resource->id,
            'parent' => $this->resource->parent_id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'language' => $this->whenLoaded('language'),
            'children' => new PublicCategoryCollection($this->resource->children),
        ];
    }
}
