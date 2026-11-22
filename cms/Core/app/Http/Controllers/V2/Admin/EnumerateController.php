<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Interfaces\EnumerateInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\Enumerate\StoreEnumerateRequest;
use Cms\Core\Http\Requests\Enumerate\EnumerateIndexRequest;
use Cms\Core\Http\Requests\Enumerate\UpdateEnumerateRequest;


/**
 * Manage enumerate entries for Admin v2: list, search, create/update,
 * delete, and support for linking to enumerate types for dynamic options.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2022-01-20)
 * @last_updated 8.0.0 - 2025-09-09, 00:00 (+07:00)
 */
class EnumerateController extends AdminController
{
    /**
     * Enumerate constructor
     *
     * @param EnumerateInterface $model
     *
     * @author malayvuong
     * @since  1.0.0
     */
    public function __construct(
        protected EnumerateInterface $model
    )
    {
        parent::__construct();
    }

    /**
     * Search enum.
     *
     * Filters enumerate records by optional query and type parameters.
     *
     * @return mixed JSON response containing matched records.
     *
     * @author malayvuong
     * @since 6.11.5 - 2022-03-13, 21:22
     */
    public function search()
    {
        $type = request()->input('type');

        $model = $this->model->get([
            'select' => ['id', 'name'],
            'when' => [
                [
                    request('query', '') !== '',
                    function ($q) {
                        $q->where('name', 'like', '%' . request('query') . '%');
                    },
                ],
                [
                    request('type', '') !== '',
                    function ($q) use ($type): void {
                        $q->where('type', $type);
                    },
                ],
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Delete an enumerate entry.
     *
     * @param int $id Enumerate identifier.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-20, 11:59
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
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.enumerates')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.enumerates')]));
    }

    /**
     * Update an existing enumerate record.
     *
     * @param int $id Enumerate identifier.
     * @param UpdateEnumerateRequest $request Validated request payload.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-20, 11:45
     */
    public function update(int $id, UpdateEnumerateRequest $request): mixed
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
            return ires()->error()->message(__('mgs.er.save', ['name' => __('nav.a.enumerates')]));
        }
        // Export enumerates to PHP seeder for deploy-time import
        Artisan::call('cms:export-db ' . CoreTableConstant::ENUMERATES);

        return ires()->message(__('mgs.ok.save', ['name' => __('nav.a.enumerates')]));
    }

    /**
     * Hydrate an Enumerate model instance with request data.
     *
     * @param mixed|null $model Existing model or null to create a new one.
     *
     * @return mixed The populated model instance.
     *
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
     * Create a new enumerate record.
     *
     * Persists the enumerate item and exports the enumerate table for seeding.
     *
     * @param StoreEnumerateRequest $request Validated request payload.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0 - 2022-01-20, 11:42
     * @last_updated 8.0.0 - 2025-09-06, 11:00 (+07:00)
     */
    public function store(StoreEnumerateRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.save', ['name' => __('nav.a.enumerates')]));
        }
        // Export enumerates to PHP seeder for deploy-time import
        Artisan::call('cms:export-db ' . CoreTableConstant::ENUMERATES);

        return ires()->message(__('mgs.ok.save', ['name' => __('nav.a.enumerates')]));
    }

    /**
     * Retrieve a list of enumerates.
     *
     * Applies optional type filtering and orders results by latest.
     *
     * @param  EnumerateIndexRequest $request
     * @return mixed
     *
     * @author malayvuong
     * @since 1.0.0 - 2022-01-18, 1:18
     */
    public function index(EnumerateIndexRequest $request)
    {
        $typeId = $request->input('type_id');
        $keyword = trim((string)$request->input('keyword', ''));
        $perPage = (int)$request->input('per_page', 0);
        $type = $request->input('type');

        $model = $this->model->get([
            'when' => [
                [
                    !empty($type),
                    function ($q) use ($type): void {
                        $q->where('type', $type);
                    },
                ],
                [
                    !empty($typeId),
                    function ($q) use ($typeId): void {
                        $q->where('type_id', $typeId);
                    },
                ],
                [
                    $keyword !== '',
                    function ($q) use ($keyword): void {
                        $like = '%' . $keyword . '%';
                        $q->where(function ($query) use ($like) {
                            $query->where('enum_key', 'like', $like)
                                ->orWhere('name', 'like', $like)
                                ->orWhere('name_alias', 'like', $like);
                        });
                    },
                ],
            ],
            'latest' => true,
            'page' => $perPage > 0 ? $perPage : 0,
        ]);

        return ires()->data($model);
    }

    /**
     * Generate enum files from the database.
     *
     * Runs the cms:enums:generate Artisan command with a cache lock
     * to prevent concurrent execution.
     *
     * @return mixed JSON response with command output.
     *
     * @author Agent
     * @since  8.0.0 - 2026-02-09
     */
    public function generate(): mixed
    {
        // Require update permission for enumerate generation
        if (!user()?->can('update', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Acquire cache lock to prevent concurrent execution
        $lock = Cache::lock('cms:enums:generate', 30);

        if (!$lock->get()) {
            return ires()->data([
                'success' => false,
                'message' => 'Generate is already running. Please wait.',
            ], 429);
        }

        try {
            $exitCode = Artisan::call('cms:enums:generate');
            $output = Artisan::output();

            // Trim output to safe length (50KB max)
            $output = mb_substr(trim($output), 0, 50000);

            if ($exitCode !== 0) {
                return ires()->data([
                    'success' => false,
                    'exit_code' => $exitCode,
                    'output' => $output,
                    'message' => 'Enum generation failed.',
                ], 500);
            }

            return ires()->data([
                'success' => true,
                'exit_code' => $exitCode,
                'output' => $output,
                'message' => 'Enum files generated successfully.',
            ]);
        } finally {
            $lock->release();
        }
    }
}
