<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Banner;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a Banner model into its API representation.
 *
 * Output: { ...model_attributes, images }
 * Used by: Banner admin controllers
 *
 * @see \App\Models\Banner
 * @package Cms\Core\Http\Resources\Banner
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class BannerResource extends JsonResource
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
            'images' => $this->whenLoaded('images'),
        ];
    }
}
