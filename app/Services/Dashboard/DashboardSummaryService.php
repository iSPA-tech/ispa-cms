<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Constants\Tables;
use App\Constants\UserType;
use App\Enums\UserStatusEnum;
use App\Http\Controllers\V2\Admin\Concerns\ChecksModuleAvailability;
use Carbon\Carbon;
use Cms\Ecommerce\Constants\EcommerceTableConstant;
use Cms\Ecommerce\Services\Order\OrderStatusGroups;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds the aggregate-only payload for the main admin dashboard.
 *
 * Every block is one aggregate query; fact tables are never loaded row by row.
 */
class DashboardSummaryService
{
    use ChecksModuleAvailability;

    /**
     * Return the cached summary payload.
     *
     * @param string      $range  One of 7d, 30d, 90d, custom.
     * @param string|null $from   Y-m-d start (custom only).
     * @param string|null $to     Y-m-d end (custom only).
     * @param bool        $reload Bypass the cache when true.
     *
     * @return array<string,mixed>
     */
    public function summary(string $range = '7d', ?string $from = null, ?string $to = null, bool $reload = false): array
    {
        [$range, $start, $end] = $this->resolveRange($range, $from, $to);
        $key = "admin.dashboard.summary.{$range}.{$start->toDateString()}.{$end->toDateString()}";

        if ($reload) {
            cache()->forget($key);
        }

        return cache()->remember($key, 300, fn (): array => $this->build($start, $end));
    }

    /**
     * Resolve the requested range into normalised start/end instants.
     *
     * @return array{0:string,1:Carbon,2:Carbon}
     */
    protected function resolveRange(string $range, ?string $from, ?string $to): array
    {
        $days = ['7d' => 7, '30d' => 30, '90d' => 90][$range] ?? null;

        if ($range === 'custom') {
            try {
                $start = Carbon::createFromFormat('Y-m-d', (string) $from)->startOfDay();
                $end = Carbon::createFromFormat('Y-m-d', (string) $to)->endOfDay();

                if ($start->lte($end) && $start->diffInDays($end) <= 366) {
                    return ['custom', $start, $end];
                }
            } catch (Throwable) {
                // Fall through to the default range.
            }

            $days = 7;
            $range = '7d';
        }

        $days ??= 7;

        return [$days . 'd', now()->subDays($days - 1)->startOfDay(), now()->endOfDay()];
    }

    /**
     * Assemble every dashboard block.
     *
     * @return array<string,mixed>
     */
    protected function build(Carbon $from, Carbon $to): array
    {
        $length = (int) $from->diffInDays($to->copy()->startOfDay()) + 1;
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($length - 1)->startOfDay();

        $content = $this->moduleIsEnabled('Content') && $this->tableExists(Tables::POSTS);
        $ecommerce = $this->moduleIsEnabled('Ecommerce') && $this->tableExists(EcommerceTableConstant::ORDER);
        $tracking = $this->tableExists(Tables::TRACKING_DAILY);
        $comments = $this->tableExists(Tables::COMMENTS);

        $commentsBlock = $comments ? $this->comments($from, $to) : null;
        $contentBlock = $content ? $this->content($from, $to) : null;
        $commerceBlock = $ecommerce ? $this->commerce($from, $to, $prevFrom, $prevTo) : null;

        [$views, $visitors, $sessions, $scans] = $tracking ? $this->viewsSeries($from, $to) : [[], [], [], []];

        // Per-user attention entries (Content, Ecommerce, ...) come from the AttentionRegistry in the controller.
        $attention = [];

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_from' => $prevFrom->toDateString(),
                'previous_to' => $prevTo->toDateString(),
            ],
            'traffic' => $tracking ? [...$this->traffic($from, $to, $prevFrom, $prevTo), 'lifetime' => $this->lifetime()] : null,
            'content' => $contentBlock,
            'comments' => $commentsBlock,
            'commerce' => $commerceBlock,
            'people' => $this->people($from, $to, $ecommerce),
            'attention' => $attention,
            'notices' => $this->notices(),
            'series' => [
                'views' => $views,
                'visitors' => $visitors,
                'sessions' => $sessions,
                'scans' => $scans,
                'orders' => $ecommerce ? $this->ordersSeries($from, $to) : [],
            ],
            'modules' => [
                'content' => $content,
                'ecommerce' => $ecommerce,
                'education' => $this->moduleIsEnabled('Education'),
                'tracking' => $tracking,
            ],
        ];
    }

    /**
     * Traffic totals for the range and the previous range in one query.
     *
     * @return array<string,mixed>
     */
    protected function traffic(Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $cur = "date BETWEEN ? AND ?";
        $row = DB::table(Tables::TRACKING_DAILY)
            ->whereBetween('date', [$prevFrom->toDateString(), $to->toDateString()])
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN $cur THEN views ELSE 0 END),0) v,
                 COALESCE(SUM(CASE WHEN $cur THEN visitors ELSE 0 END),0) vis,
                 COALESCE(SUM(CASE WHEN $cur THEN sessions ELSE 0 END),0) s,
                 COALESCE(SUM(CASE WHEN $cur THEN scans ELSE 0 END),0) sc,
                 COALESCE(SUM(CASE WHEN $cur THEN download ELSE 0 END),0) d,
                 COALESCE(SUM(CASE WHEN date BETWEEN ? AND ? THEN views ELSE 0 END),0) pv,
                 COALESCE(SUM(CASE WHEN date BETWEEN ? AND ? THEN visitors ELSE 0 END),0) pvis,
                 COALESCE(SUM(CASE WHEN date BETWEEN ? AND ? THEN sessions ELSE 0 END),0) ps",
                [
                    ...$this->pair($from, $to), ...$this->pair($from, $to), ...$this->pair($from, $to),
                    ...$this->pair($from, $to), ...$this->pair($from, $to),
                    ...$this->pair($prevFrom, $prevTo), ...$this->pair($prevFrom, $prevTo), ...$this->pair($prevFrom, $prevTo),
                ]
            )
            ->first();

        return [
            'views' => (int) $row->v,
            'visitors' => (int) $row->vis,
            'sessions' => (int) $row->s,
            'scans' => (int) $row->sc,
            'downloads' => (int) $row->d,
            'previous' => ['views' => (int) $row->pv, 'visitors' => (int) $row->pvis, 'sessions' => (int) $row->ps],
        ];
    }

    /**
     * Date pair bindings helper.
     *
     * @return array{0:string,1:string}
     */
    private function pair(Carbon $a, Carbon $b): array
    {
        return [$a->toDateString(), $b->toDateString()];
    }

    /**
     * Post counts by status plus posts created in range.
     *
     * @return array<string,int>
     */
    protected function content(Carbon $from, Carbon $to): array
    {
        $row = DB::table(Tables::POSTS)->whereNull('deleted_at')->selectRaw(
            "COUNT(*) total,
             COALESCE(SUM(CASE WHEN status = 'status_published' THEN 1 ELSE 0 END),0) pub,
             COALESCE(SUM(CASE WHEN status = 'status_draft' THEN 1 ELSE 0 END),0) draft,
             COALESCE(SUM(CASE WHEN status = 'status_pending' THEN 1 ELSE 0 END),0) pend,
             COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END),0) new_posts",
            [$from, $to]
        )->first();

        return [
            'posts_total' => (int) $row->total,
            'posts_published' => (int) $row->pub,
            'posts_draft' => (int) $row->draft,
            'posts_pending' => (int) $row->pend,
            'posts_new' => (int) $row->new_posts,
        ];
    }

    /**
     * Comment counts by moderation status plus comments created in range. The public form saves `status_pending` and
     * approving publishes `status_published`, so each count matches what the comment list shows for that status.
     *
     * @return array<string,int>
     */
    protected function comments(Carbon $from, Carbon $to): array
    {
        $row = DB::table(Tables::COMMENTS)->whereNull('deleted_at')->selectRaw(
            "COUNT(*) total,
             COALESCE(SUM(CASE WHEN status = 'status_pending' THEN 1 ELSE 0 END),0) pend,
             COALESCE(SUM(CASE WHEN status = 'status_published' THEN 1 ELSE 0 END),0) conf,
             COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END),0) rej,
             COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END),0) new_c",
            [$from, $to]
        )->first();

        $total = (int) $row->total;

        return [
            'total' => $total,
            'pending' => (int) $row->pend,
            'confirmed' => (int) $row->conf,
            'rejected' => (int) $row->rej,
            'new' => (int) $row->new_c,
        ];
    }

    /**
     * Build the SQL "any status column is in list" predicate for an order bucket.
     *
     * @param string $group Key of OrderStatusGroups::all().
     */
    private function anyStatusIn(string $group): string
    {
        // A site may run an older Ecommerce (installed from the hub) whose groups lack a newer bucket such as `draft`:
        // a missing group matches nothing instead of breaking the whole dashboard.
        $values = array_values(array_filter((array) (OrderStatusGroups::all()[$group] ?? []), 'is_string'));
        if ($values === []) {
            return '(1 = 0)';
        }
        $list = "'" . implode("','", array_map(static fn (string $value): string => str_replace("'", "''", $value), $values)) . "'";

        return '(' . implode(' OR ', array_map(
            static fn (string $col): string => "LOWER(TRIM(COALESCE($col,''))) IN ($list)",
            ['order_status', 'payment_status', 'fulfillment_status']
        )) . ')';
    }

    /**
     * Order bucket aggregates for a single period.
     *
     * @return array{new:int,processing:int,completed:int,cancelled:int,paid_count:int,revenue:float}
     */
    protected function ordersPeriod(Carbon $from, Carbon $to): array
    {
        $canceled = $this->anyStatusIn('canceled');
        $completed = $this->anyStatusIn('completed');
        $progress = $this->anyStatusIn('in_progress');
        $draft = $this->anyStatusIn('draft');
        $paid = $this->anyStatusIn('paid');

        $row = DB::table(EcommerceTableConstant::ORDER)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                "COUNT(*) total,
                 COALESCE(SUM(CASE WHEN NOT $canceled AND NOT $completed AND NOT $draft AND $progress THEN 1 ELSE 0 END),0) proc,
                 COALESCE(SUM(CASE WHEN NOT $canceled AND $completed THEN 1 ELSE 0 END),0) done,
                 COALESCE(SUM(CASE WHEN $canceled THEN 1 ELSE 0 END),0) canc,
                 COALESCE(SUM(CASE WHEN NOT $canceled AND $paid THEN 1 ELSE 0 END),0) paid_n,
                 COALESCE(SUM(CASE WHEN NOT $canceled AND $paid THEN total_paid ELSE 0 END),0) rev"
            )->first();

        return [
            'new' => (int) $row->total,
            'processing' => (int) $row->proc,
            'completed' => (int) $row->done,
            'cancelled' => (int) $row->canc,
            'paid_count' => (int) $row->paid_n,
            'revenue' => (float) $row->rev,
        ];
    }

    /**
     * Commerce block for the current and previous period.
     *
     * @return array<string,mixed>
     */
    protected function commerce(Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo): array
    {
        $cur = $this->ordersPeriod($from, $to);
        $prev = $this->ordersPeriod($prevFrom, $prevTo);

        return [
            'orders_new' => $cur['new'],
            'orders_processing' => $cur['processing'],
            'orders_completed' => $cur['completed'],
            'orders_cancelled' => $cur['cancelled'],
            'revenue_paid' => $cur['revenue'],
            'aov' => $cur['paid_count'] > 0 ? round($cur['revenue'] / $cur['paid_count'], 2) : 0,
            'previous' => ['revenue_paid' => $prev['revenue'], 'orders_new' => $prev['new']],
        ];
    }

    /**
     * Users and customers counts.
     *
     * @return array<string,int|null>
     */
    protected function people(Carbon $from, Carbon $to, bool $ecommerce): array
    {
        $usersExist = $this->tableExists(Tables::USERS);
        $row = ! $usersExist ? null : DB::table(Tables::USERS)->whereNull('deleted_at')->selectRaw(
            "COUNT(*) total,
             COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END),0) new_u,
             COALESCE(SUM(CASE WHEN type = ? THEN 1 ELSE 0 END),0) students,
             COALESCE(SUM(CASE WHEN type = ? AND email_verified_at IS NOT NULL AND status = ? THEN 1 ELSE 0 END),0) active",
            [$from, $to, UserType::CLIENT, UserType::CLIENT, UserStatusEnum::USER_ACTIVE->value]
        )->first();

        $customers = null;
        if ($ecommerce && $this->tableExists(EcommerceTableConstant::CUSTOMER_PROFILE)) {
            $customers = (int) DB::table(EcommerceTableConstant::CUSTOMER_PROFILE)->whereNull('deleted_at')->count();
        }

        return [
            'users_total' => (int) ($row->total ?? 0),
            'users_new' => (int) ($row->new_u ?? 0),
            'students_total' => (int) ($row->students ?? 0),
            'students_active' => (int) ($row->active ?? 0),
            'customers_total' => $customers,
            'contacts_total' => $this->tableExists(Tables::CONTACTS)
                ? (int) DB::table(Tables::CONTACTS)->whereNull('deleted_at')->count()
                : 0,
        ];
    }

    /**
     * Daily views, visitors, sessions and scans series from one tracking_daily query (the period tiles draw them).
     *
     * @return array{0:list<array{0:string,1:int}>,1:list<array{0:string,1:int}>,2:list<array{0:string,1:int}>,3:list<array{0:string,1:int}>}
     */
    protected function viewsSeries(Carbon $from, Carbon $to): array
    {
        $rows = DB::table(Tables::TRACKING_DAILY)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('date d, SUM(views) n, SUM(visitors) v, SUM(sessions) s, SUM(scans) sc')->groupBy('date')->get();

        return [
            $this->fillDays($from, $to, $rows->pluck('n', 'd')->all()),
            $this->fillDays($from, $to, $rows->pluck('v', 'd')->all()),
            $this->fillDays($from, $to, $rows->pluck('s', 'd')->all()),
            $this->fillDays($from, $to, $rows->pluck('sc', 'd')->all()),
        ];
    }

    /**
     * Lifetime traffic totals: tracking_daily sums, with views and scans taken
     * from tracking_site_totals (baseline + live) in aggregate read mode only.
     * In raw read mode that row keeps its migration seed (0/0) because only the
     * aggregate writers grow live_views, so tracking_daily is the source there
     * (same split as TrackingAnalyticsService::siteTotals()).
     *
     * @return array<string,int>
     */
    protected function lifetime(): array
    {
        $row = DB::table(Tables::TRACKING_DAILY)->selectRaw(
            'COALESCE(SUM(views),0) v, COALESCE(SUM(visitors),0) vis, COALESCE(SUM(sessions),0) s,
             COALESCE(SUM(download),0) d, COALESCE(SUM(scans),0) sc'
        )->first();

        $out = [
            'views' => (int) $row->v,
            'visitors' => (int) $row->vis,
            'sessions' => (int) $row->s,
            'downloads' => (int) $row->d,
            'scans' => (int) $row->sc,
        ];

        if (config('tracking.read_mode', 'raw') === 'aggregate' && $this->tableExists(Tables::TRACKING_SITE_TOTALS)) {
            $site = DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)
                ->selectRaw('baseline_views + live_views v, baseline_scans + live_scans sc')->first();
            if ($site !== null) {
                $out['views'] = (int) $site->v;
                $out['scans'] = (int) $site->sc;
            }
        }

        return $out;
    }

    /**
     * Settings hints shown above the dashboard (same check the legacy banner used).
     *
     * @return list<array{key:string,route:string,hash:string}>
     */
    protected function notices(): array
    {
        return config('setting.api.GOOGLE_RECAPTCHA_SITE_KEY')
            ? []
            : [['key' => 'captcha_missing', 'route' => 'setting-advanced', 'hash' => '#api-setting']];
    }

    /**
     * Daily orders series.
     *
     * @return list<array{0:string,1:int}>
     */
    protected function ordersSeries(Carbon $from, Carbon $to): array
    {
        $rows = DB::table(EcommerceTableConstant::ORDER)->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd')->all();

        return $this->fillDays($from, $to, $rows);
    }

    /**
     * Zero-fill a per-day series.
     *
     * @param array<string,mixed> $rows Counts keyed by Y-m-d.
     *
     * @return list<array{0:string,1:int}>
     */
    private function fillDays(Carbon $from, Carbon $to, array $rows): array
    {
        $out = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $out[] = [$key, (int) ($rows[$key] ?? 0)];
        }

        return $out;
    }
}
