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
use Throwable;
use JsonException;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetMutationGuard;
use App\Contracts\Widget\WidgetRenderer;
use Cms\Core\Facades\CMSLogger;
use Cms\Core\Interfaces\WidgetInterface;
use Cms\Core\Http\Requests\Widget\StoreWidgetRequest;
use Cms\Core\Http\Requests\Widget\UpdateWidgetRequest;
use Cms\Core\Http\Requests\Widget\PreviewWidgetRequest;
use Cms\Core\Http\Requests\Widget\BlockWidgetRequest;
use Cms\Core\Services\Widget\Catalog\WidgetBlockService;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogStatusService;
use Cms\Core\Services\Widget\Catalog\WidgetUsageService;
use Cms\Core\Services\Widget\Hub\WidgetBundleUntrusted;
use Cms\Core\Services\Widget\Hub\WidgetHubCatalog;
use Cms\Core\Services\Widget\Hub\WidgetHubException;
use Cms\Core\Services\Widget\Hub\WidgetHubImpact;
use Cms\Core\Services\Widget\Hub\WidgetHubInstaller;
use Illuminate\Pagination\AbstractPaginator;
use Cms\Core\Interfaces\WidgetInputInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Widget\WidgetDefinitionSyncService;
use Cms\Core\Services\Widget\WidgetTrustAuthorizer;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Support\Widget\WidgetNameTranslations;
use Cms\Core\Services\Widget\Catalog\WidgetAuditRecorder;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;
use Cms\Core\Services\Widget\Catalog\WidgetCloneResult;
use Cms\Core\Services\Widget\Catalog\WidgetCloneService;
use Cms\Core\Services\Widget\Catalog\WidgetPreviewImages;
use Illuminate\Http\Request;
use Cms\Core\Http\Controllers\V2\Admin\Concerns\RefusesHubWidgetEdits;

/**
 * CRUD operations for CMS widgets, templates, and inputs.
 *
 * Manages widget definitions with their associated theme templates and
 * configurable input parameters. Supports preview rendering and exports
 * backup data after modifications. Extends AdminController.
 *
 * @see WidgetInterface
 * @see WidgetInputInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class WidgetController extends AdminController
{
    use RefusesHubWidgetEdits;

    /**
     * Controller constructor
     *
     * @param WidgetInterface $model
     * @param WidgetInputInterface $input
     *
     * @author malayvuong
     * @since 1.0.0
     * @added 2025-03-16 21:10:32
     */
    public function __construct(
        protected WidgetInterface      $model,
        protected WidgetInputInterface $input,
        protected WidgetDefinitionSyncService $widgetSync,
        protected WidgetMutationGuard $mutationGuard,
        protected WidgetTrustAuthorizer $trustAuthorizer,
        protected WidgetAuthoringPolicy $authoringPolicy,
        protected WidgetCloneService $cloneService,
        protected WidgetAuditRecorder $audit,
    )
    {
        parent::__construct();
    }

    /**
     * Chunk 1 Task 7 contract: consult the Core-owned WidgetMutationGuard
     * before mutating or deleting a single legacy row. Returns false when
     * the row carries an active persisted safe-import lock marker (fails
     * closed); always true today because no marker column exists yet on
     * any table — every legacy mutation path must still consult it so the
     * protection activates automatically once a future Safe Import
     * migration adds the marker (adr-widget-module-boundary.md decision 11).
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-07-17)
     */
    protected function isMutable(string $table, int $id): bool
    {
        return $this->mutationGuard->permitsMutation($table, $id);
    }

    /**
     * Task 16 (architecture.md "Foreign keys and deletion"): a typed 409
     * with an actionable usage summary, returned BEFORE any destructive
     * mutation is attempted, when the legacy row identified by ($table,
     * $id) is referenced by any retained Widget module Instance Version.
     * Returns null when no such reference exists so the caller falls
     * through to its normal flow unchanged — this check is INDEPENDENT of
     * {@see self::isMutable()} (which stays scoped to the pre-existing
     * Safe-Import lock-marker concern; the Widget module's own retained-
     * reference mutation guard never denies via the shared permitsMutation()
     * boolean because that single contract method carries no action-type
     * distinction and is also consulted by update(), where a plain field
     * edit must remain future-only per architecture.md, never
     * blanket-locked).
     *
     * Core never queries a module-owned table directly — this reads only
     * the root {@see \App\Contracts\Widget\WidgetMutationGuard} contract's
     * new usageSummaryFor() surface, whose safe module-off default always
     * returns null (see that contract's own "TASK 16 CONTRACT EVOLUTION"
     * docblock).
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
     * Task 16 rider (coordinator-adjudicated complementary-windows
     * semantics): destructive legacy Widget/Theme mutation over live HTTP
     * proceeds only while the application is NOT down for maintenance.
     * A drained maintenance window is the managed-disable side's EXCLUSIVE
     * window (ManagedModuleDisabler through the console-only
     * MaintenanceOnlyModuleLifecycleBarrier), so an HTTP destroy arriving
     * during it -- possible via the `artisan down --secret` bypass cookie
     * that lets live requests through the maintenance middleware -- must
     * refuse with the frozen WIDGET_MODULE_IN_USE conflict surface instead
     * of racing the disable transition. Deliberately NOT the
     * refuse-every-non-console barrier itself: routing shipped legacy
     * deletion through that would refuse every widget destroy in
     * production forever. Console callers are exempt (console is the
     * disable side's own sanctioned context; within-console coordination
     * during a drained window is the operator's responsibility under the
     * accepted G-013 degenerate state, superseded when a real
     * cross-process adapter lands).
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
     * Whether the widget library ("Thư viện widget") asks for the list (`scope=library`): every widget of the site
     * database with every design. Pickers never send it and keep the site filter.
     */
    private function isLibraryScope(): bool
    {
        return request('scope') === 'library';
    }

    /**
     * Design filter of the widget lists: none for the library scope, otherwise only designs this site can use
     * ({@see WidgetTheme::scopeUsableOnSite()}).
     *
     * @return \Closure(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation): mixed
     */
    private function listDesignFilter(bool $placeable = false): \Closure
    {
        if ($this->isLibraryScope()) {
            return static fn ($q) => $q;
        }

        return static fn ($q) => $q->usableOnSite($placeable);
    }

    /**
     * Set `usable_on_site` on a listed design: whether this site (`ispa-cms.folder_name`) can use it
     * ({@see WidgetTheme::projectListAllows()}). Read before the empty project list is normalised to `[]`.
     */
    private function markUsableOnSite(WidgetTheme $template): void
    {
        $template->usable_on_site = WidgetTheme::projectListAllows(
            $template->project_name,
            (string) (config('ispa-cms.folder_name') ?: 'Default'),
        );
    }

    /**
     * Get widget for admin filtered by project name
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2025-05-02, 20:43 ICT
     */
    public function adminList(): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Only designs this site can use (pickers, the Site Layout add drawer with `placeable=1`); the library asks
        // for every widget and design with `scope=library` ({@see self::listDesignFilter()}).
        $designs = $this->listDesignFilter(request()->boolean('placeable'));

        $model = $this->model->get([
            'page' => request('pageSize', 20),
            'latest' => true,
            'with' => [
                // `id` included so the admin gallery can pair a theme row with
                // its definition-catalog entry (widget_id + widget_theme_id); `theme_key` pairs a design with its
                // hub version in the update dialog.
                'template' => fn ($q) => $designs($q->select(['id', 'widget_id', 'theme_key', 'name', 'name_translations', 'css', 'project_name', 'preview_image', 'preview_stale'])),
                'inputs:id,widget_id,name',
            ],
            'has' => $this->isLibraryScope() ? [] : ['template' => $designs],
        ]);

        $model->map(function ($item) {
            $item->has_js = !empty($item->js);

            unset($item->js);

            //  Format template
            $item->template = $item->template->map(function ($template) {
                $this->markUsableOnSite($template);
                $this->withPreviewFields($template);
                $this->withDisplayName($template);
                $template->has_css = !empty($template->css);
                if (empty($template->project_name)) {
                    $template->project_name = [];
                }

                unset($template->css);
                return $template;
            });

            return $item;
        });
        $this->withLibraryFields($this->listRows($model), request()->boolean('with_usage'));

        return ires()->data($model);
    }

    /**
     * Search product
     *
     * @return JsonResponse|Response
     * @author malayvuong
     * @since 6.62.6 - 2022-09-25, 10:11 ICT
     */
    public function search()
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $data = $this->model->get([
            'when' => [
                [
                    request('id'),
                    function ($q): void {
                        $q->whereKey((int) request('id'));
                    },
                ],
                [
                    !request('id') && request('keywords') && request('keywords') !== '',
                    function ($q): void {
                        $q->where('name', 'like', '%' . request('keywords') . '%')
                            ->orWhere('description', 'like', '%' . request('keywords') . '%');
                    },
                ],
            ],
            'page' => request('limit', 20),
            'latest' => true,
            'has' => ['template' => fn ($q) => $q->usableOnSite()],
        ]);
        foreach ($this->listRows($data) as $widget) {
            if ($widget instanceof Widget) {
                $this->withDisplayName($widget);
            }
        }

        return ires()->data($data);
    }

    /**
     * Quick Update resource.
     *
     * @param Integer $id
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

        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return $this->saveDesign($model);
    }

    /**
     * Switch a status of resource.
     *
     * @param Integer $id \
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

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return $this->saveDesign($model);
    }

    /**
     * Emergency block: the widget renders nothing wherever it is placed until it is unblocked.
     * Allowed on hub widgets too (site runtime state, not a catalog edit), so no hub read-only guard.
     */
    public function block(BlockWidgetRequest $request, int $id): mixed
    {
        $model = $this->blockTarget($id);
        if (!$model instanceof Widget) {
            return $model;
        }

        app(WidgetBlockService::class)->block($model, trim((string) $request->validated('reason')), (int) user()?->id);

        return ires()
            ->data($this->blockPayload($model))
            ->message(__('mgs.ok.update', ['name' => __('nav.a.widgets')]));
    }

    public function unblock(int $id): mixed
    {
        $model = $this->blockTarget($id);
        if (!$model instanceof Widget) {
            return $model;
        }

        app(WidgetBlockService::class)->unblock($model, (int) user()?->id);

        return ires()
            ->data($this->blockPayload($model))
            ->message(__('mgs.ok.update', ['name' => __('nav.a.widgets')]));
    }

    /** @return Widget|mixed The widget, or a ready-to-return error response. */
    protected function blockTarget(int $id): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.block')) {
            return ires()->error()->code(403)->errorCode('WIDGET_BLOCK_FORBIDDEN')->message(__('mgs.no.permission'));
        }

        $model = $id > 0 ? Widget::query()->find($id) : null;

        return $model ?? $this->widgetNotFound();
    }

    protected function widgetBlockedConflict(): mixed
    {
        return ires()->error()->code(409)->errorCode('WIDGET_BLOCKED')
            ->message('This widget is blocked on this site and renders nothing until it is unblocked.');
    }

    protected function widgetNotFound(): mixed
    {
        return ires()->error()->code(404)->errorCode('WIDGET_NOT_FOUND')->message(__('mgs.model.404'));
    }

    /**
     * Pages and shared areas that use the widget (library usage drawer).
     *
     * @return mixed `{pages: [{post_id, title, type, edit_route}], areas: int}`
     */
    public function usage(int $id): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $id > 0 ? Widget::query()->find($id) : null;
        if ($model === null) {
            return $this->widgetNotFound();
        }

        return ires()->data(app(WidgetUsageService::class)->details($model));
    }

    /**
     * Whether this environment authors hub catalogs, which hub widgets have local edits not exported yet, and which
     * have content the hub has not published yet.
     *
     * @return mixed `{authoring: bool, unexported_widget_keys: string[], unpublished_widget_keys: string[]}` (empty
     *               lists when authoring is off)
     */
    public function catalogStatus(): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(app(WidgetCatalogStatusService::class)->status());
    }

    /**
     * Widgets on the hub visible to this site, with install state (cached 10 minutes; `refresh=1` reads the hub now).
     */
    public function hubCatalog(): mixed
    {
        return $this->hubAction(fn (): mixed => ires()->data(
            app(WidgetHubCatalog::class)->list(request()->boolean('refresh'))
        ));
    }

    /** "Tải về": install the hub's latest version of a widget this site does not have. */
    public function hubDownload(string $widgetKey): mixed
    {
        return $this->hubAction(function () use ($widgetKey): mixed {
            $installer = app(WidgetHubInstaller::class);
            $widget = $installer->download($widgetKey, (int) user()?->id);

            return ires()->data(['id' => (int) $widget->id] + ($installer->lastSync()?->toArray() ?? []))
                ->message('Đã tải widget về site.');
        });
    }

    /**
     * Hub preview image (PNG) of one design of a hub version, through the signed hub channel and cached 24 hours
     * ({@see WidgetHubCatalog::previewImage()}); the browser may keep it privately for a day.
     */
    public function hubPreview(string $widgetKey, int $version, string $themeKey): mixed
    {
        return $this->hubAction(fn (): mixed => response(
            app(WidgetHubCatalog::class)->previewImage($widgetKey, $version, $themeKey),
            200,
            ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff'],
        ));
    }

    /** What "Cập nhật" would change: pages, areas, designs in use that the new version drops, new input labels. */
    public function hubUpdateImpact(int $id): mixed
    {
        return $this->hubAction(fn (): mixed => ires()->data(app(WidgetHubImpact::class)->forUpdate($id)));
    }

    /** "Cập nhật": move a downloaded hub widget to the hub's latest version. */
    public function hubUpdate(int $id): mixed
    {
        return $this->hubAction(function () use ($id): mixed {
            $installer = app(WidgetHubInstaller::class);
            $widget = $installer->update($id, (int) user()?->id);

            return ires()->data(['id' => (int) $widget->id] + ($installer->lastSync()?->toArray() ?? []))
                ->message('Đã cập nhật widget.');
        });
    }

    /**
     * Permission `widget.hub`, then the action; hub refusals become `{error_code}` responses with the admin copy.
     *
     * @param callable(): mixed $action
     */
    protected function hubAction(callable $action): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.hub')) {
            return ires()->error()->code(403)->errorCode('WIDGET_HUB_FORBIDDEN')->message(__('mgs.no.permission'));
        }

        try {
            return $action();
        } catch (WidgetHubException $e) {
            return ires()->error()->code($e->status)->errorCode($e->errorCode)->message($e->getMessage());
        } catch (WidgetBundleUntrusted $e) {
            report($e);

            return ires()->error()->code(422)->errorCode(WidgetBundleUntrusted::ERROR_CODE)
                ->message('Gói widget không hợp lệ nên chưa được cài.');
        }
    }

    /**
     * Library fields on list rows and the edit payload: catalog origin, block state and version, plus `usage` when
     * `$withUsage`. Usage for all given widgets comes from one {@see WidgetUsageService::summaries()} pass; the list
     * endpoints compute it only when the library asks (`with_usage=1`), because the page editor's widget picker loads
     * the same lists on every mount and never shows usage. Without it the `usage` key is omitted.
     *
     * @param iterable<Widget> $widgets
     */
    protected function withLibraryFields(iterable $widgets, bool $withUsage): void
    {
        $widgets = collect($widgets);
        $usage = $withUsage ? app(WidgetUsageService::class)->summaries($widgets) : null;
        // Latest hub versions only from the cached hub catalog: listing never calls the hub.
        $hubLatest = app(WidgetHubCatalog::class)->cachedLatestVersions() ?? [];

        foreach ($widgets as $widget) {
            $this->withDisplayName($widget);
            $widget->setAttribute('origin', $widget->origin);
            $widget->setAttribute('publisher', $widget->publisher);
            $widget->setAttribute('cloned_from_widget_key', $widget->cloned_from_widget_key);
            $widget->setAttribute('is_blocked', $widget->isBlocked());
            $widget->setAttribute('blocked_reason', $widget->blocked_reason);
            $widget->setAttribute('version', (int) ($widget->version ?? 1));
            $widget->setAttribute('hub_latest_version', $widget->isHub() ? ($hubLatest[(string) $widget->widget_key] ?? null) : null);
            if ($usage !== null) {
                $widget->setAttribute('usage', $usage[(int) $widget->id] ?? ['pages' => 0, 'areas' => 0]);
            }
        }
    }

    /**
     * `display_name` (and `display_description` on a widget): the name in the request locale
     * ({@see Widget::localizedName()}), next to the unchanged `name` and the editable `name_translations`.
     */
    protected function withDisplayName(Widget|WidgetTheme $row): void
    {
        $row->setAttribute('display_name', $row->localizedName());
        if ($row instanceof Widget) {
            $row->setAttribute('display_description', $row->localizedDescription());
        }
    }

    /** Preview fields of a design row for the library: public URL of `preview_image` and `preview_stale` as a bool. */
    protected function withPreviewFields(WidgetTheme $template): void
    {
        $template->setAttribute('preview_image_url', WidgetPreviewImages::url($template->preview_image));
        $template->setAttribute('preview_stale', (bool) $template->preview_stale);
    }

    /**
     * Store a captured preview (PNG or WebP, at most 1 MB and 2400x4000 px) as the design's own image and clear
     * `preview_stale`. The upload is decoded and encoded again; only the re-encoded picture is stored. Same gate as
     * the design editor's save ({@see update()}): `widget.update` on the design's widget and a mutable widget row;
     * only designs this site may edit.
     */
    public function previewImage(int $id, Request $request): mixed
    {
        /** @var WidgetTheme|null $theme */
        $theme = WidgetTheme::query()->with('widget')->find($id);
        if ($theme === null || $theme->widget === null) {
            return ires()->error()->code(404)->errorCode('WIDGET_NOT_FOUND')->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $theme->widget) || !$this->isMutable(CoreTableConstant::WIDGET, (int) $theme->widget->id)) {
            return ires()->error()->code(403)->message(__('mgs.no.permission'));
        }

        $hubConflict = $this->hubReadonlyConflict($theme->widget);
        if ($hubConflict !== null) {
            return $hubConflict;
        }

        // Same error_code as a failed decode below, so the admin shows one message for any refused picture.
        $validator = Validator::make($request->all(), ['image' => ['required', 'file', 'max:1024', 'mimes:png,webp']]);
        if ($validator->fails()) {
            return ires()->error()->code(422)->errorCode('WIDGET_PREVIEW_INVALID')->message($validator->errors()->first());
        }

        // Header size check, full decode and re-encode, whatever the name or declared type says.
        $image = WidgetPreviewImages::reencode((string) file_get_contents($request->file('image')->getRealPath()));
        if ($image === null) {
            return ires()->error()->code(422)->errorCode('WIDGET_PREVIEW_INVALID')->message(__('validation.image', ['attribute' => 'image']));
        }

        $saved = app(WidgetPreviewImages::class)->replace($theme, $image['bytes'], $image['extension']);

        return ires()->data([
            'id' => (int) $saved->id,
            'preview_image_url' => WidgetPreviewImages::url($saved->preview_image),
            'preview_stale' => false,
        ]);
    }

    /** @return iterable<Widget> The rows of a list result (paginated or not). */
    protected function listRows(mixed $result): iterable
    {
        return $result instanceof AbstractPaginator ? $result->getCollection() : collect($result);
    }

    /** @return array{id: int, blocked: bool, blocked_at: ?string, blocked_by: ?int, blocked_reason: ?string} */
    protected function blockPayload(Widget $model): array
    {
        return [
            'id' => (int) $model->id,
            'blocked' => $model->isBlocked(),
            'blocked_at' => $model->blocked_at?->toIso8601String(),
            'blocked_by' => $model->blocked_by,
            'blocked_reason' => $model->blocked_reason,
        ];
    }

    /**
     * Shared save for quickUpdate/switch: a hub widget that cannot be edited in place is cloned for
     * this site first and the clone is saved instead.
     */
    protected function saveDesign(Widget $model): mixed
    {
        DB::beginTransaction();
        try {
            $cloneResult = $this->cloneWhenReadOnly($model);
            $saved = $cloneResult?->clone ?? $model;

            /** Todo: Make your change here */
            if (!$saved->save()) {
                DB::rollBack();
                return ires()->error()->message(__('mgs.failed.update', ['name' => __('nav.a.widgets')]));
            }
            $this->recordDesignSaved($saved, $cloneResult);
        } catch (Exception $e) {
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        if ($cloneResult === null) {
            return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.widgets')]));
        }

        // The clone already published its templates inside the clone transaction; this sync reports
        // the usual sync_status for the save.
        $syncResult = $this->widgetSync->sync($this->projectNamesFromThemes($saved->template()->get()));

        return ires()
            ->data(array_merge(['id' => $saved->id], $syncResult->toArray(), $this->clonedPayload($cloneResult)))
            ->message(__('mgs.ok.update', ['name' => __('nav.a.widgets')]));
    }

    /**
     * Inside the caller's transaction: when the widget is a hub widget this site may not edit in place,
     * clone it (or reuse a clone made seconds ago, see WidgetCloneService::REUSE_CLONE_WITHIN_SECONDS).
     * Returns null when the widget itself should be edited.
     */
    protected function cloneWhenReadOnly(Widget $model): ?WidgetCloneResult
    {
        if ($this->authoringPolicy->canEditInPlace($model)) {
            return null;
        }

        return $this->cloneService->cloneOrReuseForSite($model, (int) user()?->id);
    }

    /**
     * Point the request at the clone: theme ids go through the clone's themeIdMap, input ids are
     * matched by input_key. An id with no counterpart is left as is, so the ownership checks in
     * update() reject it. New rows (id <= 0) are untouched and get created on the clone.
     */
    protected function remapPayloadToClone(UpdateWidgetRequest $request, Widget $hub, WidgetCloneResult $result): void
    {
        $themeIdMap = $result->themeIdMap;
        $hubInputKeys = $hub->inputs()->pluck('input_key', 'id');
        $cloneInputIds = $result->clone->inputs()->pluck('id', 'input_key');

        $mapTheme = static fn (int $id): int => $themeIdMap[$id] ?? $id;
        $mapInput = static function (int $id) use ($hubInputKeys, $cloneInputIds): int {
            $key = $hubInputKeys[$id] ?? null;

            return $key !== null && isset($cloneInputIds[$key]) ? (int) $cloneInputIds[$key] : $id;
        };
        $mapRows = static fn (array $rows, callable $map): array => array_map(static function ($row) use ($map) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $row['id'] = $map((int) $row['id']);
            }

            return $row;
        }, $rows);

        $merge = [];
        // The editor still shows the hub name; keep the clone's own name so the list tells them apart.
        if ($request->has('name') && (string) $request->input('name') === (string) $hub->name) {
            $merge['name'] = (string) $result->clone->name;
            // fill() reads the base request, which a form-encoded FormRequest does not share.
            request()->merge(['name' => $merge['name']]);
        }
        // Same for the per-language names: unchanged hub names become the clone's own.
        if ($request->has('name_translations')
            && WidgetNameTranslations::normalize($request->input('name_translations')) === WidgetNameTranslations::normalize($hub->name_translations)) {
            $merge['name_translations'] = $result->clone->name_translations;
            request()->merge(['name_translations' => $merge['name_translations']]);
        }
        if (is_array($request->input('template'))) {
            $merge['template'] = $mapRows($request->input('template'), $mapTheme);
        }
        if (is_array($request->input('inputs'))) {
            $merge['inputs'] = $mapRows($request->input('inputs'), $mapInput);
        }
        if (is_array($request->input('deleted_template_ids'))) {
            $merge['deleted_template_ids'] = array_map(static fn ($id) => $mapTheme((int) $id), $request->input('deleted_template_ids'));
        }
        if (is_array($request->input('deleted_input_ids'))) {
            $merge['deleted_input_ids'] = array_map(static fn ($id) => $mapInput((int) $id), $request->input('deleted_input_ids'));
        }

        $request->merge($merge);
    }

    /**
     * True when the save payload changes nothing on the widget except the project lists of existing
     * themes (the Site Layout auto-open sends the whole widget back with one project added). Any other
     * difference, a new or dropped row, or an unchanged project list returns false.
     */
    protected function onlyChangesThemeProjects(Widget $model, UpdateWidgetRequest $request): bool
    {
        foreach (['name', 'description', 'js'] as $field) {
            if ($request->has($field) && (string) $request->input($field) !== (string) $model->{$field}) {
                return false;
            }
        }
        foreach (['name_translations', 'description_translations'] as $field) {
            if ($request->has($field) && WidgetNameTranslations::normalize($request->input($field)) !== WidgetNameTranslations::normalize($model->{$field})) {
                return false;
            }
        }
        if (!empty($request->input('deleted_template_ids')) || !empty($request->input('deleted_input_ids'))) {
            return false;
        }

        $normalize = static fn ($content): string => (string) preg_replace('/\s*\{\s*(.*?)\s*}/', '{\1}', (string) $content);
        $projects = static function ($value): array {
            $list = is_array($value) ? $value : (($value === null || $value === '') ? [] : [$value]);
            $list = array_map('strval', array_values($list));
            sort($list);

            return $list;
        };

        $themes = $model->template()->get()->keyBy('id');
        $submittedThemes = is_array($request->input('template')) ? $request->input('template') : [];
        if (count($submittedThemes) !== $themes->count()) {
            return false;
        }

        $projectsChanged = false;
        foreach ($submittedThemes as $row) {
            $theme = is_array($row) ? $themes->get((int) ($row['id'] ?? 0)) : null;
            if ($theme === null
                || (array_key_exists('name', $row) && (string) $row['name'] !== (string) $theme->name)
                || (array_key_exists('content', $row) && $normalize($row['content']) !== $normalize($theme->content))
                || (array_key_exists('css', $row) && (string) $row['css'] !== (string) $theme->css)
                || (array_key_exists('query', $row) && $row['query'] != $theme->query)
                || (array_key_exists('name_translations', $row)
                    && WidgetNameTranslations::normalize($row['name_translations']) !== WidgetNameTranslations::normalize($theme->name_translations))
            ) {
                return false;
            }
            if (array_key_exists('project_name', $row) && $projects($row['project_name']) !== $projects($theme->project_name)) {
                $projectsChanged = true;
            }
        }

        if (is_array($request->input('inputs'))) {
            $inputs = $model->inputs()->get()->keyBy('id');
            if (count($request->input('inputs')) !== $inputs->count()) {
                return false;
            }
            foreach ($request->input('inputs') as $row) {
                $input = is_array($row) ? $inputs->get((int) ($row['id'] ?? 0)) : null;
                if ($input === null
                    || (array_key_exists('name', $row) && (string) $row['name'] !== (string) $input->name)
                    || (array_key_exists('type', $row) && (string) $row['type'] !== (string) $input->type)
                    || (array_key_exists('default_value', $row) && (is_array($row['default_value'])
                        ? json_encode($row['default_value']) : (string) $row['default_value']) !== (string) $input->default_value)
                    || (array_key_exists('options', $row) && $row['options'] != $input->options)
                ) {
                    return false;
                }
            }
        }

        return $projectsChanged;
    }

    protected function recordDesignSaved(Widget $saved, ?WidgetCloneResult $cloneResult): void
    {
        $this->audit->record(
            WidgetAuditRecorder::ACTION_DESIGN_SAVED,
            $saved,
            (int) user()?->id,
            $cloneResult === null ? [] : ['cloned_from_widget_key' => (string) $saved->cloned_from_widget_key]
        );
    }

    /** @return array{cloned?: array{widget_id: int, widget_key: string, pages_updated: int}} */
    protected function clonedPayload(?WidgetCloneResult $cloneResult): array
    {
        if ($cloneResult === null) {
            return [];
        }

        return ['cloned' => [
            'widget_id' => (int) $cloneResult->clone->id,
            'widget_key' => (string) $cloneResult->clone->widget_key,
            'pages_updated' => $cloneResult->pagesUpdated,
        ]];
    }

    /**
     * Display a listing of the resource.
     *
     * @return $res
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.viewAny')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Same rule as admin-list: only designs this site can use, and widgets that have one, unless `scope=library`.
        $designs = $this->listDesignFilter();

        $model = $this->model->get([
            'page' => request('pageSize', 20),
            'latest' => true,
            'with' => [
                'template' => fn ($q) => $designs($q->select(['widget_id', 'name', 'name_translations', 'css', 'project_name'])),
                'inputs:widget_id,name',
            ],
            'has' => $this->isLibraryScope() ? [] : ['template' => $designs],
        ]);

        $model->map(function ($item) {
            $item->has_js = !empty($item->js);

            unset($item->js);

            //  Format template
            $item->template = $item->template->map(function ($template) {
                $this->markUsableOnSite($template);
                $this->withDisplayName($template);
                $template->has_css = !empty($template->css);
                if (empty($template->project_name)) {
                    $template->project_name = [];
                }

                unset($template->css);
                return $template;
            });

            return $item;
        });
        $this->withLibraryFields($this->listRows($model), request()->boolean('with_usage'));

        return ires()->data($model);
    }

    /**
     * Render an admin preview of a Widget/Theme pairing with the supplied
     * (or default-filled) input values.
     *
     * Resolves the root WidgetRenderer contract (Chunk 1 Task 8) instead of
     * calling WidgetQueryService directly, so the cms/Widget module can own
     * rendering without this controller ever depending on a module-owned
     * class. Values are passed through unsanitized/advisory (WidgetRenderSpec docblock):
     * the resolved renderer allowlists and sanitizes them against the
     * canonical schema, exactly like the Inline WidgetView path, so both
     * callers build an equivalent WidgetRenderSpec for the same fixture.
     *
     * Responses: raw rendered HTML string (200) on success; legacy JSON
     * error envelope otherwise — 404 missing widget, 400 permission denial,
     * 422 render-level validation failure (e.g. Widget/Theme mismatch),
     * sanitized 500 for an unexpected render failure (raw exception detail
     * is logged server-side only, never returned to the caller).
     *
     * @param int $widgetId
     *
     * @return mixed raw HTML string on success, or an ires() error envelope
     * @throws JsonException
     * @author malayvuong
     */
    public function preview(int $widgetId, PreviewWidgetRequest $request): mixed
    {
        $widget = $this->model->find($widgetId, ['*'], ['inputs']);

        if (!$widget) {
            return $this->widgetNotFound();
        }

        if (!user()?->can('view', $widget)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // A blocked widget renders nothing anywhere; say so instead of the render-level 422.
        if ($widget instanceof Widget && $widget->isBlocked()) {
            return $this->widgetBlockedConflict();
        }

        $templateId = $request->integer('template_id');

        //  init the default value into data if its value is empty
        $data = [];
        foreach ($widget->inputs as $input) {
            if (!$request->has($input->name)) {
                // Edit-marker previews must never show the literal `{name}` placeholder.
                $data[$input->name] = $request->boolean('edit_markers')
                    ? ($input->default_value ?? (in_array($input->type, ['image', 'upload'], true) ? [] : ''))
                    : ($input->default_value ?? '{' . $input->name . '}');
            } else {
                $value = $request->input($input->name);
                $data[$input->name] = in_array($input->type, ['image', 'upload'], true)
                    ? (is_array($value) ? ($value[0] ?? []) : $value)
                    : $value;
            }
        }

        $spec = new WidgetRenderSpec($widgetId, $templateId, $data);
        $context = new WidgetRenderContext(
            projectName: (string) config('ispa-cms.folder_name', 'Default'),
            useCss: true,
            isPreview: true,
            editMarkers: $request->boolean('edit_markers'),
        );

        try {
            $result = app(WidgetRenderer::class)->render($spec, $context);
        } catch (Throwable $e) {
            // Sanitized 500: never leak raw exception detail to the admin
            // preview caller. The real exception is still logged server-side.
            CMSLogger::error([
                'message' => $e->getMessage(),
                'exception' => $e,
                'isAttach' => true,
            ]);

            return ires()->serverError(__('mgs.err.server_error'));
        }

        if (!$result->ok && $result->errorCode === 'WIDGET_BLOCKED') {
            return $this->widgetBlockedConflict();
        }

        if (!$result->ok) {
            // Widget/Theme mismatch or another canonical-schema rejection:
            // characterized as 422 by the frozen WidgetAdminContractTest
            // ("widget preview rejects a template belonging to another widget").
            return ires()->error()->code(422)->message(__('validation.exists', [
                'attribute' => 'template_id',
            ]));
        }

        //  Process to render string to html
        return $result->html;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreWidgetRequest $request
     *
     * @return $res
     * @throws Throwable
     * @author malayvuong
     */
    public function store(StoreWidgetRequest $request): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.create')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Chunk 1 Task 9b trust boundary (FR-14): CREATING executable
        // content is gated exactly like changing it. A brand-new widget
        // carrying non-empty js requires widget.trust; any submitted
        // template row carrying non-empty content/css requires
        // widget_theme.trust. Empty variants (js absent/empty) stay
        // ungated; template content can never be empty here because
        // StoreWidgetRequest requires it, so validation answers 406
        // before this gate is ever consulted for that case.
        if ($this->trustAuthorizer->widgetJsTouched(null, $request->all())
            && !user()?->can(WidgetTrustAuthorizer::WIDGET_TRUST_PERMISSION)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $storeAddsThemeExecutable = false;
        foreach ($request->input('template', []) as $template) {
            if ($this->trustAuthorizer->newThemeRowTouchesExecutable(
                $template['content'] ?? null,
                $template['css'] ?? null
            )) {
                $storeAddsThemeExecutable = true;
                break;
            }
        }
        if ($storeAddsThemeExecutable
            && !user()?->can(WidgetTrustAuthorizer::WIDGET_THEME_TRUST_PERMISSION)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();
        try {
            $model = $this->fill();

            $model->save();

            //  Init the template items
            if (!empty($request->input('template', []))) {
                $templates = [];
                foreach ($request->input('template', []) as $key => $template) {
                    $templates[$key] = $template;
                    $templates[$key]['content'] = preg_replace('/\s*\{\s*(.*?)\s*}/', '{\1}', $template['content']);
                }

                $model->template()->createMany($templates);
            }
            // Inputs are independent from templates and must not be silently dropped.
            if (!empty($request->input('inputs', []))) {
                $model->inputs()->createMany($this->initInputs($request->input('inputs', [])));
            }
        } catch (Exception $e) {
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        $syncResult = $this->widgetSync->sync($this->projectNamesFromThemes($model->template()->get()));

        // Committed resource identity + additive sync fields: the row is
        // saved regardless of the sync outcome, so the response always
        // carries its id alongside sync_status (never a 503 here).
        return ires()
            ->data(array_merge(['id' => $model->id], $syncResult->toArray()))
            ->message(__('mgs.ok.create', ['name' => __('nav.a.widgets')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     * @author malayvuong
     */
    protected function fill(mixed $model = null): mixed
    {
        if ($model === null) {
            $model = $this->model->model();
        }

        // Only the admin-editable fields. Catalog and block columns (origin, publisher, cloned_*,
        // blocked_*) are mass assignable for services but must never come from an admin request.
        // Per-language names are normalised by the model (trimmed, vi/en only, null when empty).
        $model->fill(request()->only(['name', 'description', 'js', 'name_translations', 'description_translations']));

        return $model;
    }

    /**
     * Init inputs before create
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-05-07, 20:41 ICT
     */
    protected function initInputs(array $data = []): array
    {
        $collect = collect($data);

        $collect = $collect->map(function ($item) {
            if ($item['type'] === 'list' && is_array($item['default_value'])) {
                $item['default_value'] = json_encode($item['default_value'], JSON_THROW_ON_ERROR);
            }

            return $item;
        });

        return $collect->toArray();
    }

    /**
     * Validate a staged Theme (template) deletion set before it is applied
     * inside the aggregate Save transaction (Gate 0 Task 3).
     *
     * Every id must belong to the widget being saved (ownership), none may
     * also appear in the upsert array for this save (overlap), and the
     * resulting Theme count may never reach zero (last-Template rule): a
     * widget must retain at least one Theme.
     *
     * @param mixed $model Widget model instance being saved.
     * @param array<int, int> $deletedTemplateIds
     * @param array $submittedTemplates The raw `template` request array.
     * @throws RuntimeException
     */
    protected function validateDeletedTemplateIds(mixed $model, array $deletedTemplateIds, array $submittedTemplates): void
    {
        if (empty($deletedTemplateIds)) {
            return;
        }

        $existingTemplateIds = $model->template()->pluck('id')->all();
        if (!empty(array_diff($deletedTemplateIds, $existingTemplateIds))) {
            throw new RuntimeException('Template không thuộc Widget đang cập nhật.');
        }

        $updatedTemplateIds = [];
        $newTemplateCount = 0;
        foreach ($submittedTemplates as $template) {
            $templateId = (int) ($template['id'] ?? 0);
            if ($templateId > 0) {
                $updatedTemplateIds[] = $templateId;
            } else {
                $newTemplateCount++;
            }
        }

        if (!empty(array_intersect($deletedTemplateIds, $updatedTemplateIds))) {
            throw new RuntimeException('Template không thể vừa cập nhật vừa xoá trong cùng một lần lưu.');
        }

        $remainingTemplateCount = count($existingTemplateIds) - count($deletedTemplateIds) + $newTemplateCount;
        if ($remainingTemplateCount < 1) {
            throw new RuntimeException('Widget phải giữ lại ít nhất một Theme.');
        }
    }

    /**
     * Validate a staged Input deletion set before it is applied inside the
     * aggregate Save transaction (Gate 0 Task 3).
     *
     * Every id must belong to the widget being saved (ownership) and none
     * may also appear in the upsert array for this save (overlap). Inputs
     * have no last-item invariant.
     *
     * @param mixed $model Widget model instance being saved.
     * @param array<int, int> $deletedInputIds
     * @param array $submittedInputs The raw `inputs` request array.
     * @throws RuntimeException
     */
    protected function validateDeletedInputIds(mixed $model, array $deletedInputIds, array $submittedInputs): void
    {
        if (empty($deletedInputIds)) {
            return;
        }

        $existingInputIds = $model->inputs()->pluck('id')->all();
        if (!empty(array_diff($deletedInputIds, $existingInputIds))) {
            throw new RuntimeException('Input không thuộc Widget đang cập nhật.');
        }

        $updatedInputIds = [];
        foreach ($submittedInputs as $input) {
            $inputId = (int) ($input['id'] ?? 0);
            if ($inputId > 0) {
                $updatedInputIds[] = $inputId;
            }
        }

        if (!empty(array_intersect($deletedInputIds, $updatedInputIds))) {
            throw new RuntimeException('Input không thể vừa cập nhật vừa xoá trong cùng một lần lưu.');
        }
    }

    /**
     * Protected operator recovery action for a `sync_status: pending`
     * incident: re-runs the legacy Widget definition export and artifact
     * publication for every known project. A browser-usable remedy so
     * operators are never told to run a shell command directly.
     *
     * @return $res
     * @author malayvuong
     */
    public function retrySync(): mixed
    {
        if (!user()?->can(str(CoreTableConstant::WIDGET)->singular() . '.update')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $syncResult = $this->widgetSync->syncAll();

        $message = $syncResult->isReady()
            ? 'Đồng bộ widget đã hoàn tất.'
            : 'Đồng bộ widget vẫn đang chờ xử lý. Vui lòng thử lại sau.';

        return ires()->data($syncResult->toArray())->message($message);
    }

    /**
     * Expand a collection of WidgetTheme rows into the distinct project
     * names their generated artifacts apply to, mirroring the legacy
     * generator fan-out: a scalar or missing project_name normalizes to
     * Default; an explicit array (including an empty one) is used as-is.
     *
     * @param iterable $themes
     * @return array<int, string>
     */
    protected function projectNamesFromThemes(iterable $themes): array
    {
        $names = [];
        foreach ($themes as $theme) {
            $projectNames = $theme->project_name ?? null;
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

        $model = $this->model->find($id, ['*'], ['template', 'inputs']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model->template = $model->template->map(function ($template) {
            if (empty($template->project_name)) {
                $template->project_name = [];
            }
            $this->withPreviewFields($template);
            $this->withDisplayName($template);

            return $template;
        });
        $this->withLibraryFields([$model], true);

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Integer $id
     * @param UpdateWidgetRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function update(int $id, UpdateWidgetRequest $request): mixed
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

        if (!$this->isMutable(CoreTableConstant::WIDGET, $model->id)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Adding or removing a site on a hub theme (Site Layout "enable for this site") is not a design
        // edit: cloning for it would move every page to a copy and leave the caller holding hub ids.
        if (!$this->authoringPolicy->canEditInPlace($model) && $this->onlyChangesThemeProjects($model, $request)) {
            return $this->hubReadonlyConflict($model);
        }

        // A hub widget this site may not edit in place is cloned first; the edit then applies to the
        // clone (all page uses already moved to it) and the hub row never changes. Cloning runs in the
        // same transaction as the edit, so a refused edit (trust, ownership) leaves no clone behind.
        DB::beginTransaction();
        try {
            $cloneResult = $this->cloneWhenReadOnly($model);
            if ($cloneResult !== null) {
                $this->remapPayloadToClone($request, $model, $cloneResult);
                $model = $cloneResult->clone;
            }
        } catch (Exception $e) {
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }

        // Chunk 1 Task 9b trust boundary: adding, changing, or clearing the
        // widget's own executable `js` field requires widget.trust. A
        // request that never mentions `js` (e.g. renaming a widget) is
        // never gated — see WidgetTrustAuthorizer::widgetJsTouched().
        // Compared against the widget actually being edited (a reused clone
        // may already carry a different `js` than its hub).
        if ($this->trustAuthorizer->widgetJsTouched($model->js, $request->all())
            && !user()?->can(WidgetTrustAuthorizer::WIDGET_TRUST_PERMISSION)) {
            DB::rollBack();
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Capture the pre-mutation project set so a Theme moved OFF a
        // project (or deleted outright) still gets that project's complete
        // set rebuilt, dropping the now-stale generated file.
        $beforeProjects = $this->projectNamesFromThemes($model->template()->get());

        // Gate 0 Task 3: optional staged child deletion. Presence of the
        // field (regardless of being empty) opts that child type into the
        // explicit id-based deletion contract for this save; templates/
        // inputs omitted from the payload then survive untouched instead of
        // being swept by the legacy whole-array-replace delete below.
        // Omitting the field entirely preserves current behavior exactly.
        $templateDeletionStaged = $request->has('deleted_template_ids');
        $inputDeletionStaged = $request->has('deleted_input_ids');
        $deletedTemplateIds = array_values(array_unique(array_map(
            'intval',
            $request->input('deleted_template_ids', [])
        )));
        $deletedInputIds = array_values(array_unique(array_map(
            'intval',
            $request->input('deleted_input_ids', [])
        )));

        try {
            $model = $this->fill($model);

            $model->save();

            if ($templateDeletionStaged) {
                $this->validateDeletedTemplateIds($model, $deletedTemplateIds, $request->input('template', []));
            }
            if ($inputDeletionStaged) {
                $this->validateDeletedInputIds($model, $deletedInputIds, $request->input('inputs', []));
            }

            $submittedTemplateIds = [];
            foreach ($request->input('template', []) as $template) {
                $templateId = (int) ($template['id'] ?? 0);
                $templateData = collect($template)->only([
                    'name', 'content', 'css', 'query', 'project_name', 'name_translations',
                ])->toArray();
                if (array_key_exists('name_translations', $templateData)) {
                    $templateData['name_translations'] = WidgetNameTranslations::normalize($templateData['name_translations']);
                }
                $templateData['content'] = preg_replace('/\s*\{\s*(.*?)\s*}/', '{\1}', $templateData['content']);

                if ($templateId > 0) {
                    $existingTemplate = $model->template()->whereKey($templateId)->first();
                    if (!$existingTemplate) {
                        throw new RuntimeException('Template không thuộc Widget đang cập nhật.');
                    }
                    if (!$this->isMutable(CoreTableConstant::WIDGET_THEME, $templateId)) {
                        throw new RuntimeException(__('mgs.no.permission'));
                    }
                    // Chunk 1 Task 9b trust boundary (FR-14): adding,
                    // changing, or clearing an EXISTING theme's executable
                    // content/css requires widget_theme.trust. Compared
                    // against $templateData (the normalized values actually
                    // persisted below), so a byte-identical resubmit stays a
                    // no-op and is never gated.
                    if ($this->trustAuthorizer->themeFieldsTouched(
                        $existingTemplate->content,
                        $existingTemplate->css,
                        $templateData
                    ) && !user()?->can(WidgetTrustAuthorizer::WIDGET_THEME_TRUST_PERMISSION)) {
                        throw new RuntimeException(__('mgs.no.permission'));
                    }
                    // A query-builder update skips the model casts: write the translations as JSON text.
                    $model->template()->whereKey($templateId)->update(array_key_exists('name_translations', $templateData)
                        ? ['name_translations' => WidgetNameTranslations::encode($templateData['name_translations'])] + $templateData
                        : $templateData);
                    $submittedTemplateIds[] = $templateId;
                } else {
                    // Chunk 1 Task 9b trust boundary: a brand-new nested
                    // Theme row (no persisted counterpart) carrying
                    // non-empty executable content/css is ADDING executable
                    // content to an already-existing Widget and requires
                    // widget_theme.trust.
                    if ($this->trustAuthorizer->newThemeRowTouchesExecutable(
                        $templateData['content'] ?? null,
                        $templateData['css'] ?? null
                    ) && !user()?->can(WidgetTrustAuthorizer::WIDGET_THEME_TRUST_PERMISSION)) {
                        throw new RuntimeException(__('mgs.no.permission'));
                    }
                    $submittedTemplateIds[] = $model->template()->create($templateData)->id;
                }
            }
            if (!$templateDeletionStaged) {
                // Legacy whole-array-replace sweep: every unsubmitted theme
                // row is deleted, so each swept row goes through both the
                // mutation guard and — when it carries non-empty executable
                // content/css — the widget_theme.trust deletion gate (FR-14).
                foreach ($model->template()->whereNotIn('id', $submittedTemplateIds)->get() as $sweptTemplate) {
                    if (!$this->isMutable(CoreTableConstant::WIDGET_THEME, (int) $sweptTemplate->id)) {
                        throw new RuntimeException(__('mgs.no.permission'));
                    }
                    if ($this->trustAuthorizer->themeDeletionTouchesExecutable($sweptTemplate->content, $sweptTemplate->css)
                        && !user()?->can(WidgetTrustAuthorizer::WIDGET_THEME_TRUST_PERMISSION)) {
                        throw new RuntimeException(__('mgs.no.permission'));
                    }
                }
                $model->template()->whereNotIn('id', $submittedTemplateIds)->delete();
            }
            if ($templateDeletionStaged && !empty($deletedTemplateIds)) {
                // Staged deletion (Gate 0 Task 3): ownership was already
                // validated, so this whereIn returns exactly the staged
                // rows — each goes through the mutation guard and the
                // widget_theme.trust deletion gate (FR-14).
                foreach ($model->template()->whereIn('id', $deletedTemplateIds)->get() as $deletedTemplate) {
                    if (!$this->isMutable(CoreTableConstant::WIDGET_THEME, (int) $deletedTemplate->id)) {
                        throw new RuntimeException(__('mgs.no.permission'));
                    }
                    if ($this->trustAuthorizer->themeDeletionTouchesExecutable($deletedTemplate->content, $deletedTemplate->css)
                        && !user()?->can(WidgetTrustAuthorizer::WIDGET_THEME_TRUST_PERMISSION)) {
                        throw new RuntimeException(__('mgs.no.permission'));
                    }
                }
                $model->template()->whereIn('id', $deletedTemplateIds)->delete();
            }

            $payload = $request->all();
            if (array_key_exists('inputs', $payload)) {
                $submittedInputIds = [];
                foreach ($this->initInputs($request->input('inputs') ?? []) as $input) {
                    $inputId = (int) ($input['id'] ?? 0);
                    $inputData = collect($input)->only([
                        'name', 'default_value', 'type', 'options',
                    ])->toArray();

                    if ($inputId > 0) {
                        if (!$model->inputs()->whereKey($inputId)->exists()) {
                            throw new RuntimeException('Input không thuộc Widget đang cập nhật.');
                        }
                        if (!$this->isMutable(CoreTableConstant::WIDGET_INPUT, $inputId)) {
                            throw new RuntimeException(__('mgs.no.permission'));
                        }
                        $model->inputs()->whereKey($inputId)->update($inputData);
                        $submittedInputIds[] = $inputId;
                    } else {
                        $submittedInputIds[] = $model->inputs()->create($inputData)->id;
                    }
                }
                if (!$inputDeletionStaged) {
                    foreach ($model->inputs()->whereNotIn('id', $submittedInputIds)->pluck('id') as $sweptInputId) {
                        if (!$this->isMutable(CoreTableConstant::WIDGET_INPUT, (int) $sweptInputId)) {
                            throw new RuntimeException(__('mgs.no.permission'));
                        }
                    }
                    $model->inputs()->whereNotIn('id', $submittedInputIds)->delete();
                }
            }
            if ($inputDeletionStaged && !empty($deletedInputIds)) {
                foreach ($deletedInputIds as $deletedInputId) {
                    if (!$this->isMutable(CoreTableConstant::WIDGET_INPUT, $deletedInputId)) {
                        throw new RuntimeException(__('mgs.no.permission'));
                    }
                }
                $model->inputs()->whereIn('id', $deletedInputIds)->delete();
            }

            $this->recordDesignSaved($model, $cloneResult);
        } catch (Exception $e) {
            DB::rollBack();
            return ires()->error()->message($e->getMessage());
        }
        DB::commit();

        $afterProjects = $this->projectNamesFromThemes($model->template()->get());
        $affectedProjects = array_values(array_unique([...$beforeProjects, ...$afterProjects]));
        $syncResult = $this->widgetSync->sync($affectedProjects);

        return ires()
            ->data(array_merge(['id' => $model->id], $syncResult->toArray(), $this->clonedPayload($cloneResult)))
            ->message(__('mgs.ok.update', ['name' => __('nav.a.widgets')]));
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

        // Hub widgets belong to the catalog: a site may clone them by editing, never delete them.
        $hubConflict = $this->hubReadonlyConflict($model);
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
        $definitionConflict = $this->retainedReferenceConflict(CoreTableConstant::WIDGET, $model->id, 'WIDGET_DEFINITION_IN_USE');
        if ($definitionConflict !== null) {
            return $definitionConflict;
        }

        if (!$this->isMutable(CoreTableConstant::WIDGET, $model->id)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        // Chunk 1 Task 9b trust boundary: deleting a widget whose `js` is
        // non-empty removes executable content and requires widget.trust.
        if ($this->trustAuthorizer->widgetDeletionTouchesJs($model->js)
            && !user()?->can(WidgetTrustAuthorizer::WIDGET_TRUST_PERMISSION)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $themes = $model->template()->get();

        // Chunk 1 Task 7 guard cascade: widget deletion cascade-removes
        // its Theme and Input rows (widget_id is cascadeOnDelete on both
        // child tables), so every child row is consulted through the
        // mutation guard exactly like update()'s per-row loops — a locked
        // child must fail closed even when its parent widget row is
        // unlocked, otherwise destroy would bypass the per-row guard.
        // Task 16: aggregate child Theme deletion gets the SAME typed 409
        // treatment as direct Theme delete (plan checklist: "Direct Theme
        // delete, aggregate child Theme deletion, and Widget destroy...
        // return typed 409 before FK failure"); cascaded Input rows do
        // NOT (Input stays future-only, no blanket lock — architecture.md).
        foreach ($themes as $theme) {
            $themeConflict = $this->retainedReferenceConflict(CoreTableConstant::WIDGET_THEME, (int) $theme->id, 'WIDGET_TEMPLATE_IN_USE');
            if ($themeConflict !== null) {
                return $themeConflict;
            }

            if (!$this->isMutable(CoreTableConstant::WIDGET_THEME, (int) $theme->id)) {
                return ires()->error()->message(__('mgs.no.permission'));
            }
        }
        foreach ($model->inputs()->get() as $input) {
            if (!$this->isMutable(CoreTableConstant::WIDGET_INPUT, (int) $input->id)) {
                return ires()->error()->message(__('mgs.no.permission'));
            }
        }

        // FR-14 cascade rule: widget deletion cascade-removes its Theme
        // rows (widget_themes.widget_id is cascadeOnDelete), so when any
        // owned Theme carries non-empty executable content/css this
        // deletion also requires widget_theme.trust — otherwise widget
        // destroy would be a trivial bypass of the per-theme deletion gate.
        $cascadeTouchesExecutable = $themes->contains(
            fn ($theme) => $this->trustAuthorizer->themeDeletionTouchesExecutable($theme->content, $theme->css)
        );
        if ($cascadeTouchesExecutable
            && !user()?->can(WidgetTrustAuthorizer::WIDGET_THEME_TRUST_PERMISSION)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $affectedProjects = $this->projectNamesFromThemes($themes);
        $deletedId = $model->id;

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.failed.delete', ['name' => __('nav.a.widgets')]));
        }

        $syncResult = $this->widgetSync->sync($affectedProjects);

        return ires()
            ->data(array_merge(['id' => $deletedId], $syncResult->toArray()))
            ->message(__('mgs.ok.delete', ['name' => __('nav.a.widgets')]));
    }
}
