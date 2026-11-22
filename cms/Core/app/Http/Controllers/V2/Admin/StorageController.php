<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Core\Interfaces\StorageInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\Storage\StoreStorageRequest;
use Cms\Core\Http\Requests\Storage\UpdateStorageRequest;

/**
 * CRUD operations for hierarchical storage locations.
 *
 * Manages storage records with parent-child relationships, search capabilities,
 * and multi-level tree formatting for the admin UI. Extends AdminController.
 *
 * @see StorageInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class StorageController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param StorageInterface $model
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function __construct(protected StorageInterface $model)
    {
        parent::__construct();
    }

    /**
     * Search enum
     *
     * @return JsonResponse|Response
     * @author malayvuong
     *
     * @since 6.11.5 - 2022-03-13, 21:22
     */
    public function search()
    {
        $model = $this->model->get([
            'select' => ['id', 'name'],
            'when' => [
                [
                    request('query', '') !== '',
                    function ($q) {
                        $q->whereFulltext(['uid', 'code', 'name'], request('query', ''))->orWhere('name', 'like', '%' . request('query') . '%');
                    },
                ],
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Delete storage
     *
     * @param  int $id
     * @return JsonResponse|Response
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-20, 14:24
     */
    public function destroy(int $id)
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

        Db::beginTransaction();

        try {
            $model->delete();

            DB::commit();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.storages')]));
    }

    /**
     * Storage Update
     *
     * @param  int $id
     * @param  UpdateStorageRequest $request
     * @return JsonResponse|Response
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-20, 13:40
     */
    public function update(int $id, UpdateStorageRequest $request)
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

        DB::beginTransaction();

        try {
            $model->update();

            DB::commit();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.storages')]));
    }

    /**
     * Get Storage to edit
     *
     * @param  int $id
     * @return JsonResponse|Response
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-20, 14:16
     */
    public function edit(int $id)
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

        if ($model->parent_id === null) {
            $model->parent_id = 0;
        }

        return ires()->data($model);
    }

    /**
     * Store a Storage
     *
     * @param  StoreStorageRequest $request
     * @return JsonResponse|Response
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-20, 13:20
     */
    public function store(StoreStorageRequest $request)
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            $model->save();

            DB::commit();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        return ires()->message(__('mgs.ok.save', ['name' => __('nav.a.storages')]));
    }

    /**
     * Get the list
     *
     * @return JsonResponse|Response
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-01-20, 12:25
     */
    public function index()
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'order' => ['id' => 'desc'],
            'with' => [
                'storage_type:id,name',
            ],
        ]);

        return ires()->data($this->multi($model->toArray()));
    }

    /**
     * Return list by multiple level
     *-------------------
     *
     * @param array $list | array data
     * @param int|null $parentId
     * @param string $prefix | Prefix of name
     * @param array $returnArray | Array will return to client
     *
     * @return array
     *
     **@author malayvuong
     */
    protected function multi(array $list = [], int $parentId = null, string $prefix = ' ', array $returnArray = [['id' => 0, 'name' => 'ROOT']]): array
    {
        if (count($list) < 1) {
            return $returnArray;
        }

        foreach ($list as $key => $item) {
            if ($item && $item['parent_id'] === $parentId) {
                $item['mapped_name'] = $prefix . $item['name'];
                $returnArray[] = $item;
                //  Delete item from source array
                unset($list[$key]);
                //  Continue to insert item to array
                $returnArray = $this->multi($list, $item['id'], $prefix . '|-- ', $returnArray);
            }
        }

        return $returnArray;
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     *
     * @author malayvuong
     *
     * @since 5.0.0
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        /** Fill all request by $fillable */
        $model->fill(request()->only($model->getFillable()));

        if ($model->id > 0) {
        } else {
            /** Set uuid string */
            $model->uid = $this->model->checkUnique('uid', Str::random(10));
            $model->user_id = auth()->id();
        }

        if (request('parent_id', 0) === 0) {
            $model->parent_id = null;
        }

        if (request('enumerate_id', 0) === 0) {
            $model->enumerate_id = null;
        }

        if (request('established_by', 0) === 0) {
            $model->established_by = null;
        }

        return $model;
    }
}
