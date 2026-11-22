<?php

namespace App\Jobs;

use App\Constants\Tables;
use App\Models\Comment;
use App\Models\Tracking\Track;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\Carbon;
use Cms\Core\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use LogicException;

class DailyTrackingJob implements ShouldQueue
{
    use Queueable;

    protected string $dateColumn = 'updated_at';

    public function __construct(public ?Carbon $date = null)
    {
    }

    public function handle(): void
    {
        $date = ($this->date ?? Carbon::yesterday())->copy()->startOfDay();
        $analyticsToday = Carbon::now((string) config('tracking.analytics_timezone'))
            ->startOfDay();
        if ($date->greaterThanOrEqualTo($analyticsToday)) {
            throw new LogicException('Tracking finalizer cannot close the current analytics day.');
        }

        $endOfDay = $date->copy()->endOfDay();
        $raw = [
            'views' => (int) Track::query()
                ->whereBetween($this->dateColumn, [$date, $endOfDay])
                ->sum('view'),
            'sessions' => (int) Track::query()
                ->whereBetween($this->dateColumn, [$date, $endOfDay])
                ->distinct()
                ->count('session_id'),
            'visitors' => (int) Track::query()
                ->whereBetween($this->dateColumn, [$date, $endOfDay])
                ->distinct()
                ->count('ip_address'),
            'scans' => (int) Track::query()
                ->whereBetween($this->dateColumn, [$date, $endOfDay])
                ->whereJsonContains('tags', 'scan')
                ->count(),
            'place_scan' => (int) Track::query()
                ->whereBetween($this->dateColumn, [$date, $endOfDay])
                ->whereJsonContains('tags', 'scan')
                ->whereJsonContains('tags', 'place')
                ->count(),
            'item_scan' => (int) Track::query()
                ->whereBetween($this->dateColumn, [$date, $endOfDay])
                ->whereJsonContains('tags', 'scan')
                ->whereJsonContains('tags', 'item')
                ->count(),
            'download' => (int) Track::query()
                ->whereBetween($this->dateColumn, [$date, $endOfDay])
                ->where(function ($query): void {
                    $query->whereJsonContains('tags', 'download')
                        ->orWhereJsonContains('tags', 'downloads');
                })
                ->count(),
        ];

        $logs = AuditLog::query()
            ->whereBetween($this->dateColumn, [$date, $endOfDay])
            ->whereIn('action', [
                'action.register',
                'action.sign-in',
                'action.sign-out',
                'action.subscribe',
                'action.unsubscribe',
            ])
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');
        $nonTracking = [
            'login' => (int) ($logs['action.sign-in'] ?? 0),
            'logout' => (int) ($logs['action.sign-out'] ?? 0),
            'new_register' => (int) ($logs['action.register'] ?? 0),
            'subscribe' => (int) ($logs['action.subscribe'] ?? 0),
            'unsubscribe' => (int) ($logs['action.unsubscribe'] ?? 0),
            'comments' => Comment::query()
                ->whereBetween($this->dateColumn, [$date, $endOfDay])
                ->count(),
        ];

        DB::transaction(function () use ($date, $raw, $nonTracking): void {
            $state = TrackingRuntimeState::query()->whereKey(1)->sharedLock()->firstOrFail();
            $dateString = $date->toDateString();
            $now = now('UTC');

            DB::table(Tables::TRACKING_DAILY)->insertOrIgnore([
                'date' => $dateString,
                'is_closed' => false,
                'aggregate_version' => (int) config('tracking.aggregate_contract_version', 1),
                'late_event_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $updates = [
                ...$nonTracking,
                'is_closed' => true,
                'aggregated_at' => $now,
                'updated_at' => $now,
            ];
            if ($this->legacyOwnsDate($state, $dateString)) {
                $updates = [...$updates, ...$raw];
            }

            DB::table(Tables::TRACKING_DAILY)
                ->whereDate('date', $dateString)
                ->update($updates);
        });
    }

    private function legacyOwnsDate(TrackingRuntimeState $state, string $date): bool
    {
        if ($state->direct_counter_owner === 'legacy') {
            return true;
        }

        return $state->first_direct_write_date !== null
            && $date < $state->first_direct_write_date->toDateString();
    }
}
