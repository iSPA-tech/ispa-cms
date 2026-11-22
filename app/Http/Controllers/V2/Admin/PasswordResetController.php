<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\PasswordReset\MultiDeletePasswordResetRequest;
use Cms\Core\Http\Requests\PasswordReset\StorePasswordResetRequest;
use Cms\Core\Http\Requests\PasswordReset\UpdatePasswordResetRequest;
use Cms\Core\Repositories\Interfaces\PasswordResetInterface;

class PasswordResetController extends AdminController
{
    public $model;

    /**
     * Controller constructor
     *
     * @param PasswordResetInterface $model
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function __construct(PasswordResetInterface $model)
    {
        $this->model = $model;

        parent::__construct();
    }

    /**
     * Quick Update resource.
     *
     * @param int $id
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

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Todo: Make your change here */
        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.password_resets')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.password_resets')]));
    }

    /**
     * Switch a status of resource.
     *
     * @param int $id
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

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Todo: Make your change here */
        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.password_resets')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.password_resets')]));
    }

    /**
     * Display a listing of the resource.
     *
     * @return mixed $res
     * @author malayvuong
     *
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

    /**
     * Show the form for creating a new resource.
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function create()
    {
        return $res;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StorePasswordResetRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function store(StorePasswordResetRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.password_resets')]));
        }

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.password_resets')]));
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     *
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
     *
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
     * @param UpdatePasswordResetRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function update(int $id, UpdatePasswordResetRequest $request): mixed
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

        $model = $this->fill($model);

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.password_resets')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.password_resets')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     *
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
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.password_resets')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.password_resets')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeletePasswordResetRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function massDestroy(MultiDeletePasswordResetRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.password_resets')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     *
     **@author malayvuong
     */
    protected function fill($model = null)
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        return $model;
    }
}
