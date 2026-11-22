<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\PermissionInterface;
use Cms\Core\Http\Requests\Permission\StorePermissionRequest;
use Cms\Core\Http\Requests\Permission\UpdatePermissionRequest;

/**
 * Manage permission definitions for Admin v2: list, create/update,
 * upsert grouped rules, and delete by group.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-15)
 * @last_updated 8.0.0 - 2025-09-09, 00:00 (+07:00)
 */
class PermissionController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param PermissionInterface $model
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function __construct(
        protected PermissionInterface $model
    )
    {
        parent::__construct();
    }

    /**
     * Delete by group name
     *
     * @param string $group
     *
     * @return mixed
     * @author malayvuong
     * @since  1.0.0
     */
    public function groupDelete(string $group): mixed
    {
        if (!$group) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $deleted = $this->model->deleteBy([
            'group' => $group,
        ]);

        return ires()->data($deleted)->message(__('mgs.ok.delete', ['name' => __('nav.a.permission')]));
    }

    /**
     * Display a listing of the resource.
     *
     * @return mixed
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $data = $this->model->get([
            'order' => [
                'group' => 'ASC',
                'name' => 'ASC',
            ],
        ]);

        return ires()->data([
            'data' => $data,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StorePermissionRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function store(StorePermissionRequest $request): mixed
    {
        if (!user()?->can('create', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $save = [];
        if (count($request->get('rules')) > 0) {
            foreach ($request->get('rules') as $item) {
                if (!$this->check($request->get('group'), $item['key'])) {
                    $save[] = [
                        'name' => $item['key'],
                        'title' => $item['text'],
                        'group' => $request->get('group'),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }
        if (count($request->get('addedRules')) > 0) {
            foreach ($request->get('addedRules') as $item) {
                if (isset($item['key'], $item['text']) && !$this->check($request->get('group'), $item['key'])) {
                    $save[] = [
                        'name' => $item['key'],
                        'title' => $item['text'],
                        'group' => $request->get('group'),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }

        return ires()->data(
            $this->model->upsert($save, ['name', 'group'], ['updated_at', 'title'])
        )->message(__('mgs.ok.create', ['name' => __('nav.a.permission')]));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param string $group
     * @param UpdatePermissionRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function update(string $group, UpdatePermissionRequest $request): mixed
    {
        if (!$group) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        /** Step 0: Delete all data by group */
        $this->model->deleteBy([
            'group' => $request->get('group'),
        ]);

        /** Step 1: Get all data with Trashed */
        $all = $this->model->get([
            'where' => [
                'group' => $request->get('group'),
            ],
            'withTrashed' => true,
        ]);
        $tempData = collect($all);
        /** Step 2: compare all value */
        $rules = $tempRules = collect($request->get('rules'))->concat($request->get('addedRules'));
        foreach ($all as $key => $value) {
            //  Find key is exists in $rules
            $isExists = $rules->first(function ($val, $xKey) use ($value, $tempRules): bool {
                if (($val['key'] ?? $val['name'] ?? null) === $value->name) {
                    $tempRules->forget($xKey);
                    //  Restore this rule if it exists and deleted
                    if ($value->deleted_at !== null) {
                        $value->restore();
                    }
                    if ($value->title !== $val['text']) {
                        $value->title = $val['text'];
                        $value->save();
                    }

                    return true;
                }

                return false;
            });

            if ($isExists) {
                //  Remove item form tempData if it exists
                $tempData->forget($key);
            }
        }

        $save = [];
        if ($tempRules && $tempRules->count() > 0) {
            foreach ($tempRules->toArray() as $item) {
                if (isset($item['key']) && !$this->check($request->get('group'), $item['key'])) {
                    $save[] = [
                        'name' => $item['key'],
                        'title' => $item['text'] ?? $item['title'],
                        'group' => $request->get('group'),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }

        return ires()->data(
            $this->model->upsert($save, ['name', 'group'], ['updated_at', 'title'])
        )->message(__('mgs.ok.update', ['name' => __('nav.a.permission')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
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

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.permission')]));
        }

        return ires()->data()->message(__('mgs.ok.delete', ['name' => __('nav.a.permission')]));
    }

    /**
     * Check group & key
     *
     * @param string $group
     * @param string $name
     *
     * @return bool
     * @author malayvuong
     * @since  1.0.0
     */
    protected function check(string $group, string $name): bool
    {
        return (bool)$this->model->reset()->model()->where([
            'name' => $name,
            'group' => $group,
        ])->select('id')->first();
    }
}
