<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Comment;

use App\Enums\StatusEnum;
use App\Services\Comment\CommentBannedWords;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a Comment model into its API representation.
 *
 * Output: { ...model_attributes, isPublished, isDeleted, comment_status }
 * Used by: Comment admin controllers
 *
 * @package Cms\Core\Http\Resources\Comment
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class CommentResource extends JsonResource
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
            ...$this->resource->toArray(),
            'isPublished' => $this->resource->status === StatusEnum::PUBLISHED->value,
            'isDeleted' => $this->resource->deleted_at !== null,
            'comment_status' => $this->whenLoaded('comment_status'),
            // Banned words of the comment settings found in the sender name or the text (the admin is asked before approving).
            'banned_words' => app(CommentBannedWords::class)->in($this->resource->name, $this->resource->content),
        ];
    }
}
