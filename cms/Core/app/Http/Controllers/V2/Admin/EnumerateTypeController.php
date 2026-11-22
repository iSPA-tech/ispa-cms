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
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\EnumerateTypeInterface;
use Cms\Core\Http\Requests\EnumerateType\StoreEnumerateTypeRequest;

/**
 * Manage enumerate types for Admin v2: list, create/update, and delete
 * base types used to categorize or constrain enumerate entries.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-20)
 * @last_updated 8.0.0 - 2025-09-09, 00:00 (+07:00)
 */
class EnumerateTypeController extends AdminController
{
    protected EnumerateTypeInterface $model;

    /**
     * Enumerate constructor
     *
     * @param EnumerateTypeInterface $model
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function __construct(EnumerateTypeInterface $model)
    {
        $this->model = $model;

        parent::__construct();
    }

    /**
     * Store Enumerate type
     *
     * @param StoreEnumerateTypeRequest $request
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-23, 1:38
     */
    public function store(StoreEnumerateTypeRequest $request): mixed
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
        //  Call command to export db for seeder
        Artisan::call('cms:export-db ' . CoreTableConstant::ENUMERATE_TYPES);

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.enumerates_types')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     * @author malayvuong
     * @since 5.0.0
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        /** Fill all request by $fillable */
        $model->fill(request()->only($model->getFillable()));

        return $model;
    }

    /**
     * Delete item
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-20, 10:2
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
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.enumerates_types')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.enumerates_types')]));
    }

    /**
     * Update Enumerate type
     *
     * @param int $id
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-20, 9:50
     */
    public function update(int $id): mixed
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
            return ires()->error()->message(__('mgs.er.save', ['name' => __('nav.a.enumerates_types')]));
        }
        //  Call command to export db for seeder
        Artisan::call('cms:export-db ' . CoreTableConstant::ENUMERATE_TYPES);

        return ires()->message(__('mgs.ok.save', ['name' => __('nav.a.enumerates_types')]));
    }

    /**
     * Get the list
     *
     * @return mixed
     * @author malayvuong
     * @since 1.0.0 - 2022-01-20, 1:38
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
        'order' => ['id' => 'desc'],
        ]);

        return ires()->data($model);
    }
}
