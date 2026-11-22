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
 * Transform a MetaClass for public website display with nested attribute options.
 *
 * Output: { id, target, name, type, parentId, categoryId, userId, group, options }
 * Used by: Public website metadata endpoints
 *
 * @see PublicMetaAttributeResource
 * @package Cms\Core\Http\Resources\Website
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PublicMetadataResource extends JsonResource
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
            'id' => $this->resource->id,
            'target' => $this->resource->target,
            'name' => __($this->resource->name),
            'type' => $this->resource->type,
            'parentId' => $this->resource->parent_id,
            'categoryId' => $this->resource->category_id,
            'userId' => $this->resource->user_id,
            'group' => self::collection($this->whenLoaded('group')),
            'options' => PublicMetaAttributeResource::collection($this->whenLoaded('options')),
        ];
    }
}
