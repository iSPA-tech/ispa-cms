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
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a MetaAttribute model with its type-specific value options.
 *
 * Output: { ...model_attributes, translated_description, options }
 * Used by: MetaAttribute admin controllers, MetaClassResource
 *
 * @see MetaValueResource
 * @package Cms\Core\Http\Resources\Meta
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class MetaAttributeResource extends JsonResource
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
            ...$this->resource->toArray(),
            'translated_description' => __($this->resource->description ?? $this->resource->label),
            'options' => MetaValueResource::collection($this->whenLoaded($mappedType[$this->resource->type])),
        ];
    }
}
