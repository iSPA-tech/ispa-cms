<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use App\Constants\Tables;
use App\Constants\Constant;
use App\Repositories\Interfaces\CalendarInterface;
use Cms\Core\Http\Requests\Calendar\StoreCalendarRequest;
use Cms\Core\Http\Requests\Calendar\UpdateCalendarRequest;
use Cms\Core\Http\Requests\Calendar\MultiDeleteCalendarRequest;
use Cms\Core\Http\Controllers\AdminController;

class CalendarController extends AdminController
{
    /** @var CalendarInterface $model */
    public CalendarInterface $model;

    /**
     * Controller constructor
     *
     * @param CalendarInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(CalendarInterface $model)
    {
        $this->model = $model;

        parent::__construct();
    }

    /**
     * Display a listing of the resource.
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can(str(Tables::CALENDARS)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'order' => ['id' => 'desc'],
            'with' => [
                'language:id,name,path',
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreCalendarRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function store(StoreCalendarRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.calendars')]));
        }
        //  Remove the cache

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.calendars')]));
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

        if (!user()->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Integer $id
     * @param UpdateCalendarRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function update(int $id, UpdateCalendarRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.calendars')]));
        }
        //  Remove the cache

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.calendars')]));
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

        if (!user()->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.calendars')]));
        }

        //  Remove the cache

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.calendars')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteCalendarRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteCalendarRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.calendars')]));
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
}
