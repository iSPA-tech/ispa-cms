<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;


use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\MetaClassInterface;
use Cms\Core\Http\Resources\Meta\MetaClassCollection;
use Cms\Core\Http\Resources\Meta\MetaClassEditResource;
use Cms\Core\Http\Requests\MetaClass\StoreMetaClassRequest;
use Cms\Core\Http\Requests\MetaClass\UpdateMetaClassRequest;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Manage meta classes for Admin v2: list, create with attribute sync,
 * edit/update, bulk delete, and trigger export of data where appropriate.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0 (2022-01-15)
 * @last_updated 8.0.0 - 2025-09-11, 14:40 (+07:00)
 */
class MetaClassController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param MetaClassInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected MetaClassInterface $model
    )
    {
        parent::__construct();
    }

    /**
     * Display a listing of the resource.
     *
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can(str(CoreTableConstant::META_CLASSES)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'where' => [
                'type' => 'class',
            ],
            'with' => [
                // group.options: nested display groups carry their own
                // attributes — without the dot-load the group nodes serialize
                // with no options at all (whenLoaded miss).
                'group.options' => fn($query) => $query->orderBy('is_required', 'desc'),
                'options' => fn($query) => $query->orderBy('is_required', 'desc'),
            ],
            'page' => request('perPage', 20),
            'when' => [
                [
                    !empty(request('target')),
                    fn($q) => $q->whereTarget(request('target')),
                ],
            ],
        ]);

        return ires()->data(new MetaClassCollection($model));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreMetaClassRequest $request
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function store(StoreMetaClassRequest $request): mixed
    {
        $model = $this->fill();
        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();
        try {
            $model->save();

            //  Sync attributes list to metaClass
            if (!empty($request->input('options', []))) {
                $model->options()->sync($request->input('options', []));
            }
            //  Export new data to seeder sql file
        } catch (Exception $e) {
            Log::debug('MetaClassController@store: ' . $e->getMessage());
            DB::rollBack();
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.meta_classes')]));
        }

        DB::commit();
        //  Export new data to seeder sql file
        Artisan::call('cms:export-db ' . $model->getTable());
        Artisan::call('cms:export-db ' . CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT);

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.meta_classes')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return Mixed $model
     *
     **@author malayvuong
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        return $model;
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id, ['*'], ['options:id']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('meta_class.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(new MetaClassEditResource($model));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param UpdateMetaClassRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function update(int $id, UpdateMetaClassRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if ($model->resource_key !== null) {
            return ires()->error()->message(__('Manage this field from the resource screen.'));
        }

        if (!user()?->can('meta_class.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        DB::beginTransaction();
        try {
            $model->save();

            //  Sync attributes list to metaClass
            if (!empty($request->input('options', []))) {
                $model->options()->sync($request->input('options', []));
            }
        } catch (Exception $e) {
            Log::debug('MetaClassController@update: ' . $e);
            DB::rollBack();
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.meta_classes')]));
        }

        DB::commit();
        //  Export new data to seeder sql file
        Artisan::call('cms:export-db ' . $model->getTable());
        Artisan::call('cms:export-db ' . CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT);

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.meta_classes')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Integer $id
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if ($model->resource_key !== null) {
            return ires()->error()->message(__('Manage this field from the resource screen.'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.meta_classes')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.meta_classes')]));
    }
}
