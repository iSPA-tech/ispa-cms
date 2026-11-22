<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Database\Seeders;

use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Illuminate\Database\Seeder;

class SignInAsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::query()->updateOrCreate(
            [
                'group' => 'user',
                'name' => 'signInAs',
            ],
            [
                'title' => 'Sign In As | Đăng nhập giả',
            ],
        );

        $webmaster = Role::query()->where('name', 'Webmaster')->first();
        if (!$webmaster) {
            return;
        }

        $webmaster->permissions()->syncWithoutDetaching([$permission->id]);
    }
}
