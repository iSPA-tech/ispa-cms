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
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Resources\Meta\MetaValueCollection;
use Cms\Core\Interfaces\MetaValueTextInterface;
use Cms\Core\Interfaces\MetaValueDateInterface;
use Cms\Core\Interfaces\MetaValueBigNumInterface;
use Cms\Core\Interfaces\MetaValueStringInterface;
use Cms\Core\Http\Requests\MetaValue\StoreMetaValueRequest;
use Cms\Core\Interfaces\MetaValueTinyNumInterface;
use Cms\Core\Interfaces\MetaValueBooleanInterface;
use Cms\Core\Http\Requests\MetaValueBigNum\UpdateMetaValueBigNumRequest;
use Cms\Core\Http\Requests\MetaValueBigNum\MultiDeleteMetaValueBigNumRequest;

/**
 * Unified CRUD operations for all meta value types.
 *
 * Delegates to the appropriate type-specific repository (bigInt, string,
 * text, tinyInt, date, boolean) based on the request type parameter.
 * Exports database backups after create and update operations.
 * Extends AdminController.
 *
 * @see MetaValueStringInterface
 * @see MetaValueBigNumInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class MetaValueController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param MetaValueBigNumInterface $bigInt
     * @param MetaValueStringInterface $string
     * @param MetaValueTextInterface $text
     * @param MetaValueTinyNumInterface $tinyInt
     * @param MetaValueDateInterface $date
     * @param MetaValueBooleanInterface $boolean
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected MetaValueBigNumInterface  $bigInt,
        protected MetaValueStringInterface  $string,
        protected MetaValueTextInterface    $text,
        protected MetaValueTinyNumInterface $tinyInt,
        protected MetaValueDateInterface    $date,
        protected MetaValueBooleanInterface $boolean
    )
    {
        parent::__construct();
    }

    /**
     * Display a listing of the resource.
     *
     * @return mixed
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can('meta_attribute.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $allowedType = ['bigInt', 'string', 'text', 'tinyInt', 'date', 'boolean'];
        $type = request('type', 'string');

        $getArray = [
            'page' => request('per_page', 20),
            'when' => [
                [
                    request('query') !== null,
                    function ($query): void {
                        $query->where('value', 'like', '%' . request('query') . '%');
                    },
                ],
            ],
        ];

        $type = in_array($type, $allowedType, true) ? $type : 'string';

        // Hide pooled values referenced only by custom fields; values shared
        // with a system attribute or not assigned anywhere stay visible.
        $getArray['when'][] = [
            true,
            function ($query) use ($type): void {
                $this->hideCustomOnlyValues($query, get_value_class($type));
            },
        ];

        $data = $this->{$type}->get($getArray);

        return ires()->data(new MetaValueCollection($data));
    }

    /**
     * Exclude value rows that only custom attributes reference in meta_data.
     *
     * A row stays visible when it has no meta_data link at all, or when at
     * least one non-custom attribute links it.
     *
     * @param mixed $query Eloquent builder over a value table.
     * @param string $valueClass Value model class of the listed type.
     * @return void
     */
    private function hideCustomOnlyValues(mixed $query, string $valueClass): void
    {
        $valueModel = new $valueClass();
        $valueKey = $valueModel->getTable() . '.' . $valueModel->getKeyName();
        $link = CoreTableConstant::META_DATA;
        $attributes = CoreTableConstant::META_ATTRIBUTES;

        $references = function (bool $custom) use ($valueKey, $valueClass, $link, $attributes) {
            return function ($sub) use ($custom, $valueKey, $valueClass, $link, $attributes): void {
                $sub->select(DB::raw('1'))
                    ->from($link)
                    ->join($attributes, $attributes . '.id', '=', $link . '.meta_attribute_id')
                    ->where($link . '.value_type', $valueClass)
                    ->whereColumn($link . '.value_id', $valueKey)
                    ->where($attributes . '.is_custom', $custom);
            };
        };

        $query->where(function ($q) use ($references): void {
            $q->whereNotExists($references(true))
                ->orWhereExists($references(false));
        });
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreMetaValueRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function store(StoreMetaValueRequest $request): mixed
    {
        if (!user()?->can('meta_attribute.create')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }
        $model = $this->fill();

        DB::beginTransaction();
        try {
            $model->save();
        } catch (Exception $e) {
            Log::debug('MetaValueController@store: ' . $e->getMessage());
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();
        //  Export db to migration seeder
        Artisan::call('cms:export-db ' . $model->getTable());

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.meta_values')]));
    }

    /**
     * Fill data to Model Eloquent
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
            if ($model = $this->getModel(request('type', 'string'))) {
                $model = $model->model();
            } else {
                $model = $this->string->model();
            }
        }

        $model->fill(request()->only($model->getFillable()));

        return $model;
    }

    /**
     * Get Model interface by request('type')
     *
     * @param string $type
     *
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2022-11-17, 19:32 ICT
     */
    protected function getModel(string $type): mixed
    {
        return $this->{$type} ?? null;
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

        $model = $this->getModel(request('type', 'string'))->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        //  Replace type of model by request type
        $model->type = request('type', 'string');

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param UpdateMetaValueBigNumRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function update(int $id, UpdateMetaValueBigNumRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->getModel($request->input('type', 'string'))->model()->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();
        $model = $this->fill($model);

        try {
            $model->save();
        } catch (Exception $e) {
            Log::debug('MetaValueController@update: ' . $e->getMessage());
            DB::rollBack();
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.meta_values')]));
        }

        DB::commit();

        //  Export db to migration seeder
        Artisan::call('cms:export-db ' . $model->getTable());

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.meta_values')]));
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

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.meta_values')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.meta_values')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteMetaValueBigNumRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteMetaValueBigNumRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.meta_values')]));
    }
}
