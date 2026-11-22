<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\User;

use JsonException;
use JsonSerializable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a User model for edit forms with meta, avatar, and roles.
 *
 * Output: { ...model_attributes, meta, avatar, roles (IDs) }
 * Used by: User edit endpoint
 *
 * @package Cms\Core\Http\Resources\User
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UserEditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array|Arrayable|JsonSerializable
     * @throws JsonException
     */
    public function toArray(Request $request): array|JsonSerializable|Arrayable
    {
        //  Set meta data
        $meta = (object)[];
        if ($this->whenLoaded('meta')) {
            $meta = meta_data_collection($this->resource->meta);
        }

        return [
            ...$this->resource->toArray(),
            'meta' => $meta,
            'avatar' => $this->whenLoaded('avatar', fn() => $this->avatar ? $this->avatar->toArray() : null),
            'roles' => $this->whenLoaded('roles', function () {
                return $this->resource->roles->pluck('id');
            }),
        ];
    }
}
