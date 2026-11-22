<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Upload;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform an Upload model with responsive image thumbnails and cover variants.
 *
 * Output: { id, name, path, type, size, url, dimension, thumbnails (sm/md/lg/xl), covers (sm/md/lg/xl), uploader }
 * Used by: Upload/media admin controllers
 *
 * @see \Cms\Core\Models\Upload
 * @package Cms\Core\Http\Resources\Upload
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UploadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (in_array($this->resource->type, ['image', 'avatar'], true)) {
            $appends = $this->resource->getAppends();

            $appendData = [
                'thumbnail_sm' => in_array('thumbnail_sm', $appends, true) ? $this->resource->thumbnail_sm : null,
                'thumbnail_md' => in_array('thumbnail_md', $appends, true) ? $this->resource->thumbnail_md : null,
                'thumbnail_lg' => in_array('thumbnail_lg', $appends, true) ? $this->resource->thumbnail_lg : null,
                'thumbnail_xl' => in_array('thumbnail_xl', $appends, true) ? $this->resource->thumbnail_xl : null,
                'cover_sm' => in_array('cover_sm', $appends, true) ? $this->resource->cover_sm : null,
                'cover_md' => in_array('cover_md', $appends, true) ? $this->resource->cover_md : null,
                'cover_lg' => in_array('cover_lg', $appends, true) ? $this->resource->cover_lg : null,
                'cover_xl' => in_array('cover_xl', $appends, true) ? $this->resource->cover_xl : null,
                'sm' => in_array('sm', $appends, true) ? $this->resource->sm : null,
                'md' => in_array('md', $appends, true) ? $this->resource->md : null,
                'lg' => in_array('lg', $appends, true) ? $this->resource->lg : null,
                'xl' => in_array('xl', $appends, true) ? $this->resource->xl : null,
                'xxl' => in_array('xxl', $appends, true) ? $this->resource->xxl : null,
            ];
        } else {
            $appendData = [];
        }

        $data = [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'path' => $this->resource->path,
            'type' => $this->resource->type,
            'size' => $this->resource->size,
            'alias_name' => $this->resource->alias_name,
            'created_at' => now()->parse($this->resource->created_at)->setTimezone(get_timezone())->format('Y-m-d H:i:s'),
            'pivots_count' => $this->resource->pivots_count ?? 0,
            'custom' => $this->resource->custom,
            'dimension' => "{$this->resource->dimensions['width']}x{$this->resource->dimensions['height']}",
            'uploader' => $this->whenLoaded('uploader'),
        ];

        return [
            ...$data,
            'url' => str($this->resource->url)->ltrim('/'),
            ...$appendData,
        ];
    }
}
