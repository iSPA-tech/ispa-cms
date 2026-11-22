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
use Cms\Core\Interfaces\WidgetThemeInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\WidgetTheme\StoreWidgetThemeRequest;
use Cms\Core\Http\Requests\WidgetTheme\UpdateWidgetThemeRequest;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Widget;
use Cms\Core\Http\Controllers\V2\Admin\Concerns\RefusesHubWidgetEdits;
use Cms\Core\Services\Widget\WidgetDefinitionSyncService;
use Cms\Core\Services\Widget\WidgetTrustAuthorizer;

/**
 * CRUD operations for widget theme/template variants.
 *
 * Manages theme templates associated with widgets, including their
 * content, CSS, and project-name scoping. Extends AdminController.
 *
 * @see WidgetThemeInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class WidgetThemeController extends AdminController
{
    use RefusesHubWidgetEdits;

    /**
     * Controller constructor
     *
     * @param WidgetThemeInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     * @added 2025-03-16 21:17:58
     */
    public function __construct(
        protected WidgetThemeInterface $model,
        protected WidgetDefinitionSyncService $widgetSync,
        protected WidgetMutationGuard $mutationGuard,
        protected WidgetTrustAuthorizer $trustAuthorizer,
    )
    {
        parent::__construct();
    }

    /**
     * Chunk 1 Task 7 contract: consult the Core-owned WidgetMutationGuard
     * before mutating or deleting a single legacy row. See
     * WidgetController::isMutable() for the full rationale — duplicated
     * here (not shared via AdminController) because that base class is
     * out of this task's allowed scope.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-07-17)
     */
    protected function isMutable(string $table, int $id): bool
    {
        return $this->mutationGuard->permitsMutation($table, $id);
    }

    /**
     * Task 16: a typed 409 with an actionable usage summary, returned
     * BEFORE any destructive mutation is attempted, when the legacy row
     * identified by ($table, $id) is referenced by any retained cms/Widget
     * Instance Version. Returns null when no such reference exists. See
     * WidgetController::retainedReferenceConflict() for the full rationale
     * (duplicated here rather than shared via AdminController, matching
     * this class's own established isMutable() duplication precedent).
     *
     * @return mixed A ready-to-return typed 409 AppResponse, or null.
     */
    protected function retainedReferenceConflict(string $table, int $id, string $errorCode): mixed
    {
        $summary = $this->mutationGuard->usageSummaryFor($table, $id);

        if ($summary === null) {
            return null;
        }

        return ires()->error()
            ->code(409)
            ->errorCode($errorCode)
            ->message('This item cannot be deleted because it is referenced by retained Widget Instance configuration.')
            ->data([
                'table' => $summary->table,
                'id' => $summary->id,
                'retained_version_count' => $summary->retainedVersionCount,
                'usage_fingerprint' => $summary->usageFingerprint,
            ]);
    }

    /**
     * Task 16 rider: refuse live-HTTP destructive Theme mutation during
     * the managed-disable side's exclusive maintenance window. See
     * WidgetController::maintenanceWindowConflict() for the full
     * adjudicated complementary-windows rationale (duplicated here, not
     * shared via AdminController, matching this class's own established
     * isMutable() duplication precedent).
     *
     * @return mixed A ready-to-return typed 409 AppResponse, or null.
     */
    protected function maintenanceWindowConflict(): mixed
    {
        if (!app()->runningInConsole() && app()->isDownForMaintenance()) {
            return ires()->error()
                ->code(409)
                ->errorCode('WIDGET_MODULE_IN_USE')
                ->message('A module maintenance transition is in progress; destructive widget changes are refused until it completes.');
        }

        return null;
    }

    /**
     * Expand a theme's project_name into the distinct project names its
     * generated artifact applies to (legacy generator fan-out semantics: a
     * scalar or missing project_name normalizes to Default; an explicit
     * array, including an empty one, is used as-is).
     *
     * @return array<int, string>
     */
    private function expandProjectNames(mixed $projectNames): array
    {
        if (!is_array($projectNames)) {
            return !empty($projectNames) ? [$projectNames] : ['Default'];
        }

        return $projectNames;
    }

    /**
     * Display a listing of the resource.
     *
     * @param int $widgetId
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function index(int $widgetId = 0): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET_THEME)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'latest' => true,
            'when' => [
                [
                    $widgetId > 0,
                    function ($query) use ($widgetId) {
                        $query->where('widget_id', $widgetId);
                    },
                ],
                // The top-level list feeds the page block design select: only designs this site can use.
                // The widget-scoped list stays complete for the widget editor.
                [
                    $widgetId < 1,
                    function ($query) {
                        $query->usableOnSite();
                    },
                ],
            ],
        ]);

        $model->map(function ($item) {
            if (empty($item->project_name)) {
                $item->project_name = [];
            }
            // Design name in the request locale; `name` and `name_translations` stay as stored.
            $item->setAttribute('display_name', $item->localizedName());
            return $item;
        });

        return ires()->data($model);
    }

    /**
     * Store a newly created resource in storage.
     *
     * Reached only through the widget-scoped route; the submitted widget_id
     * must match the {widgetId} route segment so a theme can never be created
     * under a widget other than the one addressed by the URL.
     *
     * @param int $widgetId
     * @param StoreWidgetThemeRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function store(int $widgetId, StoreWidgetThemeRequest $request): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET_THEME)->singular() . '.create')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Hub widgets are read-only on a site: change them through the widget save, which clones them.
        $hubConflict = $this->hubReadonlyConflict(Widget::query()->find($widgetId));
        if ($hubConflict !== null) {
            return $hubConflict;
        }

        // Chunk 1 Task 9b trust boundary (FR-14): creating a theme whose
        // content/css is non-empty ADDS executable content and requires
        // widget_theme.trust on top of widget_theme.create.
        if ($this->trustAuthorizer->newThemeRowTouchesExecutable($request->input('content'), $request->input('css'))
            && !user()?->can(WidgetTrustAuthorizer::WIDGET_THEME_TRUST_PERMISSION)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($request->integer('widget_id') !== $widgetId) {
            return ires()->error()->code(422)->message('Template không thuộc Widget đang xử lý.');
        }

        DB::beginTransaction();
        try {
            $model = $this->fill();

            $model->save();
        } catch (Exception $e) {
            Log::debug('WidgetThemeController@store: ' . $e->getMessage());
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        $syncResult = $this->widgetSync->sync($this->expandProjectNames($model->project_name));

        return ires()
            ->data(array_merge(['id' => $model->id], $syncResult->toArray()))
            ->message(__('mgs.ok.create', ['name' => __('nav.a.widget_themes')]));
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
     * Scoped by the owning widget: the theme is resolved by BOTH widget_id
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

        if (!user()?->can(str(CoreTableConstant::WIDGET_THEME)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }
        $model->setAttribute('display_name', $model->localizedName());

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $widgetId
     * @param int $id
     * @param UpdateWidgetThemeRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function update(int $widgetId, int $id, UpdateWidgetThemeRequest $request): mixed
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

        if (!user()?->can(str(CoreTableConstant::WIDGET_THEME)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Hub widgets are read-only on a site: change them through the widget save, which clones them.
        $hubConflict = $this->hubReadonlyConflict(Widget::query()->find($widgetId));
        if ($hubConflict !== null) {
            return $hubConflict;
        }

        if (!$this->isMutable(CoreTableConstant::WIDGET_THEME, $model->id)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Chunk 1 Task 9b trust boundary: adding, changing, or clearing
        // this theme's executable content/css requires widget_theme.trust.
        // A request that never mentions content/css (e.g. renaming a
        // theme) is never gated — see
        // WidgetTrustAuthorizer::themeFieldsTouched().
        if ($this->trustAuthorizer->themeFieldsTouched($model->content, $model->css, $request->all())
            && !user()?->can(WidgetTrustAuthorizer::WIDGET_THEME_TRUST_PERMISSION)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Capture the pre-mutation project set so a Theme moved OFF a
        // project still gets that project's complete set rebuilt, dropping
        // the now-stale generated file.
        $beforeProjects = $this->expandProjectNames($model->project_name);

        $model = $this->fill($model);

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.widget_themes')]));
        }

        $afterProjects = $this->expandProjectNames($model->project_name);
        $affectedProjects = array_values(array_unique([...$beforeProjects, ...$afterProjects]));
        $syncResult = $this->widgetSync->sync($affectedProjects);

        return ires()
            ->data(array_merge(['id' => $model->id], $syncResult->toArray()))
            ->message(__('mgs.ok.update', ['name' => __('nav.a.widget_themes')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * Scoped by the owning widget: the theme is resolved by BOTH widget_id
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

        if (!user()?->can(str(CoreTableConstant::WIDGET_THEME)->singular() . '.delete')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Hub widgets are read-only on a site: change them through the widget save, which clones them.
        $hubConflict = $this->hubReadonlyConflict(Widget::query()->find($widgetId));
        if ($hubConflict !== null) {
            return $hubConflict;
        }

        // Task 16 rider: refuse live-HTTP destroy during the managed-disable
        // side's exclusive maintenance window (see maintenanceWindowConflict()).
        $maintenanceConflict = $this->maintenanceWindowConflict();
        if ($maintenanceConflict !== null) {
            return $maintenanceConflict;
        }

        // Task 16: typed 409 with usage summary BEFORE any destructive
        // mutation is attempted — independent of, and checked before,
        // isMutable()'s pre-existing Safe-Import lock check.
        $conflict = $this->retainedReferenceConflict(CoreTableConstant::WIDGET_THEME, $model->id, 'WIDGET_TEMPLATE_IN_USE');
        if ($conflict !== null) {
            return $conflict;
        }

        if (!$this->isMutable(CoreTableConstant::WIDGET_THEME, $model->id)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Chunk 1 Task 9b trust boundary (FR-14): deleting a theme whose
        // persisted content/css is non-empty removes executable content
        // and requires widget_theme.trust on top of widget_theme.delete.
        if ($this->trustAuthorizer->themeDeletionTouchesExecutable($model->content, $model->css)
            && !user()?->can(WidgetTrustAuthorizer::WIDGET_THEME_TRUST_PERMISSION)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $affectedProjects = $this->expandProjectNames($model->project_name);
        $deletedId = $model->id;

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.failed.delete', ['name' => __('nav.a.widget_themes')]));
        }

        $syncResult = $this->widgetSync->sync($affectedProjects);

        return ires()
            ->data(array_merge(['id' => $deletedId], $syncResult->toArray()))
            ->message(__('mgs.ok.delete', ['name' => __('nav.a.widget_themes')]));
    }
}
