<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Admin;

use App\Support\Modules\ModuleStatus;
use App\Support\Tracking\TrackingTargetRollUpRegistry;
use App\Constants\Tables;
use App\Jobs\TopRangeAggregationJob;
use App\Models\Tracking\TrackingTop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Content\Interfaces\PostInterface;
use Carbon\Carbon;
use App\Repositories\Interfaces\TrackInterface;
use App\Repositories\Interfaces\CommentInterface;
use App\Repositories\Interfaces\ContactInterface;
use App\Services\Tracking\TrackingAnalyticsService;
use App\Services\Tracking\TrackingTargetKeyResolver;
use Illuminate\Support\Collection;
use Throwable;

class TrackController extends AdminController
{
    /**
     * Request-lifetime memo for schema and module-status lookups.
     *
     * @var array<string, bool>
     */
    protected static array $schemaMemo = [];

    /**
     * Controller constructor
     *
     * @param TrackInterface $model
     * @param PostInterface|null $post Null when the Content module is disabled (no container binding); never used by this controller
     * @param ContactInterface|null $contact
     * @param CommentInterface|null $comment Resolved lazily when not injected
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected TrackInterface   $model,
        protected ?PostInterface   $post = null,
        protected ?ContactInterface $contact = null,
        protected ?CommentInterface $comment = null,
        protected ?TrackingAnalyticsService $analytics = null
    )
    {

        parent::__construct();
    }

    /**
     * Get data for chart
     *
     * @author malayvuong
     * @since 6.6.8 - 2022-03-07, 18:12
     */
    public function chart()
    {
        $data = request()->all();
        $type = $data['type'] ?? 'last_7_days';
        $dateRange = get_date_range($type);
        $startDate = Carbon::parse($dateRange[0])->startOfDay();
        $endDate = Carbon::parse($dateRange[1])->endOfDay();
        $baseTargetType = $this->resolveTargetType($data);
        $baseTargetId = ($data['target_id'] ?? 0) > 0
            ? (int) $data['target_id']
            : null;
        $hasScanSeries = !isset($data['target_type']) || $data['target_type'] !== 'Post';
        $chart = $this->analytics()->chart(
            $startDate,
            $endDate,
            $baseTargetType,
            $baseTargetId
        );
        $series = [
            ['name' => __('text.rp.total-view'), 'data' => $chart['views']],
            ['name' => __('text.rp.total-visits'), 'data' => $chart['visitors']],
        ];
        if ($hasScanSeries) {
            $series[] = ['name' => __('text.rp.total-scan'), 'data' => $chart['scans']];
        }

        $dates = $chart['dates'];
        unset($chart['dates'], $chart['views'], $chart['visitors'], $chart['scans']);

        return ires()->data([
            'label' => array_map(
                static fn (string $date): string => isset($data['target_type'])
                    ? $date
                    : Carbon::parse($date)->format('d/m'),
                $dates
            ),
            'series' => $series,
            ...$chart,
        ]);
    }

    /**
     * Get tracking for dashboard
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-03-4, 1:23
     */
    public function dashboard(): mixed
    {
        $range = $this->dashboardRange();
        $cacheKey = 'admin.track.dashboard.' . md5(json_encode([
            'from' => $range[0],
            'to' => $range[1],
            'locale' => app()->getLocale(),
        ]));

        if (filter_var(request()->query('reload'), FILTER_VALIDATE_BOOLEAN)) {
            cache()->forget($cacheKey);
        }

        $payload = cache()->remember($cacheKey, 300, fn (): array => $this->buildDashboardPayload($range));

        return ires()->data($payload);
    }

    /**
     * Resolve the creation-date range used by the dashboard "new" metrics.
     * Accepts a two-item `dateRange` request array, otherwise the last 7 days.
     *
     * @return array{0:string,1:string} Start and end timestamps (Y-m-d H:i:s).
     */
    protected function dashboardRange(): array
    {
        $given = request('dateRange', []);

        if (is_array($given) && count($given) === 2) {
            try {
                return [
                    Carbon::parse($given[0])->format('Y-m-d H:i:s'),
                    Carbon::parse($given[1])->format('Y-m-d H:i:s'),
                ];
            } catch (Throwable) {
                // fall back to the default range
            }
        }

        return [now()->subDays(7)->format('Y-m-d 00:00:00'), now()->format('Y-m-d 23:59:59')];
    }

    /**
     * Compute the dashboard payload (cached by dashboard()).
     *
     * @param array{0:string,1:string} $range
     * @return array<string, mixed>
     */
    protected function buildDashboardPayload(array $range): array
    {
        $interface = [
            'ggSiteCaptcha' => (bool)config('setting.api.GOOGLE_RECAPTCHA_SITE_KEY'),
        ];

        $tracking = $this->analytics()->siteTotals();
        // TODO: tags LIKE scans in TrackingAnalyticsService are slow on raw tracks; prefer read_mode=aggregate.
        // Enriched metrics sourced from the new aggregate tables (tracking_daily / target totals).
        $hasDaily = $this->tableExists(Tables::TRACKING_DAILY);
        $totalSession = $hasDaily ? (int) DB::table(Tables::TRACKING_DAILY)->sum('sessions') : 0;
        $totalDownload = $hasDaily ? (int) DB::table(Tables::TRACKING_DAILY)->sum('download') : 0;
        $topContent = $this->topContent(8);
        $viewsByType = $this->viewsByType();
        $totalItems = $this->moduleIsEnabled('GeoPlaces') && $this->tableExists(Tables::PLACE_ITEMS)
            ? DB::table(Tables::PLACE_ITEMS)->whereNull('deleted_at')->count()
            : 0;
        $totalNews = $this->contentPostsTableIsAvailable()
            ? DB::table(Tables::POSTS)->whereNull('deleted_at')->count()
            : 0;
        $totalContact = $this->tableExists(Tables::CONTACTS)
            ? DB::table(Tables::CONTACTS)->whereNull('deleted_at')->count()
            : 0;
        $comments = $this->tableExists(Tables::COMMENTS)
            ? [
                'total' => DB::table(Tables::COMMENTS)->whereNull('deleted_at')->count(),
                'new' => DB::table(Tables::COMMENTS)->whereNull('deleted_at')->whereBetween('created_at', $range)->count(),
                'pending' => DB::table(Tables::COMMENTS)->whereIn('status', ['pending', 'viewed'])->whereNull('deleted_at')->count(),
                'confirmed' => DB::table(Tables::COMMENTS)->where('status', 'confirmed')->whereNull('deleted_at')->count(),
                'rejected' => DB::table(Tables::COMMENTS)->where('status', 'rejected')->whereNull('deleted_at')->count(),
            ]
            : ['total' => 0, 'new' => 0, 'pending' => 0, 'confirmed' => 0, 'rejected' => 0];

        return [
            'totalScan' => $tracking['total_scan'],
            'totalView' => $tracking['total_view'],
            'totalVisit' => $tracking['total_visit'],
            'totalSession' => $totalSession,
            'totalDownload' => $totalDownload,
            'topContent' => $topContent,
            'viewsByType' => $viewsByType,
            'trackingDataQuality' => $tracking['data_quality'],
            'trackingTotalScope' => $tracking['total_scope'],
            'totalItems' => (int)$totalItems,
            'totalNews' => (int)$totalNews,
            'totalContact' => (int)$totalContact,
            'comments' => $comments,
            ...$interface,
        ];
    }

    /**
     * Clear the request-lifetime schema memo (long-lived workers and tests).
     */
    public static function forgetSchemaMemo(): void
    {
        self::$schemaMemo = [];
    }

    /**
     * Whether a table exists; memoised per request and cached for one day.
     */
    protected function tableExists(string $table): bool
    {
        return self::$schemaMemo["t:$table"] ??= (bool) cache()->remember(
            'admin.track.schema.' . $table,
            86400,
            fn (): bool => Schema::hasTable($table)
        );
    }

    /**
     * Whether a table column exists; memoised per request and cached for one day.
     */
    protected function columnExists(string $table, string $column): bool
    {
        return self::$schemaMemo["c:$table.$column"] ??= (bool) cache()->remember(
            'admin.track.schema.' . $table . '.' . $column,
            86400,
            fn (): bool => Schema::hasColumn($table, $column)
        );
    }

    protected function contentPostsTableIsAvailable(): bool
    {
        return $this->moduleIsEnabled('Content') && $this->tableExists(Tables::POSTS);
    }

    /**
     * Whether a module is enabled according to the module activator; memoised per request
     * and cached for one day.
     */
    protected function moduleIsEnabled(string $module): bool
    {
        return self::$schemaMemo["m:$module"] ??= (bool) cache()->remember(
            'admin.track.schema.module.' . $module,
            86400,
            function () use ($module): bool {
                return ModuleStatus::isEnabled($module);
            }
        );
    }

    /**
     * Get general tracking information
     *
     * @author malayvuong
     *
     * @since 6.0.3 - 2022-02-19, 11:43
     */
    public function getTrack(): mixed
    {
        if (request('id', 0) === 0 || !request()->has('type')) {
            return ires()->data([]);
        }

        $targetClass = $this->resolveTargetType([
            'target_type' => request('type'),
            'target_id' => request('id'),
        ]);

        $where = [
            'target_type' => $targetClass,
            'target_id' => request('id'),
        ];

        if ($this->analytics()->readMode() === 'aggregate') {
            $totals = $this->analytics()->targetTotals(
                $targetClass,
                (int) request('id')
            );

            return ires()->data([
                'totalVisit' => $totals['total_visit'],
                'totalScan' => $totals['total_scan'],
                'totalView' => $totals['total_view'],
                'label' => [],
                'view' => [],
                'scan' => [],
                'visit' => [],
                'comments' => $this->commentCounts($where),
                'data_quality' => $totals['data_quality'],
                'total_scope' => $totals['total_scope'],
                'total_visit_semantics' => $totals['total_visit_semantics'],
                'exact_from_date' => $totals['exact_from_date'] ?? null,
                'metric_quality' => $totals['metric_quality'],
                'metric_scope' => $totals['metric_scope'] ?? null,
            ]);
        }

        $tracks = $this->model->get([
            'select' => ['tags', 'view', 'session_id', 'created_at'],
            'where' => $where,
            'when' => [
                [
                    fn() => count(request('dateRange', [])) === 2,
                    function ($q) {
                        return $q->whereBetween('created_at', request('dateRange'));
                    },
                ],
                //  If no dateRange => get data default by 7 days
                [
                    fn() => count(request('dateRange', [])) === 0,
                    function ($q): void {
                        $q->whereBetween('created_at', [now()->addDays(-7)->format('Y-m-d 00:00:00'), now()]);
                    },
                ],
            ],
        ]);

        $totalScan = $this->model->model()->where($where)
            ->whereJsonContains('tags', 'scan')->select('id')->count();

        $totalVisit = $this->model->model()->where($where)
            ->whereJsonContains('tags', 'page_view')->groupBy('session_id')->select('session_id', DB::raw('count(*) as total'))->get();

        $totalView = $this->model->model()->where($where)
            ->whereJsonContains('tags', 'page_view')->select('view')->sum('view');

        //  Init the data
        $data = [
            'totalVisit' => $totalVisit->count('total'),
            'totalScan' => $totalScan,
            'totalView' => $totalView,
            'label' => [],
            'view' => [],
            'scan' => [],
            'visit' => [],
            'comments' => $this->commentCounts($where),
        ];

        //  SET THE DATE
        if (in_array(request('dateType', 'last_7_days'), ['last_week', 'last_month', 'last_30_days', 'last_7_days'], true)) {
            foreach ($tracks as $track) {
            }
        }

        return ires()->data($data);
    }

    /**
     * Display a listing of the resource.
     *
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

        $model = $this->model->model()
            ->newQuery()
            ->where(
                'updated_at',
                '>=',
                now()->subDays((int) config('tracking.raw_listing_retention_days', 30))
            )
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) request('per_page', 25))));

        return ires()->data($model);
    }

    /**
     * Resolve target type to the fully qualified model class used in tracking.
     *
     * @param array $data
     *
     * @return string|null
     */
    protected function resolveTargetType(array $data): ?string
    {
        $map = [
            'Post' => 'Cms\\Content\\Models\\Post',
            'Content' => 'Cms\\Content\\Models\\Content',
            'App\\Models\\Articles\\Post' => 'Cms\\Content\\Models\\Post',
        ];

        if (!empty($data['target_type'])) {
            return $map[$data['target_type']] ?? ('App\\Models\\' . $data['target_type']);
        }

        if (($data['target_id'] ?? 0) > 0) {
            return 'Cms\\Content\\Models\\Post';
        }

        return null;
    }

    /**
     * Build the same daily totals the legacy MySQL procedure returned.
     *
     * @return array{total_views:int,total_visitor:int,total_scan:int}
     */
    protected function summarizeTracksForRange(
        Carbon $start,
        Carbon $end,
        ?string $targetType = null,
        int|string|null $targetId = null
    ): array
    {
        $query = DB::table(Tables::TRACKS)
            ->whereBetween('created_at', [$start, $end]);

        if ($targetType !== null) {
            $query->where('target_type', $targetType);
        }

        if ($targetId !== null) {
            $query->where('target_id', $targetId);
        }

        return [
            'total_views' => (int)(clone $query)->sum('view'),
            'total_visitor' => (int)(clone $query)
                ->whereNotNull('session_id')
                ->distinct()
                ->count('session_id'),
            'total_scan' => (int)(clone $query)->where('tags', 'like', '%"scan"%')->count(),
        ];
    }

    protected function commentCounts(array $where): Collection
    {
        $comments = ($this->comment ??= app(CommentInterface::class))->model()
            ->where($where)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get();
        $counts = collect([
            'total' => 0,
            'pending' => 0,
            'confirmed' => 0,
            'rejected' => 0,
        ]);

        foreach ($comments as $comment) {
            if ($counts->has($comment->status)) {
                $counts->put($comment->status, $comment->total);
                $counts->put('total', $counts->get('total') + $comment->total);
            }
        }

        return $counts;
    }

    protected function analytics(): TrackingAnalyticsService
    {
        return $this->analytics ??= app(TrackingAnalyticsService::class);
    }

    /**
     * Most-viewed content (lifetime) from the canonical target totals.
     *
     * @return array<int, array{name:string,type:string,views:int,url:string|null}>
     */
    protected function topContent(int $limit = 8): array
    {
        if (!$this->tableExists(Tables::TRACKING_TARGET_TOTALS)) {
            return [];
        }

        $resolver = app(TrackingTargetKeyResolver::class);
        $take = max(1, min(20, $limit));

        // Candidate window: registered child types fold into their parents, so keep some headroom over $take.
        $rows = DB::table(Tables::TRACKING_TARGET_TOTALS)
            ->selectRaw('target_type, target_id, (baseline_views + live_views) as views')
            ->where('target_id', '>', 0)
            ->orderByDesc('views')
            ->limit(max($take * 5, 40))
            ->get();

        $top = $this->rollUps()->rollUp($rows)
            ->sortByDesc('views')
            ->take($take)
            ->values();

        $labels = $this->resolveContentLabels($top);

        return $top
            ->map(function (object $row) use ($resolver, $labels): array {
                $type = (string) $row->target_type;
                $id = (int) $row->target_id;
                $resolved = $resolver->resolve($type, $id, null, [], null);

                return [
                    'name' => $labels[$type . ':' . $id] ?? (class_basename($type) . ' #' . $id),
                    'type' => class_basename($type),
                    'views' => (int) $row->views,
                    'url' => data_get($resolved, 'public_path'),
                ];
            })
            ->all();
    }

    /**
     * Resolve labels for many targets with one query per target type.
     *
     * @param \Illuminate\Support\Collection<int, object> $rows Rows with target_type and target_id.
     * @return array<string, string> Map of "type:id" to a non-empty name.
     */
    protected function resolveContentLabels(Collection $rows): array
    {
        $labels = [];

        foreach ($rows->groupBy(static fn (object $row): string => (string) $row->target_type) as $type => $group) {
            try {
                if ($type === '' || !class_exists($type)) {
                    continue;
                }
                $table = (new $type)->getTable();
                if (!$this->tableExists($table)) {
                    continue;
                }
                // Most targets carry a `name`; library books, courses, chapters and help articles carry a `title`.
                $labelColumn = collect(['name', 'title'])->first(fn (string $column): bool => $this->columnExists($table, $column));
                if ($labelColumn === null) {
                    continue;
                }
                $ids = $group->pluck('target_id')->map(static fn (mixed $id): int => (int) $id)->unique()->all();
                foreach (DB::table($table)->whereIn('id', $ids)->pluck($labelColumn, 'id') as $id => $name) {
                    if (is_string($name) && trim($name) !== '') {
                        $labels[$type . ':' . $id] = $name;
                    }
                }
            } catch (Throwable) {
                // fall through to the generic label
            }
        }

        return $labels;
    }

    /**
     * Top targets per range for the dashboard report, read straight from the
     * precomputed tracking_top cache (rebuilt nightly per type by
     * cms:tracking-top --all-targets). Query params: range (7d|1m|3m|6m|1y),
     * type (class basename, defaults to the busiest type), limit (1..50).
     * Without the Content module it answers the same payload shape with
     * empty lists (the dashboard reads every key).
     */
    public function topTargets(): mixed
    {
        $ranges = ['7d', '1m', '3m', '6m', '1y'];
        $range = in_array(request('range'), $ranges, true) ? request('range') : '7d';

        if (!$this->moduleIsEnabled('Content')) {
            return ires()->data([
                'range' => $range,
                'ranges' => $ranges,
                'type' => '',
                'types' => [],
                'rangeStart' => null,
                'rangeEnd' => null,
                'rangeTotalViews' => 0,
                'items' => [],
            ]);
        }

        $limit = max(1, min(50, (int) request('limit', 10)));

        $typeRows = TrackingTop::query()
            ->where('range_name', $range)
            ->selectRaw('target_type, SUM(total_views) as views, MAX(range_end) as range_end')
            ->groupBy('target_type')
            ->orderByDesc('views')
            ->get();

        $classByBase = $typeRows->mapWithKeys(
            static fn (object $row): array => [class_basename((string) $row->target_type) => (string) $row->target_type]
        );

        $selectedBase = $classByBase->has((string) request('type'))
            ? (string) request('type')
            : (string) $classByBase->keys()->first();

        $items = $selectedBase === ''
            ? collect()
            : $this->topTargetItems($range, $selectedBase, $classByBase, $limit);
        $resolver = app(TrackingTargetKeyResolver::class);
        $pagePaths = $this->pagePathsFor($items);

        // Coverage of the ranked window against the site's total traffic.
        $rangeStart = TrackingTop::query()->where('range_name', $range)->min('range_start');
        $rangeEnd = $typeRows->max('range_end');
        $rangeStart = $rangeStart === null ? null : Carbon::parse($rangeStart)->toDateString();
        $rangeEnd = $rangeEnd === null ? null : Carbon::parse($rangeEnd)->toDateString();
        $rangeTotalViews = $rangeStart === null ? 0 : (int) DB::table(Tables::TRACKING_DAILY)
            ->whereBetween('date', [$rangeStart, $rangeEnd])
            ->sum('views');

        return ires()->data([
            'range' => $range,
            'ranges' => $ranges,
            'type' => $selectedBase,
            'types' => $typeRows->map(static fn (object $row): array => [
                'type' => class_basename((string) $row->target_type),
                'views' => (int) $row->views,
            ])->values()->all(),
            'rangeStart' => $rangeStart,
            'rangeEnd' => $rangeEnd,
            'rangeTotalViews' => $rangeTotalViews,
            'items' => $items->values()->map(function (object $row, int $index) use ($resolver, $pagePaths): array {
                $type = (string) $row->target_type;
                $id = (int) $row->target_id;

                if ($type === TopRangeAggregationJob::ROUTE_TYPE) {
                    $key = (string) $row->target_key;

                    return [
                        'rank' => $index + 1,
                        ...$this->resolvePageTarget($key, $pagePaths->get($key)),
                        'type' => $type,
                        'views' => (int) $row->views,
                        'target_id' => $id,
                    ];
                }

                return [
                    'rank' => $index + 1,
                    'name' => $this->resolveContentLabel($type, $id),
                    'type' => class_basename($type),
                    'views' => (int) $row->views,
                    'url' => data_get($resolver->resolve($type, $id, null, [], null), 'public_path'),
                    'target_id' => $id,
                ];
            })->all(),
        ]);
    }

    /**
     * Ranked rows for one type. A parent type with registered child types
     * (see TrackingTargetRollUpRegistry) folds the children's top lists into
     * its rows, the same read-time roll-up as the lifetime leaderboard; every
     * other type is a straight ranked read.
     *
     * @param \Illuminate\Support\Collection<string, string> $classByBase
     * @return \Illuminate\Support\Collection<int, object>
     */
    protected function topTargetItems(string $range, string $selectedBase, Collection $classByBase, int $limit): Collection
    {
        $selectedClass = $classByBase->get($selectedBase);
        $classes = [$selectedClass];

        foreach ($this->rollUps()->childTypesOf((string) $selectedClass) as $childClass) {
            if ($classByBase->contains($childClass)) {
                $classes[] = $childClass;
            }
        }

        $rows = TrackingTop::query()
            ->where('range_name', $range)
            ->whereIn('target_type', $classes)
            ->orderBy('rank')
            ->limit(count($classes) > 1 ? max($limit * 5, 100) : $limit)
            ->get(['target_key', 'target_type', 'target_id', 'total_views'])
            ->map(static fn (object $row): object => (object) [
                'target_key' => (string) $row->target_key,
                'target_type' => (string) $row->target_type,
                'target_id' => (int) $row->target_id,
                'views' => (int) $row->total_views,
            ]);

        if (count($classes) > 1) {
            $rows = $this->rollUps()->rollUp($rows);
        }

        return $rows->sortByDesc('views')->take($limit);
    }

    protected function rollUps(): TrackingTargetRollUpRegistry
    {
        return app(TrackingTargetRollUpRegistry::class);
    }

    /**
     * Lifetime views grouped by content type (for the report pie/column chart).
     *
     * @return array<int, array{type:string,label:string,views:int}>
     */
    protected function viewsByType(): array
    {
        if (!$this->tableExists(Tables::TRACKING_TARGET_TOTALS)) {
            return [];
        }

        $labels = [
            'Post' => 'text.rp.ctype-post',
            'Category' => 'text.rp.ctype-category',
            'Upload' => 'text.rp.ctype-upload',
            'Gallery' => 'text.rp.ctype-gallery',
            'Content' => 'text.rp.ctype-content',
            'Document' => 'text.rp.ctype-upload',
            'Place' => 'text.rp.ctype-place',
        ] + $this->rollUps()->typeLabels();

        return DB::table(Tables::TRACKING_TARGET_TOTALS)
            ->selectRaw('target_type, SUM(baseline_views + live_views) as views')
            ->groupBy('target_type')
            ->orderByDesc('views')
            ->get()
            ->map(function (object $row) use ($labels): array {
                //  A null target_type is a hashed path target: page views of
                //  URLs with no backing entity (home, wiki, search, ...).
                //  Without its own bucket they all read as an opaque "Other".
                $base = class_basename((string) $row->target_type) ?: 'Page';

                return [
                    'type' => $base,
                    'label' => __($labels[$base]
                        ?? ($base === 'Page' ? 'text.rp.ctype-page' : 'text.rp.ctype-other')),
                    'views' => (int) $row->views,
                ];
            })
            ->filter(static fn (array $item): bool => $item['views'] > 0)
            ->values()
            ->all();
    }

    /**
     * Resolve a human label for a tracking target by reading its model `name` column.
     */
    /**
     * Page targets carry no model row, so their label and link come either
     * from the target catalog (hashed path targets, `path:sha256:<hash>`) or
     * from the router (named route targets, `route:<name>`).
     *
     * @return array{name: string, url: string|null}
     */
    protected function resolvePageTarget(string $targetKey, ?string $publicPath = null): array
    {
        //  The hash in a path target is one-way; the readable path only ever
        //  comes from the catalog row the writer stored alongside it.
        if (is_string($publicPath) && $publicPath !== '') {
            return [
                'name' => $publicPath,
                'url' => $publicPath,
            ];
        }

        if (!Str::startsWith($targetKey, 'route:')) {
            return [
                'name' => $targetKey,
                'url' => null,
            ];
        }

        $routeName = Str::after($targetKey, 'route:');
        $path = null;

        try {
            if ($routeName !== '' && Route::has($routeName)) {
                $path = route($routeName, [], false);
            }
        } catch (Throwable) {
            // an unnamed or parameterised route just keeps its route name
        }

        return [
            'name' => $path ?? $routeName,
            'url' => $path,
        ];
    }

    /**
     * Readable paths for the page rows of one leaderboard, in a single query.
     *
     * @param \Illuminate\Support\Collection<int, object> $items
     * @return \Illuminate\Support\Collection<string, string>
     */
    protected function pagePathsFor(Collection $items): Collection
    {
        $keys = $items
            ->filter(static fn (object $row): bool =>
                (string) $row->target_type === TopRangeAggregationJob::ROUTE_TYPE)
            ->map(static fn (object $row): string => (string) $row->target_key)
            ->values()
            ->all();

        if ($keys === [] || !$this->tableExists(Tables::TRACKING_TARGET_CATALOG)) {
            return collect();
        }

        return DB::table(Tables::TRACKING_TARGET_CATALOG)
            ->whereIn('target_key', $keys)
            ->pluck('public_path', 'target_key')
            ->filter(static fn (mixed $path): bool => is_string($path) && $path !== '');
    }

    protected function resolveContentLabel(string $type, int $id): string
    {
        try {
            if ($type !== '' && class_exists($type)) {
                $table = (new $type)->getTable();
                if ($this->tableExists($table) && $this->columnExists($table, 'name')) {
                    $name = DB::table($table)->where('id', $id)->value('name');
                    if (is_string($name) && trim($name) !== '') {
                        return $name;
                    }
                }
            }
        } catch (Throwable) {
            // fall through to the generic label
        }

        return class_basename($type) . ' #' . $id;
    }
}
