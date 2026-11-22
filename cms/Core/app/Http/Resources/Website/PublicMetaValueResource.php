<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Website;

use JsonSerializable;
use Illuminate\Http\Request;
use Cms\Core\Models\MetaValueBoolean;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a meta value for public website display with localized label.
 *
 * Output: { id, value, label }
 * Used by: PublicMetaAttributeResource
 *
 * @see \Cms\Core\Models\MetaValueBoolean
 * @package Cms\Core\Http\Resources\Website
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PublicMetaValueResource extends JsonResource
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
        $value = $this->resource->value;
        if (get_class($this->resource) === MetaValueBoolean::class) {
            $value = $this->resource->value ? 'true' : 'false';
        }

        return [
            'id' => $this->resource->id,
            'value' => $value,
            'label' => __($this->resource->label),
        ];
    }
}
