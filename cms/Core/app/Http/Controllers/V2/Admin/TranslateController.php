<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use JsonException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Eloquent\Builder;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Core\Interfaces\TranslateInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Resources\Language\TranslateCollection;
use Cms\Core\Http\Requests\Translate\StoreTranslateRequest;
use Cms\Core\Http\Requests\Translate\UpdateTranslateRequest;


/**
 * Manage translation entries for Admin v2: list, search, create/update,
 * and export/import translation resources for multiple locales.
 *
 * @see TranslateInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-15)
 * @last_updated 8.0.0 - 2025-09-09, 00:00 (+07:00)
 */
class TranslateController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param TranslateInterface $model
     * @param LanguageInterface $language
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function __construct(
        protected TranslateInterface $model,
        protected LanguageInterface  $language
    )
    {
        parent::__construct();
    }

    /**
     * Retrieve a translate entry for editing.
     *
     * @param int $id Translate identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-16, 9:16
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }
        //  Check rule

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model->name = str_replace($model->group_name . '.', '', $model->name);

        return ires()->data($model);
    }

    /**
     * Search translate keys.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 12/5/21 15:17
     */
    public function search(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'latest' => true,
            'page' => 100,
            'when' => [
                [
                    !empty(request('keywords')),
                    function ($q) {
                        return $q->where(function (Builder $f): Builder {
                            return $f->whereFulltext(['name', 'text'], json_encode(request('keywords'), JSON_THROW_ON_ERROR));
                        });
                    },
                ],
            ],
        ]);

        return ires()->data(new TranslateCollection($model));
    }

    /**
     * Generate language files from stored translations.
     *
     * @return mixed
     *
     * @throws JsonException
     *
     * @since  1.0.0
     * @author malayvuong
     */
    public function generate(): mixed
    {
        if (!user()?->can('generate', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $skipSync = request()->boolean('skip_sync');
        if (!$skipSync) {
            Artisan::call('cms:translation-sync', [
                '--all' => true,
            ]);
        }

        Artisan::call('cms:translation-generate');

        //  Call command to export db for seeder
        Artisan::call('cms:export-db ' . CoreTableConstant::TRANSLATES);

        return ires()->message(__('mgs.ok.generate-file'));
    }

    /**
     * Switch a status of resource.
     *
     * @param int $id Translate identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     */
    public function switch(int $id): mixed
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

        $model[request('col')] = request('value');

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.translate')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.translate')]));
    }

    /**
     * Display a listing of translation records.
     *
     * @return mixed
     *
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'latest' => true,
            'page' => request('limit', 30),
            'when' => [
                [
                    !empty(request('keywords')),
                    function ($q) {
                        return $q->where(function ($f) {
                            return $f->whereFulltext(['name', 'text'], json_encode(request('keywords'), JSON_THROW_ON_ERROR));
                        });
                    },
                ],
                [
                    !empty(request('type')),
                    function (Builder $q): Builder {
                        return $q->where(request('type'), true);
                    },
                ],
                [
                    request()->has('is_used') && in_array((int)request('is_used'), [0, 1], true),
                    function (Builder $q): Builder {
                        return $q->where('is_used', (int)request('is_used') > 0);
                    },
                ],
            ],
        ]);

        return ires()->data(new TranslateCollection($model));
    }

    /**
     * Store a newly created translation key/value pair.
     *
     * @param StoreTranslateRequest $request Validated request payload.
     *
     * @return mixed
     *
     * @author malayvuong
     */
    public function store(StoreTranslateRequest $request): mixed
    {
        if (!user()?->can('create', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        /** Check key name unique */
        if ($this->checkUnique()) {
            return ires()->error()->message('Key name exists');
        }

        $model = $this->fill();

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.translates')]));
        }

        Artisan::call('cms:translation-export-modules');

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.translates')]));
    }

    /**
     * Check name unique by `groupName` and `name`
     *
     * @return bool
     * @author malayvuong
     * @since 1.0.0
     */
    protected function checkUnique(): bool
    {
        $name = request('group_name') . '.' . request('name');

        $model = $this->model->findBy(['name' => $name], ['id']);

        return (bool)$model;
    }

    /**
     * Fill table
     *
     * @param null $model
     * Populate a translate model instance from request data.
     *
     * @param mixed|null $model Existing model or null to create a new one.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0
     * @last_updated 8.0.0 - 2025-09-06, 11:00 (+07:00)
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        //  Set up your model information here
        $model->fill(request()->only([
            'modules', 'group_name', 'text', 'for_web', 'for_adm', 'for_app', 'status',
        ]));
        $model->name = request('group_name') . '.' . request('name');

        return $model;
    }

    /**
     * Update the specified translation record.
     *
     * @param int $id Translate identifier.
     * @param UpdateTranslateRequest $request Validated request payload.
     *
     * @return mixed
     *
     * @author malayvuong
     */
    public function update(int $id, UpdateTranslateRequest $request): mixed
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
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.translates')]));
        }

        Artisan::call('cms:translation-export-modules');

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.translates')]));
    }
}
