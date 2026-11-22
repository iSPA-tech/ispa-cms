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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Core\Traits\Controllers\SearchTrait;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\LanguageInterface;
use Cms\Core\Http\Requests\Langugae\StoreLanguageRequest;
use Cms\Core\Http\Requests\Langugae\UpdateLanguageRequest;

/**
 * Manage application languages for Admin v2: search, list with filters,
 * toggle visibility/flags, and full CRUD operations including soft-deletes
 * where applicable.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-15)
 * @last_updated 8.0.0 - 2025-09-09, 00:00 (+07:00)
 */
class LanguageController extends AdminController
{
    use SearchTrait;

    private const PLATFORM_BY_SWITCH_COLUMN = [
        'for_web' => 'web',
        'for_adm' => 'adm',
        'for_app' => 'app',
        'default_web' => 'web',
        'default_adm' => 'adm',
        'default_app' => 'app',
    ];

    /**
     * Controller constructor
     *
     * @param LanguageInterface $model
     *
     * @author phien
     * @since 1.0.0
     */
    public function __construct(protected LanguageInterface $model)
    {
        parent::__construct();
    }

    /**
     * Search for languages.
     *
     * Applies a simple name filter to retrieve matching languages.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 6.11.5 - 2022-03-13, 16:13
     */
    public function search()
    {
        $model = $this->model->get([
            'select' => ['id', 'name'],
            'when' => [
                [
                    request('query', '') !== '',
                    function ($q): void {
                        $q->where('name', 'like', '%' . request('query') . '%');
                    },
                ],
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * List the flag images that a language can reference.
     *
     * Reads `public/images/flags/4x3` and returns each file with its public
     * URL. The listing is cached for one hour.
     *
     * @return mixed
     */
    public function flags(): mixed
    {
        $flags = Cache::remember('language_flag_files', 3600, static function (): array {
            $dir = public_path('images/flags/4x3');
            $files = is_dir($dir) ? glob($dir . '/*.{svg,png}', GLOB_BRACE) : [];
            sort($files);

            return array_map(static fn (string $path): array => [
                'file' => basename($path),
                'url' => '/images/flags/4x3/' . basename($path),
            ], $files ?: []);
        });

        return ires()->data($flags);
    }

    /**
     * Display a paginated list of languages.
     *
     * @return mixed
     *
     * @author phien
     * @since 1.0.0 - 2022-01-15, 20:0
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($this->model->get([
            'order' => ['id' => 'desc'],
            'withTrashed' => true,
            'page' => request('pageSize', 20),
            'when' => [
                [
                    request('query') !== null,
                    function ($q) {
                        return $q->where('name', 'like', '%' . request('query') . '%');
                    },
                ],
            ],
        ]));
    }

    /**
     * Toggle a boolean column on the language model.
     *
     * @param int $id Language identifier.
     * @param string $column Column name to switch.
     *
     * @return mixed
     *
     * @author phien
     */
    public function switch(int $id, string $column): mixed
    {
        if ($id < 1 || !isset(self::PLATFORM_BY_SWITCH_COLUMN[$column])) {
            return ires()->error()->message('Please provide data.');
        }

        request()->validate([
            'value' => ['required', 'boolean'],
        ]);

        $language = $this->model->find($id);

        if (!$language) {
            return ires()->error()->message('Language not found');
        }
        /** Check rules before process to delete */
        if (!user()?->can('update', $language)) {
            return ires()->error()->message('No Permission');
        }

        $platform = self::PLATFORM_BY_SWITCH_COLUMN[$column];
        $value = request()->boolean('value');

        if (str_starts_with($column, 'default_') && $value && !$language['for_' . $platform]) {
            return ires()->error()->message('Enable platform usage before setting its default language.');
        }

        $language[$column] = $value;

        try {
            DB::transaction(function () use ($language): void {
                $this->saveWithDefaultInvariant($language);
            });
        } catch (Exception $e) {
            Log::debug($e);

            return ires()->error()->message('Cannot update');
        }

        //  Remove default language cache
        cache()->forget('default_language');

        return ires()->data($language->fresh())->message('Update success');
    }

    /**
     * Update language information.
     *
     * @param int $id Language identifier.
     * @param UpdatelanguageRequest $request Validated request data.
     *
     * @return mixed
     *
     * @author phien
     * @since 1.0.0 - 2022-01-17, 21:17
     */
    public function update(int $id, UpdatelanguageRequest $request): mixed
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

        try {
            DB::transaction(function () use ($model): void {
                $this->saveWithDefaultInvariant($model);
            });
            //  Remove default language
            cache()->forget('default_language');
        } catch (Exception $e) {
            Log::debug($e);

            return ires()->error()->message($e->getMessage());
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.languages')]));
    }

    /**
     * Retrieve language data for editing.
     *
     * @param int $id Language identifier.
     *
     * @return mixed
     *
     * @author phien
     * @since 1.0.0 - 2022-01-17, 21:9
     */
    public function edit(int $id): mixed
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

        return ires()->data($model);
    }

    /**
     * Store a newly created language.
     *
     * @param StorelanguageRequest $request Validated request payload.
     *
     * @return mixed
     *
     * @author phien
     * @since 1.0.0 - 2022-01-17, 19:24
     */
    public function store(StorelanguageRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }
        try {
            DB::transaction(function () use ($model): void {
                $this->saveWithDefaultInvariant($model);
            });
            //  Remove default language
            cache()->forget('default_language');
        } catch (Exception $e) {
            Log::debug($e);

            return ires()->error()->message($e->getMessage());
        }

        return ires()->data($model)->message(__('mgs.ok.save', ['name' => __('nav.a.languages')]))->code(201);
    }

    /**
     * Keep platform usage and default flags consistent for every write path.
     */
    private function saveWithDefaultInvariant(Model $model): void
    {
        foreach (['web', 'adm', 'app'] as $platform) {
            $usageColumn = 'for_' . $platform;
            $defaultColumn = 'default_' . $platform;

            if (!$model[$usageColumn]) {
                $model[$defaultColumn] = false;
            }

            if (!$model[$defaultColumn]) {
                continue;
            }

            $query = $this->model->model()->where($defaultColumn, true);

            if ($model->getKey()) {
                $query->where('id', '<>', $model->getKey());
            }

            $query->update([$defaultColumn => false]);
        }

        $model->saveOrFail();
    }

    /**
     * Hydrate a Language model instance with request data.
     *
     * @param mixed|null $model Existing model or null to create a new one.
     *
     * @return mixed
     *
     * @author phien
     * @since 1.0.0 - 2022-01-23, 2:51
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }
        $input = request()->only($model->getFillable());
        // Languages store bare status keys; the form requests accept the `status_` prefixed form too.
        if (isset($input['status']) && is_string($input['status'])) {
            $input['status'] = preg_replace('/^status_/', '', $input['status']);
        }
        $model->fill($input);

        return $model;
    }

    /**
     * Delete a language.
     *
     * @param int $id Language identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-25, 14:15
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->find($id, ['id']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        Db::beginTransaction();

        try {
            $model->delete();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.languages')]));
    }

    /**
     * Restore a soft-deleted language record.
     *
     * @param int $id Language identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0 - 2022-01-25, 14:15
     * @last_updated 8.0.0 - 2025-09-06, 11:00 (+07:00)
     */
    public function restore(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.id.404'));
        }

        $model = $this->model->model()->withTrashed()->where('id', $id)->first();

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('restore', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->restore()) {
            return ires()->error()->message(__('mgs.failed.restore', ['name' => __('nav.a.languages')]));
        }

        return ires()->message(__('mgs.ok.restore', ['name' => __('nav.a.languages')]));
    }
}
