<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Illuminate\Http\Request;
use Cms\Core\Models\Permission;
use Cms\Core\Services\Permissions\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Cms\Core\Responses\AppResponse;
use Cms\Core\Http\Controllers\AdminController;

/**
 * Lazy-loading catalog endpoints for permissions.
 * Designed to scale to thousands of permissions by returning paginated, filtered results.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   8.0.0 (2026-03-17)
 */
class PermissionCatalogController extends AdminController
{
    /**
     * GET /api/v2/admin/permissions/modules
     *
     * Returns a list of modules (derived from permission.group prefix) with permission counts.
     * Used by the frontend to populate the left sidebar of the permission editor.
     *
     * @return AppResponse
     */
    public function modules(): AppResponse
    {
        $modules = Permission::query()
            ->select('group')
            ->whereNotNull('group')
            ->get()
            ->map(static fn (Permission $permission) => PermissionCatalog::moduleOf($permission->group))
            ->filter()
            ->countBy()
            ->sortKeys()
            ->map(static fn (int $count, string $module) => [
                'module' => $module,
                'count' => $count,
            ])
            ->values();

        return ires()->data($modules);
    }

    /**
     * GET /api/v2/admin/permissions/catalog
     *
     * Returns paginated permissions for a specific module with optional search.
     *
     * Query params:
     *   - module (required): filter by module prefix in group
     *   - search (optional): filter by key/title/group/name
     *   - page (optional, default 1)
     *   - per_page (optional, default 200, max 500)
     *
     * @param Request $request
     *
     * @return AppResponse
     */
    public function catalog(Request $request): AppResponse
    {
        $module = $request->input('module');
        $search = $request->input('search');
        $page = max(1, (int)$request->input('page', 1));
        $perPage = min(500, max(1, (int)$request->input('per_page', 200)));
        $wrappedGroup = DB::connection()->getQueryGrammar()->wrap('group');

        $query = Permission::query()
            ->select(['id', 'name', 'group', 'title'])
            ->when($module, function ($q) use ($module, $wrappedGroup) {
                // Match permissions where group starts with "module." or group equals "module"
                // Use case-insensitive matching
                $lowerModule = strtolower($module);
                $q->where(function ($sub) use ($lowerModule, $wrappedGroup) {
                    $sub->whereRaw("LOWER({$wrappedGroup}) = ?", [$lowerModule])
                        ->orWhereRaw("LOWER({$wrappedGroup}) LIKE ?", [$lowerModule . '.%']);
                });
            })
            ->when($search, function ($q) use ($search) {
                $term = '%' . $search . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('group', 'like', $term)
                        ->orWhere('title', 'like', $term);
                });
            })
            ->orderBy('group')
            ->orderBy('name');

        $total = $query->count();
        $items = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'key' => $p->key, // accessor: group.name
                'name' => $p->name,
                'group' => $p->group,
                'title' => $p->title,
            ]);

        return ires()->data([
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int)ceil($total / $perPage),
            ],
        ]);
    }
}
