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
 * Transform a post model for public website display.
 *
 * Output: { uuid, name, seoTitle, description, content, slug, category, image, language, publishedAt }
 * Used by: Public website post detail endpoints
 *
 * @see PublicCategoryResource
 * @see PublicImageResource
 * @package Cms\Core\Http\Resources\Website
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PublicPostResource extends JsonResource
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
            'uuid' => $this->resource->uuid,
            'name' => $this->resource->name,
            'seoTitle' => $this->whenLoaded(
                'seo',
                fn() => ($this->resource->seos->title ?? $this->resource->name) . ' - ' . config('app.name')
            ),
            'description' => $this->resource->description,
            'content' => $this->resource->content,
            'slug' => $this->resource->slug,
            'category' => $this->whenLoaded('category', fn() => new PublicCategoryResource($this->resource->category)),
            'image' => new PublicImageResource($this->resource->image),
            'language' => $this->whenLoaded('language', fn() => $this->resource->language->name),
            'publishedAt' => now()->parse($this->resource->published_at)->setTimezone(get_timezone())->format('Y-m-d H:i:s'),
        ];
    }
}
