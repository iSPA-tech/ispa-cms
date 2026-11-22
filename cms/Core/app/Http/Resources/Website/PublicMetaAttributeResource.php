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
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a MetaAttribute for public website display with localized labels.
 *
 * Output: { id, name, label, type, description, inputType, isRequired, isMultiple, options }
 * Used by: PublicMetadataResource, public filter/search endpoints
 *
 * @see PublicMetaValueResource
 * @package Cms\Core\Http\Resources\Website
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class PublicMetaAttributeResource extends JsonResource
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
        $mappedType = [
            'bigInt' => 'bigNumValues', 'string' => 'stringValues', 'text' => 'textValues',
            'tinyInt' => 'tinyNumValues', 'date' => 'dateValues', 'boolean' => 'booleanValues',
        ];

        $this->resource->load($mappedType[$this->resource->type]);

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'label' => __($this->resource->label),
            'type' => $this->resource->type,
            'description' => __($this->resource->description ?? $this->resource->label),
            'inputType' => $this->resource->input_type,
            'isRequired' => $this->resource->is_required,
            'isMultiple' => $this->resource->is_multiple,
            'options' => PublicMetaValueResource::collection($this->whenLoaded($mappedType[$this->resource->type])),
        ];
    }
}
