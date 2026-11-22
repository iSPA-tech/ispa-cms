<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Resources\Post;

use DateTimeInterface;
use JsonSerializable;
use App\Enums\StatusEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON resource for a Post model in listing/detail view.
 *
 * Transforms a Post model into a response with timezone-adjusted dates,
 * publication and deletion status flags, video presence indicator, and
 * conditionally loaded relations (language, category, images, author, attachments).
 *
 * Output: { id, uuid, name, slug, config, type, status, created_at, published_at,
 *           hasVideo, image, language, isPublished, isDeleted, hasAttach, category,
 *           categories, images, author }
 * Used by: PostController::index(), PostController::show()
 *
 * @see \Cms\Content\Models\Post
 * @see \Cms\Content\Http\Controllers\V2\Admin\PostController
 * @package Cms\Content\Http\Resources\Post
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
            'id' => $this->resource->id,
            'uuid' => $this->resource->uuid,
            'name' => $this->resource->name,
            'slug' => $this->whenLoaded('slugs', $this->resource->slug),
            'config' => $this->resource->config,
            'type' => $this->resource->type,
            'status' => $this->resource->status,
            'created_at' => $this->formatDateTime($this->resource->created_at),
            'published_at' => $this->formatDateTime($this->resource->published_at),
            'hasVideo' => (bool)$this->resource->video_url,
            'image' => $this->resource->image,
            'language' => $this->whenLoaded('language'),
            'isPublished' => $this->resource->status === StatusEnum::PUBLISHED->value,
            'isDeleted' => $this->resource->deleted_at !== null,
            'hasAttach' => $this->whenLoaded('attachs', $this->resource->attachs->count() > 0),
            'category' => $this->whenLoaded('category'),
            'categories' => $this->whenLoaded('categories'),
            'images' => $this->whenLoaded('images'),
            'author' => $this->whenLoaded('author'),
            'question_name' => $this->resource->question_name,
            'question_email' => $this->resource->question_email,
            'has_answer' => $this->resource->has_answer,
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : Carbon::parse($value);

        return $date->setTimezone(get_timezone())->format('Y-m-d H:i:s');
    }
}
