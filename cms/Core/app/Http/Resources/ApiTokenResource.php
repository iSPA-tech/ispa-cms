<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Resources;

use Cms\Core\Models\ApiAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One API token as the token pages and the Account sessions routes show it.
 *
 * Carries the uuid, the label, the audience (null for a legacy token), the issuing flow, the coarse network label
 * (IPv4 /24 or IPv6 /64) and the short device label, the creation, last use and expiry times, and whether this is the
 * token that carries the request. Never the plain token, the hash, the abilities or a full network address. When the
 * owner relation is loaded (Webmaster listing) the owner's uuid and name are added.
 *
 * @mixin ApiAccessToken
 * @package Cms\Core\Http\Resources
 */
class ApiTokenResource extends JsonResource
{
    /**
     * Shape the token.
     *
     * @param Request $request Current request; its token marks the row "current".
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        $data = [
            'uuid' => $this->uuid,
            'label' => $this->name,
            'audience' => $this->audience,
            'legacy' => $this->audience === null,
            'issuer' => $this->issued_via,
            'network' => $this->ip_address,
            'device' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'never_expires' => $this->expires_at === null,
            'current' => $currentId !== null && (int) $this->getKey() === (int) $currentId,
        ];

        if ($this->resource->relationLoaded('tokenable') && $this->tokenable !== null) {
            $data['user'] = ['uuid' => $this->tokenable->uuid, 'name' => $this->tokenable->name];
        }

        return $data;
    }
}
