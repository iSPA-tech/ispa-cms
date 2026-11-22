<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;


use Illuminate\Support\Facades\Artisan;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\EmailTemplateInterface;
use Cms\Core\Http\Requests\EmailTemplate\StoreEmailTemplateRequest;
use Cms\Core\Http\Requests\EmailTemplate\UpdateEmailTemplateRequest;
use Cms\Core\Http\Requests\EmailTemplate\MultiDeleteEmailTemplateRequest;
use Cms\Core\Constants\CoreTableConstant;

/**
 * CRUD operations for email templates in the admin panel.
 *
 * Manages reusable email templates grouped by key and language.
 * Automatically synchronizes templates to a backup SQL file after
 * each create or update. Extends AdminController.
 *
 * @see EmailTemplateInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class EmailTemplateController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param EmailTemplateInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(protected EmailTemplateInterface $model)
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
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'when' => [
                [
                    !empty(request('group', null)),
                    function ($q): void {
                        $q->where('group', request('group'));
                    },
                ],
            ],
            'latest' => true,
            'page' => request('limit', 20),
            'with' => [
                'language:id,name,flag',
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreEmailTemplateRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function store(StoreEmailTemplateRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        //  Check if the group + key_name already exists
        $exists = $this->model->findBy([
            'group' => $model->group,
            'key_name' => $model->key_name,
        ]);
        if ($exists) {
            return ires()->error()->message(__('mgs.er.unique', ['name' => __('fields.key_name') . ' + ' . __('fields.group')]));
        }

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.email_templates')]));
        }

        $this->synchronizeSettingFile();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.email_templates')]));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return $res
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
     * @param Integer $id
     * @param UpdateEmailTemplateRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function update(int $id, UpdateEmailTemplateRequest $request): mixed
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
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.email_templates')]));
        }

        $this->synchronizeSettingFile();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.email_templates')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Integer $id
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

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.email_templates')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.email_templates')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteEmailTemplateRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteEmailTemplateRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.email_templates')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return null|mixed $model
     *
     **@author malayvuong
     */
    protected function fill($model = null)
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        //  Make description from content by stripping HTML tags and limit to 200 characters
        $description = strip_tags($model->content);
        $model->description = strlen($description) > 200 ? substr($description, 0, 197) . '...' : $description;

        return $model;
    }

    /**
     * Sync template sql for backup
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-08-27, 11:03 ICT
     */

    protected function synchronizeSettingFile(): void
    {
        Artisan::call('cms:backup-email-template');

        //  Call command to export db for seeder
        Artisan::call('cms:export-db ' . CoreTableConstant::EMAIL_TEMPLATES);
    }
}
