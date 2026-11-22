<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Resources\Content;

use App\Enums\ContentStatusEnum;
use JsonSerializable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON resource for a single Content model with related data.
 *
 * Transforms a Content model into an enriched response including custom metadata,
 * publication/deletion status flags, conditional language/status/target relations,
 * and recursively expanded child content entries for Vietnamese (language_id=1) parent records.
 *
 * Output: { ...model_attributes, custom, content, has_video, isPublished, isDeleted,
 *           audios, author, language, content_status, target, expand }
 * Used by: ContentController::show(), ContentController::edit()
 *
 * @see \Cms\Content\Models\Content
 * @see \Cms\Content\Http\Controllers\V2\Admin\ContentController
 * @package Cms\Content\Http\Resources\Content
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ContentResource extends JsonResource
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
        $children = null;
        if ($this->resource->language_id && $this->resource->language_id === 1) {
            $contents = $this->resource->contents();

            if (!empty($contents) && is_countable($contents)) {
                foreach ($contents as $content) {
                    $children[] = new ContentResource($content);
                }
            }
        }

        return [
            ...$this->resource->toArray(),
            'custom' => $this->resource->custom ?? [
                    'name' => 'none',
                    'description' => 'none',
                    'content' => 'none',
                    'audio' => 'none',
                ],
            'content' => $this->resource->content ?? [
                    [
                        'type' => 'text', 'value' => null,
                    ],
                ],

            'has_video' => !empty($this->resource->video_url),
            'isPublished' => $this->resource->status === ContentStatusEnum::CONTENT_PUBLISHED->value,
            'isDeleted' => !is_null($this->resource->deleted_at),
            'audios' => $this->whenLoaded('audios'),
            'author' => $this->whenLoaded('author'),
            'language' => $this->whenLoaded('language', function () {
                return [
                    'id' => $this->resource->language->id,
                    'name' => $this->resource->language->name,
                    'path' => $this->resource->language->path,
                    'flag_url' => url('images/flags/4x3/' . $this->resource->language->flag),
                ];
            }),
            'content_status' => $this->whenLoaded('contentStatus', function () {
                return [
                    'id' => $this->resource->contentStatus->id,
                    'name' => lang('path', 'vi') === 'vi' ? $this->resource->contentStatus->name : $this->resource->contentStatus->name_alias ?? $this->resource->contentStatus->name,
                ];
            }),
            'target' => $this->whenLoaded('target'),
            'expand' => $children,
        ];
    }
}
