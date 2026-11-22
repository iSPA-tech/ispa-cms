<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\Language;

use JsonSerializable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a translation key record into its API representation.
 *
 * Output: { id, group_name, for_web, for_adm, for_app, name, translated, text, status, modules, is_used, counter, ...text_values }
 * Used by: Translation admin controllers
 *
 * @package Cms\Core\Http\Resources\Language
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class TranslateResource extends JsonResource
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
        $data = [
            'id' => $this->resource->id,
            'group_name' => $this->resource->group_name,
            'for_web' => $this->resource->for_web,
            'for_adm' => $this->resource->for_adm,
            'for_app' => $this->resource->for_app,
            'name' => $this->resource->name,
            'translated' => __($this->resource->name),
            'text' => $this->resource->text,
            'status' => $this->resource->status,
            'modules' => $this->resource->modules,
            'is_used' => $this->resource->is_used,
            'counter' => count($this->resource->used_in ?? []),
        ];

        if (is_array($this->resource->text)) {
            $data = [...$data, ...$this->resource->text];
        }

        return $data;
    }
}
