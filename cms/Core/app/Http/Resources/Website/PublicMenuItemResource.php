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
use Cms\Core\Services\Menu\MenuItemUrlResolver;

/**
 * Transform a menu item for public website display with recursive sub-items.
 *
 * Output: { node, name, title, url, type, custom, parent, items }
 * Used by: PublicMenuResource
 *
 * @see PublicMenuItemCollection
 * @package Cms\Core\Http\Resources\Website
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PublicMenuItemResource extends JsonResource
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
            'node' => $this->resource->id ?? $this->resource->node ?? 0,
            'name' => $this->resource->name,
            'title' => $this->resource->title,
            'url' => app(MenuItemUrlResolver::class)->resolve($this->resource),
            'type' => $this->resource->type,
            'custom' => $this->resource->custom,
            'parent' => $this->resource->parent_id,
            'items' => PublicMenuItemCollection::make($this->resource->items),
        ];
    }
}
