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
 * Standard API representation of a Category with tree structure.
 *
 * Output: { ...model_attributes, config, isPublished, isDeleted, isLocked, parent_id, images, slugs, seos, children... }
 * Used by: Category admin list and detail endpoints
 *
 * @see \Cms\Core\Models\Category
 * @package Cms\Core\Http\Resources\Category
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CategoryResource extends JsonResource
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

        $latestSlug = $this->resource->latestSlug;

        return [
            ...$this->resource->toArray(),
            'disabled' => $this->resource->disabled ?? false, // NEED TO use for tree
            'config' => $config,
            'isPublished' => $this->resource->status === StatusEnum::PUBLISHED->value,
            'isDeleted' => !empty($this->resource->deleted_at),
            'isLocked' => $this->resource->config['locked'] ?? false,
            'parent_id' => $parentId,
            'images' => $this->whenLoaded('uploaded', new AllUploadCollection($this->resource->uploaded)),
            'slugs' => $this->whenLoaded('latestSlug', [
                'id' => $latestSlug?->id,
                'slug' => $latestSlug?->slug ?? str($this->resource->name)->slug(),
                'target_id' => $this->resource->id,
                'language_id' => $latestSlug?->language_id ?? $this->resource->language_id,
            ]),
            'seos' => $this->whenLoaded('seos'),
            'language' => $this->whenLoaded('language'),
            ...$dataChild,
        ];
    }
}
