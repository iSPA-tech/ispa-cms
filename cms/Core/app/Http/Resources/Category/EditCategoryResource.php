<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Category;

use Illuminate\Http\Request;
use Cms\Core\Models\Category;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Enums\StatusEnum;
use Cms\Core\Http\Resources\Upload\AllUploadCollection;

/**
 * Category resource for edit forms, including all sibling categories.
 *
 * Output: { ...model_attributes, config, parent_id, images, slugs, seos, all-categories }
 * Used by: Category edit endpoints
 *
 * @see \Cms\Core\Models\Category
 * @package Cms\Core\Http\Resources\Category
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class EditCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
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
            'seos' => $this->whenLoaded('seos', []),
            'language' => $this->whenLoaded('language'),
            ...$dataChild,
            'all-categories' => $this->parseCategoryData(
                $this->allCategories($this->resource->type),
                $this->resource->id
            ),
        ];
    }

    /**
     * Recursively mark categories as disabled to prevent circular parent selection.
     *
     * @param  array  $data     Category tree data
     * @param  int    $id       Current category ID to disable
     * @param  array  $disable  Accumulated disabled IDs
     * @return array  Category tree with disabled flags set
     */
    public function parseCategoryData($data = [], $id = 0, $disable = [])
    {
        $disable = [$id, ...$disable];
        if ($data && count($data) > 0) {
            foreach ($data as &$value) {
                if ($value['id'] > 0) {
                    if (
                        (isset($value['parent_id']) &&
                            $value['parent_id'] > 0 &&
                            in_array($value['parent_id'], $disable, true)) ||
                        $value['id'] === $id
                    ) {
                        $value['disabled'] = true;
                        $disable[] = $value['id'];
                    }

                    if (isset($value['children']) && count($value['children']) > 0) {
                        $value['children'] = $this->parseCategoryData($value['children'], $id, $disable);
                    }
                }
            }
        }

        return $data;
    }

    protected function allCategories($type = 0): CategoryCollection
    {
        $categories = Category::query()->select('*')->whereType($type)->latest()->get();

        //  multiple level
        $categories = multiLevel($categories);

        return new CategoryCollection($categories);
    }
}
