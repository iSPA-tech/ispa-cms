<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Meta;

use JsonSerializable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a MetaClass model with nested attribute resources.
 *
 * Output: { id, target, name, type, parentId, categoryId, userId, group, options }
 * Used by: MetaClass admin controllers
 *
 * @see MetaAttributeResource
 * @package Cms\Core\Http\Resources\Meta
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaClassResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array|Arrayable|JsonSerializable
     */
    public function toArray(Request $request): array|JsonSerializable|Arrayable
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
            'options' => MetaAttributeResource::collection($this->whenLoaded('options')),
        ];
    }
}
