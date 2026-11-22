<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\User;

use JsonSerializable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform a User model into its admin list representation.
 *
 * Output: { id, uuid, username, name, email, mobile, type, level, status, isDeleted, roles, meta }
 * Used by: User admin list endpoints
 *
 * @package Cms\Core\Http\Resources\User
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UserResource extends JsonResource
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
            'uuid' => $this->resource->uuid,
            'username' => $this->resource->username,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'mobile' => $this->resource->mobile,
            'emailVerifiedAt' => $this->resource->email_verified_at,
            'type' => $this->resource->type,
            'level' => $this->resource->level,
            'parentId' => $this->resource->parent_id,
            'status' => $this->resource->status,
            'isDeleted' => !empty($this->resource->deleted_at),
            'roles' => $this->whenLoaded('roles'),
            'meta' => $this->whenLoaded('meta', function () {
                $meta = [];
                foreach ($this->resource->meta as $metaClass) {
                    $metaClass = [
                        'id' => $metaClass->meta_class_id,
                        'attribute_id' => $metaClass->meta_attribute_id,
                        'value_id' => $metaClass->value_id,
                        'attribute_name' => $metaClass->attribute->name,
                        'type' => $metaClass->attribute->type,
                        'value' => [
                            'id' => $metaClass->value->id,
                            'label' => __($metaClass->value->label),
                            'value' => (string)$metaClass->value->value,
                        ],
                    ];

                    $meta[] = $metaClass;
                }

                return $meta;
            }),
        ];
    }
}
