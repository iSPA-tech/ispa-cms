<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Category;

use JsonSerializable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\StatusEnum;
use Cms\Core\Http\Resources\Upload\AllUploadCollection;

/**
 * Legacy API representation of a Category with tree structure.
 *
 * Output: { node, uid, name, slug, featured, status, priority, config, parent_id, images, slugs, seos, children... }
 * Used by: Legacy category endpoints
 *
 * @see \Cms\Core\Models\Category
 * @package Cms\Core\Http\Resources\Category
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CategoryLegacyResource extends JsonResource
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
        $allParent = $this->resource->getParentsAttribute();
        $parentId = [];
        foreach ($allParent as $parent) {
            $parentId[] = $parent['id'];
        }

        $dataChild = [];

        if ($this->whenLoaded('children') && count($this->resource->children) > 0) {
            $dataChild['children'] = self::collection($this->resource->children);
        }

        $config = $this->resource->config;
        if (!isset($config['priority'])) {
            $config['priority'] = 0;
        }

        return [
            'node' => $this->resource->id,
            'uid' => $this->resource->uid,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'featured' => $this->resource->featured,
            'status' => $this->resource->status,
            'priority' => $this->resource->priority,
            'disabled' => $this->resource->disabled ?? false, // NEED TO use for tree
            'config' => $config,
            'isPublished' => $this->resource->status === StatusEnum::PUBLISHED->value,
            'isDeleted' => !empty($this->resource->deleted_at),
            'isLocked' => $this->resource->config['locked'] ?? false,
            'parent_id' => $parentId,
            'language_id' => $this->resource->language_id,
            'type' => $this->resource->type,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
            'image' => $this->resource->image,
            'images' => $this->whenLoaded('uploaded', new AllUploadCollection($this->resource->uploaded)),
            'slugs' => $this->whenLoaded('slugs'),
            'seos' => $this->whenLoaded('seos'),
            'language' => $this->whenLoaded('language'),
            ...$dataChild,
        ];
    }
}
