<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\Website;

use Cms\Core\Responses\AppResponse;
use App\Http\Controllers\Controller;

class UtilitiesController extends Controller
{
    /**
     * Get current modules list with active status
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-10-25, 16:36 ICT
     */
    public function getModuleList(): mixed
    {
        //  Get modules list
        $modules = [];
        //  Using nwidart/laravel-modules functions to get modules list: mixed
        $allModules = app('modules')->all();
        foreach ($allModules as $module) {
            $modules[] = [
                'name' => $module->getName(),
                'active' => app('modules')->isEnabled($module->getName()),
            ];
        }

        return ires()->data($modules);
    }

    /**
     * Expose project list for website context (matches admin utilities endpoint).
     *
     * @author malayvuong
     * @since 2025-12-08
     */
    public function projectList(): mixed
    {
        return ires()->data(get_project_list());
    }
}
