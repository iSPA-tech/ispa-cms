<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Cms\Core\Constants\CoreTableConstant as Tables;
use Cms\Core\Http\Controllers\AdminController;
use App\Repositories\Interfaces\SchedulerInterface;
use Cms\Core\Http\Requests\Scheduler\StoreSchedulerRequest;
use Cms\Core\Http\Requests\Scheduler\UpdateSchedulerRequest;
use Cms\Core\Http\Requests\Scheduler\MultiDeleteSchedulerRequest;

class SchedulerController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param SchedulerInterface $model
     *
     * @author malayvuong
     * @since 7.160.1175
     * @added 2025-02-25 20:27:53
     */
    public function __construct(
        protected SchedulerInterface $model
    )
    {
        parent::__construct();
    }

    /**
     * Quick Update resource.
     *
     * @param Integer $id
     *
     * @return mixed $res
     *
     * @author malayvuong
     */
    public function quickUpdate(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can(str(Tables::SCHEDULER)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Todo: Make your change here */
        if (!$model->save()) {
            return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.schedulers')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.schedulers')]));
    }

    /**
     * Switch a status of resource.
     *
     * @param Integer $id
     *
     * @return mixed $res
     *
     * @author malayvuong
     */
    public function switch(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can(str(Tables::SCHEDULER)->singular() . '.update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Todo: Make your change here */
        if (!$model->save()) {
            return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.schedulers')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.schedulers')]));
    }

    /**
     * Display a listing of the resource.
     *
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can(str(Tables::SCHEDULER)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([

        'order' => ['id' => 'desc'],
        ]);

        return ires()->data($model);
    }

    /**
     * Show the form for creating a new resource.
     *
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function create()
    {
        return $res;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreSchedulerRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function store(StoreSchedulerRequest $request): mixed
    {
        if (!user()?->can(str(Tables::SCHEDULER)->singular() . '.create')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();
        try {
            $model = $this->fill();

            $model->save();
        } catch (Exception $e) {
            Log::debug('SchedulerController@store: ' . $e->getMessage());
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.schedulers')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return Mixed $model
     *
     * @author malayvuong
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
     * Display the specified resource.
     *
     * @param Integer $id
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function show(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        return $res;
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return mixed $res
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

        if (!user()?->can(str(Tables::SCHEDULER)->singular() . '.update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Integer $id
     * @param UpdateSchedulerRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function update(int $id, UpdateSchedulerRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can(str(Tables::SCHEDULER)->singular() . '.update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.schedulers')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.schedulers')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Integer $id
     *
     * @return mixed $res
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

        if (!user()?->can(str(Tables::SCHEDULER)->singular() . '.delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.failed.delete', ['name' => __('nav.a.schedulers')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.schedulers')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteSchedulerRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteSchedulerRequest $request): mixed
    {
        if (!user()?->can(str(Tables::SCHEDULER)->singular() . '.delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.schedulers')]));
    }


}
