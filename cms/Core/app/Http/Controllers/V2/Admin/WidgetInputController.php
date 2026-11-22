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
use App\Contracts\Widget\WidgetMutationGuard;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Interfaces\WidgetInputInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\WidgetInput\StoreWidgetInputRequest;
use Cms\Core\Http\Requests\WidgetInput\UpdateWidgetInputRequest;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Widget;
use Cms\Core\Http\Controllers\V2\Admin\Concerns\RefusesHubWidgetEdits;
use Cms\Core\Services\Widget\WidgetDefinitionSyncService;

/**
 * CRUD operations for widget input parameter definitions.
 *
 * Manages the configurable input fields that belong to a widget,
 * including their types, default values, and options.
 * Extends AdminController.
 *
 * @see WidgetInputInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class WidgetInputController extends AdminController
{
    use RefusesHubWidgetEdits;

    /**
     * Controller constructor
     *
     * @param WidgetInputInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     * @added 2025-03-16 21:17:53
     */
    public function __construct(
        protected WidgetInputInterface $model,
        protected WidgetDefinitionSyncService $widgetSync,
        protected WidgetMutationGuard $mutationGuard,
    )
    {
        parent::__construct();
    }

    /**
     * Chunk 1 Task 7 contract: consult the Core-owned WidgetMutationGuard
     * before mutating or deleting a single legacy row. See
     * WidgetController::isMutable() for the full rationale — duplicated
     * here (not shared via AdminController) because that base class is
     * out of this task's allowed scope. widget_inputs rows are never
     * trust-gated (frozen contract: inputs are non-executable), but they
     * still go through the same safe-import lock marker check as
     * widgets/widget_themes.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-07-17)
     */
    protected function isMutable(string $table, int $id): bool
    {
        return $this->mutationGuard->permitsMutation($table, $id);
    }

    /**
     * Input values are substituted at render time, not baked into generated
     * Blade files, so an Input mutation never itself changes any project's
     * artifact bytes. Sync still runs (export must reflect the new input
     * row and the retry contract must stay uniform across Widget/Theme/
     * Input mutations); the affected-project list is the owning Widget's
     * current Theme applicability, which the no-duplicate-retry dedup in
     * LegacyWidgetArtifactSetPublisher turns into a safe no-op rebuild.
     *
     * @return array<int, string>
     */
    private function affectedProjectsForWidget(int $widgetId): array
    {
        $names = [];
        foreach (WidgetTheme::query()->where('widget_id', $widgetId)->get() as $theme) {
            $projectNames = $theme->project_name;
            if (!is_array($projectNames)) {
                $projectNames = !empty($projectNames) ? [$projectNames] : ['Default'];
            }
            foreach ($projectNames as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Display a listing of the resource.
     *
     * @param int $widgetId
     *
     * @return $res
     * @author malayvuong
     */
    public function index(int $widgetId = 0): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET_INPUT)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'latest' => true,
            'when' => [
                [
                    $widgetId > 0,
                    function ($query) use ($widgetId): void {
                        $query->where('widget_id', $widgetId);
                    },
                ],
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * Store a newly created resource in storage.
     *
     * Reached only through the widget-scoped route; the submitted widget_id
     * must match the {widgetId} route segment so an input can never be
     * created under a widget other than the one addressed by the URL.
     *
     * @param int $widgetId
     * @param StoreWidgetInputRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function store(int $widgetId, StoreWidgetInputRequest $request): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET_INPUT)->singular() . '.create')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Hub widgets are read-only on a site: change them through the widget save, which clones them.
        $hubConflict = $this->hubReadonlyConflict(Widget::query()->find($widgetId));
        if ($hubConflict !== null) {
            return $hubConflict;
        }

        if ($request->integer('widget_id') !== $widgetId) {
            return ires()->error()->code(422)->message('Input không thuộc Widget đang xử lý.');
        }

        DB::beginTransaction();
        try {
            $model = $this->fill();

            $model->save();
        } catch (Exception $e) {
            Log::debug('WidgetInputController@store: ' . $e->getMessage());
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        $syncResult = $this->widgetSync->sync($this->affectedProjectsForWidget($widgetId));

        return ires()
            ->data(array_merge(['id' => $model->id], $syncResult->toArray()))
            ->message(__('mgs.ok.create', ['name' => __('nav.a.widget_inputs')]));
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
     * Show the form for editing the specified resource.
     *
     * Scoped by the owning widget: the input is resolved by BOTH widget_id
     * and id so a child of another widget can never be read through this URL.
     *
     * @param int $widgetId
     * @param int $id
     *
     * @return $res
     * @author malayvuong
     */
    public function edit(int $widgetId, int $id): mixed
    {
        if ($widgetId < 1 || $id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->model()->where([
            'widget_id' => $widgetId,
            'id' => $id,
        ])->first();

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can(str(CoreTableConstant::WIDGET_INPUT)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * Scoped by the owning widget: the input is resolved by BOTH widget_id
     * and id so cross-widget mutation is impossible through this URL.
     *
     * @param int $widgetId
     * @param int $id
     * @param UpdateWidgetInputRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function update(int $widgetId, int $id, UpdateWidgetInputRequest $request): mixed
    {
        if ($widgetId < 1 || $id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->model()->where([
            'widget_id' => $widgetId,
            'id' => $id,
        ])->first();

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can(str(CoreTableConstant::WIDGET_INPUT)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Hub widgets are read-only on a site: change them through the widget save, which clones them.
        $hubConflict = $this->hubReadonlyConflict(Widget::query()->find($widgetId));
        if ($hubConflict !== null) {
            return $hubConflict;
        }

        if (!$this->isMutable(CoreTableConstant::WIDGET_INPUT, $model->id)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.widget_inputs')]));
        }

        $syncResult = $this->widgetSync->sync($this->affectedProjectsForWidget($widgetId));

        return ires()
            ->data(array_merge(['id' => $model->id], $syncResult->toArray()))
            ->message(__('mgs.ok.update', ['name' => __('nav.a.widget_inputs')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * Scoped by the owning widget: the input is resolved by BOTH widget_id
     * and id so cross-widget deletion is impossible through this URL.
     *
     * @param int $widgetId
     * @param int $id
     *
     * @return $res
     * @author malayvuong
     */
    public function destroy(int $widgetId, int $id): mixed
    {
        if ($widgetId < 1 || $id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->model()->where([
            'widget_id' => $widgetId,
            'id' => $id,
        ])->first();

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can(str(CoreTableConstant::WIDGET_INPUT)->singular() . '.delete')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Hub widgets are read-only on a site: change them through the widget save, which clones them.
        $hubConflict = $this->hubReadonlyConflict(Widget::query()->find($widgetId));
        if ($hubConflict !== null) {
            return $hubConflict;
        }

        if (!$this->isMutable(CoreTableConstant::WIDGET_INPUT, $model->id)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $affectedProjects = $this->affectedProjectsForWidget($widgetId);
        $deletedId = $model->id;

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.failed.delete', ['name' => __('nav.a.widget_inputs')]));
        }

        $syncResult = $this->widgetSync->sync($affectedProjects);

        return ires()
            ->data(array_merge(['id' => $deletedId], $syncResult->toArray()))
            ->message(__('mgs.ok.delete', ['name' => __('nav.a.widget_inputs')]));
    }
}
