<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Cms\Core\Traits\Controllers\SearchTrait;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\RoleInterface;
use Cms\Core\Http\Requests\Role\StoreRoleRequest;
use Cms\Core\Http\Requests\Role\UpdateRoleRequest;

/**
 * Manage roles for Admin v2, including listing, creating/updating
 * role metadata, syncing permissions, and deleting roles with
 * appropriate access checks.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-17)
 * @last_updated 8.0.0 - 2025-09-09, 00:00 (+07:00)
 */
class RoleController extends AdminController
{
    use SearchTrait;

    /**
     * Controller constructor
     *
     * @param RoleInterface $model
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function __construct(protected RoleInterface $model)
    {

        parent::__construct();
    }

    /**
     * Delete a role
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 9:50
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message('deleted');
    }

    /**
     * Store a role
     *
     * @param StoreRoleRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 9:29
     */
    public function store(StoreRoleRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($model->custom['level'] < user('level', 99)) {
            return ires()->error()->message(__('m.auth.level_not_meet', ['level' => user('level', 99)]));
        }

        if (!$model->save()) {
        }

        $this->sync($model);

        return ires()->data($model)->message('success');
    }

    /**
     * Update role
     *
     * @param int $id
     * @param UpdateRoleRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 8:54
     */
    public function update(int $id, UpdateRoleRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        if (!$model->save()) {
        }

        $this->sync($model);

        return ires()->data($model)->message('Save success');
    }

    /**
     * Get edit
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 8:48
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id, ['*'], ['permissions']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model);
    }

    /**
     * Get custom roles
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->data([]);
        }

        $model = $this->model->get([
            'with' => ['permissions:id,group,name,title'],
            'page' => request('limit', 30),
            'latest' => true,
            'when' => [
                [
                    user('level', 99) > 0,
                    function ($query): void {
                        $query->where('custom->level', '>=', $this->getLevel());
                    },
                ],
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Get user level
     *
     * @return int
     *
     * @author malayvuong
     * @since  1.0.0
     */
    protected function getLevel(): int
    {
        $level = 99;
        foreach (user()->roles()->get() as $role) {
            if (isset($role->custom['level']) && (int)$role->custom['level'] < $level) {
                $level = $role->custom['level'];
            }
        }

        return $level;
    }

    /**
     * Fill form
     *
     * @param mixed|null $model
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 8:57
     */
    protected function fill($model = null)
    {
        if (!$model) {
            $model = $this->model->model();
        }

        /** Fill all request by $fillable */
        $model->fill(request()->only($model->getFillable()));
        /** Set user creator */
        if ((!$model->id || $model->id < 1) && $model->user_id < 1 && in_array('user_id', $model->getFillable(), true)) {
            $model->user_id = auth()->id();
        }

        return $model;
    }

    /**
     * Sync permission
     *
     * @param mixed $model
     *
     * @return void
     * @author malayvuong
     * @since 1.0.0 - 2022-01-17, 9:31
     */
    protected function sync($model): void
    {
        if (request('permissions')) {
            $permissionArray = collect();
            foreach (request('permissions', []) as $group) {
                if (is_array($group)) {
                    $permissionArray = $permissionArray->concat($group);
                }
            }
            $model->permissions()->sync($permissionArray);
        }
    }

    /**
     * GET /api/v2/admin/role/{id}/permissions
     *
     * Returns the currently assigned permission IDs and keys for a role.
     * Used by the permission editor to initialize the selected state.
     *
     * @param int $id
     * @return mixed
     * @author malayvuong
     * @since 8.0.0 (2026-03-17)
     */
    public function getPermissions(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id, ['id'], ['permissions:id,name,group']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $permissions = $model->permissions;

        return ires()->data([
            'permission_ids' => $permissions->pluck('id')->values()->all(),
            'permission_keys' => $permissions->map(fn($p) => $p->group . '.' . $p->name)->values()->all(),
        ]);
    }

    /**
     * PUT /api/v2/admin/role/{id}/permissions
     *
     * Syncs the role's permissions via the pivot table.
     * Expects: { permission_ids: [1, 2, 3, ...] }
     *
     * @param int $id
     * @return mixed
     * @author malayvuong
     * @since 8.0.0 (2026-03-17)
     */
    public function syncPermissions(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $permissionIds = request()->input('permission_ids', []);

        if (!is_array($permissionIds)) {
            return ires()->error()->message('permission_ids must be an array');
        }

        // Validate all permission IDs exist
        $existingCount = \Cms\Core\Models\Permission::whereIn('id', $permissionIds)->count();
        if ($existingCount !== count($permissionIds)) {
            return ires()->error()->message('Some permission IDs are invalid');
        }

        // Sync within transaction
        \Illuminate\Support\Facades\DB::transaction(function () use ($model, $permissionIds) {
            $model->permissions()->sync($permissionIds);
        });

        return ires()->data([
            'synced_count' => count($permissionIds),
        ])->message('Permissions synced successfully');
    }
}
