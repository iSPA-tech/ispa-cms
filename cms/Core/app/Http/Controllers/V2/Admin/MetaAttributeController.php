<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;


use ErrorException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Resources\Meta\MetaAttributeResource;
use Cms\Core\Interfaces\MetaAttributeInterface;
use Cms\Core\Http\Resources\Meta\MetaAttributeCollection;
use Cms\Core\Http\Requests\MetaAttribute\StoreMetaAttributeRequest;
use Cms\Core\Http\Requests\MetaAttribute\UpdateMetaAttributeRequest;
use Cms\Core\Constants\CoreTableConstant;

/**
 * Manage meta attributes for Admin v2: search, list, show, create,
 * update, and delete. Supports export actions through Artisan where needed.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0 (2022-01-15)
 * @last_updated 8.0.0 - 2025-09-11, 14:40 (+07:00)
 */
class MetaAttributeController extends AdminController
{
//    use SearchTrait;

    /**
     * Controller constructor
     *
     * @param MetaAttributeInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected MetaAttributeInterface $model
    )
    {
        parent::__construct();
    }

    /**
     * Search
     *
     * @param  Request $request
     * @return mixed
     * @author malayvuong
     * @since  - 2024-06-14, 20:51 ICT
     */
    public function search(Request $request): mixed
    {
        $model = $this->model->model()->select('*')
            ->where('name', 'like', '%' . request('keywords') . '%')
            ->get();

        return ires()->data($model);
    }

    /**
     * Show detail of the meta attribute
     *
     * @param  int $id
     * @return mixed
     * @author vuongnm
     * @since 7.160.1175 - 2023-09-29, 23:37 ICT
     */
    public function show(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id, ['*']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('view', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(new MetaAttributeResource($model));
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

        $model = $this->model->get([
            'page' => request('perPage', 20),
            'latest' => true,
            'filter' => request()->except('custom'),
            'when' => [
                [
                    in_array((string) request('custom'), ['0', '1'], true),
                    function ($query): void {
                        $query->where('is_custom', request('custom') === '1');
                    },
                ],
                [
                    !empty(request('select')),
                    function ($query): void {
                        $columns = explode(',', request('select'));
                        $select = ['type'];
                        foreach ($columns as $column) {
                            if (in_array($column, $this->model->model()->getFillable(), true)) {
                                $select[] = $column;
                            }
                        }
                        $query->select($select);
                    },
                ],
            ],
        ]);

        return ires()->data(new MetaAttributeCollection($model));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreMetaAttributeRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function store(StoreMetaAttributeRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();
        try {
            $model->save();

            if (!empty($request->input('options', []))) {
                $options = [];
                foreach ($request->input('options', []) as $option) {
                    $options[] = [
                        'value_type' => get_value_class($model->type),
                        'value_id' => $option['id'],
                    ];
                }
                //  Sync meta value
                $model->options()->sync($options);
            }
        } catch (ErrorException $ex) {
            DB::rollBack();
            Log::error($ex->getMessage());
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.meta_attributes')]));
        }
        DB::commit();
        //  Export new data to seeder sql file
        $this->exportDatabase();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.meta_attributes')]));
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
     * Export database to sql file
     *
     * @author malayvuong
     * @since 6.62.6 - 2023-02-04, 21:57 ICT
     */
    protected function exportDatabase(): void
    {
        //  Export new data to seeder sql file
        foreach (
            [
                CoreTableConstant::META_ATTRIBUTES,
                CoreTableConstant::META_VALUES_BIG_NUM, CoreTableConstant::META_VALUES_BOOLEAN, CoreTableConstant::META_VALUES_DATE,
                CoreTableConstant::META_VALUES_TINY_NUM, CoreTableConstant::META_VALUES_RANGE,
                CoreTableConstant::META_VALUES_STRING, CoreTableConstant::META_VALUES_TEXT,
                CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT, CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT,
            ] as $table) {
            Artisan::call('cms:export-db ' . $table);
        }
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

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param UpdateMetaAttributeRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function update(int $id, UpdateMetaAttributeRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if ($model->is_custom) {
            return ires()->error()->message(__('Manage this field from the resource screen.'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        DB::beginTransaction();

        try {
            $model->save();

            if (!empty($request->input('options', []))) {
                $options = [];
                foreach ($request->input('options', []) as $option) {
                    $options[] = [
                        'value_type' => get_value_class($model->type),
                        'value_id' => $option['id'],
                    ];
                }
                //  Sync meta value
                $model->options()->sync($options);
            }
        } catch (ErrorException $ex) {
            DB::rollBack();
            Log::error($ex->getMessage());
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.meta_attributes')]));
        }
        DB::commit();
        //  Export new data to seeder sql file
        $this->exportDatabase();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.meta_attributes')]));
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
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if ($model->is_custom) {
            return ires()->error()->message(__('Manage this field from the resource screen.'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.meta_attributes')]));
        }
        //  Export new data to seeder sql file
        $this->exportDatabase();

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.meta_attributes')]));
    }
}
