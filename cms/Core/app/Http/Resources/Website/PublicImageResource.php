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
 * Transform an Upload model into public-facing thumbnail and cover URLs.
 *
 * Output: { name, size, thumbnailSm, thumbnailMd, thumbnailLg, thumbnailXl, coverSm, coverMd, coverLg, coverXl }
 * Used by: PublicPostResource, public website image endpoints
 *
 * @see \Cms\Core\Models\Upload
 * @package Cms\Core\Http\Resources\Website
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PublicImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource->thumbnail_xl) {
            $largeThumbnail = $this->resource->thumbnail_xl;
        }
        if ($this->resource->cover_xl) {
            $largeCover = $this->resource->cover_xl;
        }

        return [
            'name' => $this->resource->name,
            'size' => $this->resource->size,
            'thumbnailSm' => $this->resource->thumbnail_sm,
            'thumbnailMd' => $this->resource->thumbnail_md,
            'thumbnailLg' => $this->resource->thumbnail_lg,
            'thumbnailXl' => $largeThumbnail ?? null,
            'coverSm' => $this->resource->cover_sm,
            'coverMd' => $this->resource->cover_md,
            'coverLg' => $this->resource->cover_lg,
            'coverXl' => $largeCover ?? null,
        ];
    }
}
