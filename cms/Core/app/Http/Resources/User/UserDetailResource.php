<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Resources\User;

use App\Constants\UserType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detailed User resource with roles, permissions, settings, and audit logs.
 *
 * Output: { id, uuid, name, email, status, type, roles, permissions, settings, meta, audit_logs, statistics }
 * Used by: User detail/show admin endpoint
 *
 * @package Cms\Core\Http\Resources\User
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class UserDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * @param  Request $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        $meta = [];
        if ($this->relationLoaded('meta')) {
            $meta = meta_data_collection($this->meta);
        }

        $roles = [];
        if ($this->relationLoaded('roles')) {
            $roles = $this->roles->map(static function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'custom' => $role->custom,
                    'permissions' => $role->permissions?->map(static function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'group' => $permission->group,
                        ];
                    })->values() ?? [],
                ];
            })->values();
        }

        $settings = [];
        if ($this->relationLoaded('settings')) {
            $settings = $this->settings->map(static function ($setting) {
                return [
                    'id' => $setting->id,
                    'key' => $setting->setting_key,
                    'value' => $setting->setting_value,
                ];
            })->values();
        }

        $auditLogs = [];
        if ($this->relationLoaded('auditLogs')) {
            $auditLogs = $this->auditLogs->map(static function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'changed' => $log->changed,
                    'ip_address' => $log->ip_address,
                    'url' => $log->url,
                    'agent' => $log->agent,
                    'options' => $log->options,
                    'created_at' => optional($log->created_at)->toDateTimeString(),
                    'user' => $log->creator ? [
                        'id' => $log->creator->id,
                        'name' => $log->creator->name,
                        'email' => $log->creator->email,
                    ] : null,
                ];
            })->values();
        }

        $permissions = [];
        if ($this->relationLoaded('roles')) {
            $permissions = array_values(array_unique($this->permissions()));
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'status' => $this->status,
            'status_label' => $this->status_enum?->name ?? $this->status,
            'type' => $this->type,
            'type_label' => $this->resolveTypeLabel(),
            'level' => $this->level,
            'email_verified_at' => optional($this->email_verified_at)->toDateTimeString(),
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
            'deleted_at' => optional($this->deleted_at)->toDateTimeString(),
            'avatar' => $this->whenLoaded('avatar', function () {
                return $this->avatar ? $this->avatar->toArray() : null;
            }),
            'roles' => $roles,
            'permissions' => $permissions,
            'settings' => $settings,
            'meta' => $meta,
            'audit_logs' => $auditLogs,
            'statistics' => [
                'role_count' => $this->relationLoaded('roles') ? $this->roles->count() : null,
                'permission_count' => $this->relationLoaded('roles')
                    ? count(array_unique($this->permissions()))
                    : null,
                'setting_count' => $this->relationLoaded('settings') ? $this->settings->count() : null,
            ],
        ];
    }

    /**
     * Map the numeric user type to a human-readable label.
     *
     * @return string  Type label (Webmaster, Administrator, Client, User, or raw value)
     */
    protected function resolveTypeLabel(): string
    {
        return match ((int)($this->type ?? -1)) {
            UserType::WEBMASTER => 'Webmaster',
            UserType::ADMIN => 'Administrator',
            UserType::CLIENT => 'Client',
            UserType::USER => 'User',
            default => (string)$this->type,
        };
    }
}
