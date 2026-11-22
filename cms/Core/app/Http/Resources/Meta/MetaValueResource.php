<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Meta;

use JsonSerializable;
use Illuminate\Http\Request;
use Cms\Core\Models\MetaValueBoolean;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a meta value record (any type) into its API representation.
 *
 * Output: { id, value, label }
 * Used by: MetaAttributeResource, MetaValueController
 *
 * @see \Cms\Core\Models\MetaValueBoolean
 * @package Cms\Core\Http\Resources\Meta
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaValueResource extends JsonResource
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
            'value' => get_class($this->resource) === MetaValueBoolean::class ? ($this->resource->value ? 'true' : 'false') : $this->resource->value,
            'label' => __($this->resource->label),
        ];
    }
}
