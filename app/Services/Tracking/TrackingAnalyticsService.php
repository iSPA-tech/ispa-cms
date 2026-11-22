<?php

namespace App\Services\Tracking;

use App\Constants\Tables;
use App\Models\Tracking\TrackingSiteTotal;
use App\Models\Tracking\TrackingTargetDaily;
use App\Models\Tracking\TrackingTargetTotal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TrackingAnalyticsService
{
    public function __construct(private readonly TrackingTargetKeyResolver $targets)
    {
    }

    public function readMode(): string
    {
        $mode = (string) config('tracking.read_mode', 'raw');
        if (!in_array($mode, ['raw', 'aggregate'], true)) {
            throw new InvalidArgumentException("Unsupported tracking read mode [{$mode}].");
        }

        return $mode;
    }

    public function cacheNamespace(string $consumer): string
    {
        return implode(':', [
            'tracking',
            $consumer,
            $this->readMode(),
            'v' . (int) config('tracking.aggregate_contract_version', 1),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function chart(
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $targetType = null,
        ?int $targetId = null
    ): array {
        $timezone = (string) config('tracking.analytics_timezone', 'Asia/Ho_Chi_Minh');
        $startDate = CarbonImmutable::parse($start, $timezone)->startOfDay();
        $endDate = CarbonImmutable::parse($end, $timezone)->startOfDay();
        if ($endDate->lessThan($startDate) || $startDate->diffInDays($endDate) > 366) {
            throw new InvalidArgumentException('Tracking chart range must be ordered and bounded.');
        }

        $mode = $this->readMode();
        $targetKey = $this->targetKey($targetType, $targetId);
        $boundary = $mode === 'aggregate'
            ? $this->exactBoundary($targetKey)
            : null;
        $stored = $mode === 'aggregate'
            ? $this->aggregateDaily($startDate, $endDate, $targetKey)
            : $this->rawDaily($startDate, $endDate, $targetType, $targetId);

        $dates = [];
        $views = [];
        $visitors = [];
        $scans = [];
        $missing = ['views' => [], 'visitors' => [], 'scans' => []];
        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            $dateKey = $date->format('Y-m-d');
            $dates[] = $dateKey;
            $row = $stored[$dateKey] ?? null;
            $isExact = $boundary !== null && $dateKey >= $boundary;
            foreach ([
                'views' => &$views,
                'visitors' => &$visitors,
                'scans' => &$scans,
            ] as $metric => &$series) {
                if ($row === null) {
                    $series[] = $isExact ? 0 : null;
                    if (!$isExact) {
                        $missing[$metric][] = $dateKey;
                    }
                } else {
                    $series[] = (int) $row[$metric];
                }
            }
            unset($series);
        }

        $quality = $this->rangeQuality($startDate, $endDate, $boundary);
        $knownRangeTotal = array_sum(array_filter(
            $views,
            static fn (?int $value): bool => $value !== null
        ));
        $rangeIsExact = $quality === 'exact';
        $lifetime = $this->lifetime($targetKey, $targetType, $targetId);

        return [
            'dates' => $dates,
            'views' => $views,
            'visitors' => $visitors,
            'scans' => $scans,
            'data_quality' => $quality,
            'metric_quality' => [
                'views' => $quality,
                'visitors' => $quality,
                'scans' => $quality,
            ],
            'exact_from_date' => $boundary,
            'timezone' => $timezone,
            'range_total' => $rangeIsExact ? $knownRangeTotal : null,
            'known_range_total' => $knownRangeTotal,
            'range_total_quality' => $rangeIsExact
                ? 'exact'
                : ($quality === 'mixed' ? 'mixed_known_only' : 'legacy_best_effort'),
            'total_views' => $lifetime['views'],
            'total_scope' => $lifetime['scope'],
            'legacy_missing_dates' => $missing,
            'read_mode' => $mode,
            'aggregate_contract_version' => (int) config(
                'tracking.aggregate_contract_version',
                1
            ),
        ];
    }

    /**
     * @return array{total_view:int,total_visit:int,total_scan:int,data_quality:string,total_scope:string}
     */
    public function siteTotals(): array
    {
        if ($this->readMode() === 'raw') {
            $views = (int) DB::table(Tables::TRACKING_DAILY)->sum('views');
            $visitors = (int) DB::table(Tables::TRACKING_DAILY)->sum('visitors');
            $visitSemantics = 'daily_unique_visitor_sum';
            if ($views === 0 && $visitors === 0) {
                $views = (int) DB::table(Tables::TRACKS)->sum('view');
                $visitors = (int) DB::table(Tables::TRACKS)
                    ->whereNotNull('session_id')
                    ->distinct()
                    ->count('session_id');
                $visitSemantics = 'lifetime_unique_session';
            }

            return [
                'total_view' => $views,
                'total_visit' => $visitors,
                'total_scan' => (int) DB::table(Tables::TRACKS)
                    ->where('tags', 'like', '%"scan"%')
                    ->count(),
                'data_quality' => 'legacy_best_effort',
                'total_scope' => 'best_effort_lifetime',
                'total_visit_semantics' => $visitSemantics,
                'metric_quality' => [
                    'views' => 'legacy_best_effort',
                    'visitors' => 'legacy_best_effort',
                    'scans' => 'legacy_best_effort',
                ],
            ];
        }

        $site = TrackingSiteTotal::query()->whereKey(1)->firstOrFail();

        return [
            'total_view' => (int) $site->baseline_views + (int) $site->live_views,
            'total_visit' => (int) DB::table(Tables::TRACKING_DAILY)->sum('visitors'),
            'total_scan' => (int) $site->baseline_scans + (int) $site->live_scans,
            ...$this->aggregateTotalMetadata(
                $site->baseline_quality,
                $site->scans_quality,
                $site->accurate_from_date?->toDateString(),
                'daily_unique_visitor_sum'
            ),
        ];
    }

    /**
     * @return array{total_view:int,total_visit:int,total_scan:int,data_quality:string,total_scope:string}
     */
    public function targetTotals(
        string $targetType,
        int $targetId,
        string $visitIdentity = 'session'
    ): array
    {
        $rawVisitColumn = $this->rawVisitColumn($visitIdentity);
        if ($this->readMode() === 'raw') {
            $query = DB::table(Tables::TRACKS)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId);
            $pageViews = (clone $query)->where('tags', 'like', '%"page_view"%');

            return [
                'total_view' => (int) (clone $pageViews)->sum('view'),
                'total_visit' => (int) (clone $pageViews)
                    ->whereNotNull($rawVisitColumn)
                    ->distinct()
                    ->count($rawVisitColumn),
                'total_scan' => (int) (clone $query)
                    ->where('tags', 'like', '%"scan"%')
                    ->count(),
                'data_quality' => 'legacy_best_effort',
                'total_scope' => 'best_effort_lifetime',
                'total_visit_semantics' => "lifetime_unique_{$visitIdentity}",
                'metric_quality' => [
                    'views' => 'legacy_best_effort',
                    'visitors' => 'legacy_best_effort',
                    'scans' => 'legacy_best_effort',
                ],
            ];
        }

        $targetKey = $this->targetKey($targetType, $targetId);
        if ($targetKey === null) {
            return [
                'total_view' => 0,
                'total_visit' => 0,
                'total_scan' => 0,
                'data_quality' => 'unavailable',
                'total_scope' => 'since_exact_from',
                'total_visit_semantics' => "daily_unique_{$visitIdentity}_sum",
                'metric_quality' => [
                    'views' => 'unavailable',
                    'visitors' => 'unavailable',
                    'scans' => 'unavailable',
                ],
            ];
        }

        $total = TrackingTargetTotal::query()->where('target_key', $targetKey)->first();
        $dailyVisitColumn = $visitIdentity === 'visitor'
            ? 'total_visitors'
            : 'total_sessions';
        $daily = TrackingTargetDaily::query()
            ->where('target_key', $targetKey)
            ->selectRaw("COALESCE(SUM({$dailyVisitColumn}), 0) as visitors")
            ->selectRaw('COALESCE(SUM(total_scans), 0) as scans')
            ->first();

        return [
            'total_view' => (int) ($total?->baseline_views ?? 0)
                + (int) ($total?->live_views ?? 0),
            'total_visit' => (int) ($daily?->visitors ?? 0),
            'total_scan' => (int) ($total?->baseline_scans ?? 0)
                + (int) ($total?->live_scans ?? 0),
            ...$this->aggregateTotalMetadata(
                $total?->baseline_quality,
                $total?->scans_quality,
                $total?->accurate_from_date?->toDateString(),
                "daily_unique_{$visitIdentity}_sum"
            ),
        ];
    }

    /**
     * @param list<int> $targetIds
     *
     * @return array{total_view:int,total_visit:int,total_scan:int}
     */
    public function targetsTotals(string $targetType, array $targetIds): array
    {
        $targetIds = array_values(array_unique(array_filter(
            array_map('intval', $targetIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($targetIds === []) {
            return ['total_view' => 0, 'total_visit' => 0, 'total_scan' => 0];
        }

        if ($this->readMode() === 'raw') {
            $query = DB::table(Tables::TRACKS)
                ->where('target_type', $targetType)
                ->whereIn('target_id', $targetIds);
            $pageViews = (clone $query)->where('tags', 'like', '%"page_view"%');

            return [
                'total_view' => (int) (clone $pageViews)->sum('view'),
                'total_visit' => (int) (clone $pageViews)
                    ->whereNotNull('session_id')
                    ->distinct()
                    ->count('session_id'),
                'total_scan' => (int) (clone $query)
                    ->where('tags', 'like', '%"scan"%')
                    ->count(),
            ];
        }

        $keys = array_values(array_filter(array_map(
            fn (int $id): ?string => $this->targetKey($targetType, $id),
            $targetIds
        )));
        if ($keys === []) {
            return ['total_view' => 0, 'total_visit' => 0, 'total_scan' => 0];
        }

        $totals = TrackingTargetTotal::query()
            ->whereIn('target_key', $keys)
            ->selectRaw('COALESCE(SUM(baseline_views + live_views), 0) as views')
            ->selectRaw('COALESCE(SUM(baseline_scans + live_scans), 0) as scans')
            ->first();

        return [
            'total_view' => (int) ($totals?->views ?? 0),
            'total_visit' => (int) TrackingTargetDaily::query()
                ->whereIn('target_key', $keys)
                ->sum('total_visitors'),
            'total_scan' => (int) ($totals?->scans ?? 0),
        ];
    }

    /**
     * @return array<string, array{views:int,visitors:int,scans:int}>
     */
    private function aggregateDaily(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $targetKey
    ): array {
        $query = $targetKey === null
            ? DB::table(Tables::TRACKING_DAILY)->select([
                'date',
                'views',
                'visitors',
                'scans',
            ])
            : DB::table(Tables::TRACKING_TARGET_DAILY)
                ->where('target_key', $targetKey)
                ->selectRaw(
                    'date, total_views as views, total_visitors as visitors, total_scans as scans'
                );

        return $query
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->date => [
                    'views' => (int) $row->views,
                    'visitors' => (int) $row->visitors,
                    'scans' => (int) $row->scans,
                ],
            ])
            ->all();
    }

    /**
     * @return array<string, array{views:int,visitors:int,scans:int}>
     */
    private function rawDaily(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $targetType,
        ?int $targetId
    ): array {
        $result = [];
        foreach (CarbonPeriod::create($start, $end) as $date) {
            $query = DB::table(Tables::TRACKS)
                ->whereBetween('created_at', [
                    $date->copy()->startOfDay(),
                    $date->copy()->endOfDay(),
                ]);
            if ($targetType !== null && $targetId !== null) {
                $query->where('target_type', $targetType)->where('target_id', $targetId);
            }
            if (!(clone $query)->exists()) {
                continue;
            }

            $result[$date->format('Y-m-d')] = [
                'views' => (int) (clone $query)->sum('view'),
                'visitors' => (int) (clone $query)
                    ->whereNotNull('session_id')
                    ->distinct()
                    ->count('session_id'),
                'scans' => (int) (clone $query)
                    ->where('tags', 'like', '%"scan"%')
                    ->count(),
            ];
        }

        return $result;
    }

    private function targetKey(?string $targetType, ?int $targetId): ?string
    {
        if ($targetType === null || $targetId === null) {
            return null;
        }

        return data_get(
            $this->targets->resolve($targetType, $targetId, null, [], null),
            'target_key'
        );
    }

    private function exactBoundary(?string $targetKey): ?string
    {
        $boundary = $targetKey === null
            ? TrackingSiteTotal::query()->whereKey(1)->value('accurate_from_date')
            : TrackingTargetTotal::query()
                ->where('target_key', $targetKey)
                ->value('accurate_from_date');

        if ($boundary === null && $targetKey !== null) {
            $boundary = TrackingSiteTotal::query()->whereKey(1)->value('accurate_from_date');
        }

        return $boundary !== null
            ? CarbonImmutable::parse($boundary)->toDateString()
            : null;
    }

    private function rangeQuality(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $boundary
    ): string {
        if ($boundary === null) {
            return 'legacy_best_effort';
        }
        if ($end->toDateString() < $boundary) {
            return 'legacy_best_effort';
        }
        if ($start->toDateString() >= $boundary) {
            return 'exact';
        }

        return 'mixed';
    }

    private function rawVisitColumn(string $visitIdentity): string
    {
        return match ($visitIdentity) {
            'session' => 'session_id',
            'visitor' => 'ip_address',
            default => throw new InvalidArgumentException(
                "Unsupported tracking visit identity [{$visitIdentity}]."
            ),
        };
    }

    /**
     * @return array{
     *     data_quality:string,
     *     total_scope:string,
     *     total_visit_semantics:string,
     *     exact_from_date:?string,
     *     metric_quality:array{views:string,visitors:string,scans:string},
     *     metric_scope:array{views:string,visitors:string,scans:string}
     * }
     */
    private function aggregateTotalMetadata(
        ?string $viewBaselineQuality,
        ?string $scanBaselineQuality,
        ?string $exactFromDate,
        string $visitSemantics
    ): array {
        $hasBoundary = $exactFromDate !== null;
        $viewQuality = $viewBaselineQuality === 'verified'
            ? 'exact'
            : ($hasBoundary ? 'exact_since_boundary' : 'unavailable');
        $scanQuality = $scanBaselineQuality === 'verified'
            ? 'exact'
            : ($hasBoundary ? 'exact_since_boundary' : 'unavailable');
        $visitorQuality = $hasBoundary ? 'exact_since_boundary' : 'unavailable';
        $qualities = array_unique([$viewQuality, $visitorQuality, $scanQuality]);
        $quality = count($qualities) === 1 ? $qualities[0] : 'mixed';

        return [
            'data_quality' => $quality,
            'total_scope' => $quality === 'exact'
                ? 'lifetime'
                : ($quality === 'exact_since_boundary' ? 'since_exact_from' : 'mixed'),
            'total_visit_semantics' => $visitSemantics,
            'exact_from_date' => $exactFromDate,
            'metric_quality' => [
                'views' => $viewQuality,
                'visitors' => $visitorQuality,
                'scans' => $scanQuality,
            ],
            'metric_scope' => [
                'views' => $viewQuality === 'exact' ? 'lifetime' : 'since_exact_from',
                'visitors' => 'since_exact_from',
                'scans' => $scanQuality === 'exact' ? 'lifetime' : 'since_exact_from',
            ],
        ];
    }

    /**
     * @return array{views:int,scope:string}
     */
    private function lifetime(
        ?string $targetKey,
        ?string $targetType,
        ?int $targetId
    ): array
    {
        if ($this->readMode() === 'raw') {
            $totals = $targetType !== null && $targetId !== null
                ? $this->targetTotals($targetType, $targetId)
                : $this->siteTotals();

            return [
                'views' => $totals['total_view'],
                'scope' => $totals['total_scope'],
            ];
        }

        $total = $targetKey === null
            ? TrackingSiteTotal::query()->whereKey(1)->first()
            : TrackingTargetTotal::query()->where('target_key', $targetKey)->first();

        return [
            'views' => (int) ($total?->baseline_views ?? 0)
                + (int) ($total?->live_views ?? 0),
            'scope' => $total?->baseline_quality === 'verified'
                ? 'lifetime'
                : 'since_exact_from',
        ];
    }
}
