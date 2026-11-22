<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Resources;

use JsonSerializable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight JSON resource for Post model data export.
 *
 * Transforms a Post model into a minimal flat representation suitable for
 * public API responses or data interchange. Uses uid instead of uuid for
 * legacy compatibility.
 *
 * Output: { uid, name, description, hashtag, content, type, video_url,
 *           parent_id, category_id, language_id, user_id, config, status,
 *           published_at }
 * Used by: Public content API endpoints
 *
 * @see \Cms\Content\Models\Post
 * @package Cms\Content\Http\Resources
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PostResource extends JsonResource
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
            'uid' => $this->uid,
            'name' => $this->name,
            'description' => $this->description,
            'hashtag' => $this->hashtag,
            'content' => $this->content,
            'type' => $this->type,
            'video_url' => $this->video_url,
            'parent_id' => $this->parent_id,
            'category_id' => $this->category_id,
            'language_id' => $this->language_id,
            'user_id' => $this->user_id,
            'config' => $this->config,
            'status' => $this->status,
            'published_at' => $this->published_at,
        ];
    }
}
