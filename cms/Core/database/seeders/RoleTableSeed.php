<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Cms\Core\Models\Role;
use Illuminate\Database\Seeder;
use Cms\Core\Models\Permission;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RoleTableSeed extends Seeder
{
    /**
     * Set by `cms:modules-sync` while it runs the module seeders. The Core pass then only ensures the
     * Webmaster role exists; the command grants every permission once, after all modules created theirs.
     */
    public static bool $deferPermissionGrant = false;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $role = Role::query()->where('name', 'Webmaster')->first();

        if ($role) {
            Log::info("Role exists, skipping create: Webmaster");
        } else {
            $role = Role::query()->create([
                'name' => 'Webmaster',
                'custom' => [
                    'textColor' => '#DC2626',
                    'badge' => 'danger',
                    'icon' => null,
                    'image' => null,
                    'level' => 0,
                ],
            ]);
        }

        if (self::$deferPermissionGrant) {
            Log::info('Webmaster permission grant deferred to the end of cms:modules-sync');

            return;
        }

        self::grantAllPermissions($role);
    }

    /**
     * Give the Webmaster role every permission that exists.
     *
     * @param Role|null $role The Webmaster role; looked up by name when omitted.
     * @return int|null Number of permissions granted, or null when there is no Webmaster role or permission schema.
     */
    public static function grantAllPermissions(?Role $role = null): ?int
    {
        if (!Schema::hasTable((new Role())->getTable()) || !Schema::hasTable((new Permission())->getTable())) {
            return null;
        }

        $role ??= Role::query()->where('name', 'Webmaster')->first();

        if (!$role) {
            return null;
        }

        $permissionIds = Permission::query()->pluck('id')->all();
        $role->permissions()->sync($permissionIds);

        return count($permissionIds);
    }
}
