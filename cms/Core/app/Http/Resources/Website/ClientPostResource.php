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
 * Transform a post model for client-facing (authenticated user) API responses.
 *
 * Output: { uuid, name, description, image, config, slug, status, createdAt, updatedAt, publishedAt, category, language, hasVideo, totalView }
 * Used by: Client post endpoints
 *
 * @package Cms\Core\Http\Resources\Website
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ClientPostResource extends JsonResource
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
            'description' => $this->resource->description,
            'image' => $this->whenLoaded('image', fn() => $this->resource->image->thumbnail_sm),
            'config' => $this->resource->config,
            'slug' => $this->resource->slug,
            'status' => $this->resource->status,
            'createdAt' => now()->parse($this->resource->created_at)->setTimezone(get_timezone())->format('Y-m-d H:i:s'),
            'updatedAt' => now()->parse($this->resource->updated_at)->setTimezone(get_timezone())->format('Y-m-d H:i:s'),
            'publishedAt' => now()->parse($this->resource->published_at)->setTimezone(get_timezone())->format('Y-m-d H:i:s'),
            'category' => $this->whenLoaded('category', fn() => $this->resource->category->name),
            'language' => $this->whenLoaded('language', fn() => $this->resource->language->name),
            'hasVideo' => $this->resource->video_url !== null,
            'totalView' => $this->resource->total_view,
        ];
    }
}
