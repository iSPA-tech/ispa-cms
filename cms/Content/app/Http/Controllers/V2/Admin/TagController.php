<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Content\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Exception;
use Illuminate\Support\Facades\DB;
use Cms\Content\Interfaces\TagInterface;
use Cms\Content\Constants\ContentTableConstant as Tables;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Models\Tag;
use Cms\Content\Http\Requests\Tag\StoreTagRequest;
use Cms\Content\Http\Requests\Tag\UpdateTagRequest;
use Illuminate\Support\Facades\Log;

/**
 * Admin API controller for managing content tags.
 *
 * Provides CRUD operations for tag management including keyword search, listing,
 * creation (with soft-delete restore support), editing, updating, and deletion. Tags are addressed by uuid
 * (UUID-first admin rule) and only post tags are reachable (the repository scopes every read).
 * Extends AdminController for shared admin authentication. Auto-generates slugs
 * from tag names on creation and update.
 *
 * @see TagInterface
 * @see AdminController
 * @package Cms\Content\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class TagController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param TagInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     * @added 2024-09-06 10:35:47
     */
    public function __construct(
        protected TagInterface $model
    )
    {
        parent::__construct();
    }

    /**
     * Search
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2024-09-06, 16:03 ICT
     */
    public function search(): mixed
    {
        if ($denied = $this->denied('viewAny')) {
            return $denied;
        }

        $model = $this->model->get([
            'latest' => true,
            'page' => request('pageSize', 30),
            'where' => [
                [
                    'name', 'like', '%' . request('keywords') . '%',
                ],
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Quick Update resource.
     *
     * @param Integer $id
     * @param $res
     *
     * @return $res
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

        if (!user()?->can(str(Tables::TAGS)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Todo: Make your change here */
        if (!$model->save()) {
            return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.tags')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.tags')]));
    }

    /**
     * Switch a status of resource.
     *
     * @param Integer $id
     * @param $res
     *
     * @return $res
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

        if (!user()?->can(str(Tables::TAGS)->singular() . '.update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Todo: Make your change here */
        if (!$model->save()) {
            return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.tags')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.tags')]));
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
        if ($denied = $this->denied('viewAny')) {
            return $denied;
        }

        $model = $this->model->get([
            'latest' => true,
        ]);

        return ires()->data($model);
    }

    /**
     * Store a newly created resource in storage (a soft-deleted tag with the same slug is restored instead).
     *
     * @param StoreTagRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function store(StoreTagRequest $request): mixed
    {
        if ($denied = $this->denied('create')) {
            return $denied;
        }

        DB::beginTransaction();
        try {
            //  If old data is existing, restore it
            $old = $this->model->model()->where(['slug' => str($request->input('name'))->slug()])->onlyTrashed()->first();
            if ($old) {
                $old->restore();
            } else {
                $this->fill()->save();
            }
        } catch (Exception $e) {
            DB::rollBack();
            Log::warning('Tag create failed: ' . $e->getMessage());

            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.tags')]));
        }
        DB::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.tags')]));
    }

    /**
     * The no-permission error when the signed-in user lacks `tag.<ability>` (viewAny, create, update, delete), else null.
     * The permissions are granted per role (Roles screen); roles that could already edit posts got the same tag rights.
     *
     * @param string $ability
     *
     * @return mixed
     */
    private function denied(string $ability): mixed
    {
        return user()?->can('tag.' . $ability) ? null : ires()->error()->message(__('mgs.no.permission'));
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
            $model = new Tag();
        }

        $model->fill(request()->only($model->getFillable()));
        // Admin Content screens only ever write post tags, whatever the request says.
        $model->type = Tag::TYPE_POST;

        $model->slug = str($model->name)->slug();

        return $model;
    }

    /**
     * Display the specified resource (the same data as the edit form).
     *
     * @param string $uuid
     *
     * @return mixed
     * @author malayvuong
     */
    public function show(string $uuid): mixed
    {
        return $this->edit($uuid);
    }

    /**
     * The tag for the edit form.
     *
     * @param string $uuid
     *
     * @return mixed
     * @author malayvuong
     */
    public function edit(string $uuid): mixed
    {
        if ($denied = $this->denied('viewAny')) {
            return $denied;
        }

        $model = $this->model->reset()->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param string $uuid
     * @param UpdateTagRequest $request
     *
     * @return mixed
     * @author malayvuong
     */
    public function update(string $uuid, UpdateTagRequest $request): mixed
    {
        if ($denied = $this->denied('update')) {
            return $denied;
        }

        $model = $this->model->reset()->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!$this->fill($model)->save()) {
            return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.tags')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.tags')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param string $uuid
     *
     * @return mixed
     * @author malayvuong
     */
    public function destroy(string $uuid): mixed
    {
        if ($denied = $this->denied('delete')) {
            return $denied;
        }

        $model = $this->model->reset()->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.failed.delete', ['name' => __('nav.a.tags')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.tags')]));
    }
}
