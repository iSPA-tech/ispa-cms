<?php

declare(strict_types=1);

namespace Cms\Core\Services\Backup;

use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Cms\Core\Models\Backup\BackupDestination;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Shows Webmasters a "Backups failing" task when a scheduled backup destination is unhealthy.
 *
 * A scheduled destination counts when its last failure is newer than its last success, or when it has had no success in
 * the last 26 hours (a daily schedule plus two hours of grace). A destination created less than 26 hours ago with no
 * failure yet is not counted, so a brand-new schedule does not raise a false alarm before its first run is due.
 * Unscheduled and disabled destinations are never counted.
 */
final class BackupAttentionProvider
{
    public const KEY = 'core_backup_problem';

    private const STALE_HOURS = 26;

    /**
     * Register the provider with the shared attention registry.
     */
    public static function register(AttentionRegistry $registry): void
    {
        $registry->register(
            self::KEY,
            static fn (Authenticatable $user): bool => $user instanceof User && $user->isWebmaster(),
            static fn (Authenticatable $user): int => self::count(),
            static fn (Authenticatable $user): array => self::items(),
            ['route' => 'setting-backups', 'tone' => 'danger']
        );
    }

    /**
     * Number of unhealthy scheduled destinations.
     */
    public static function count(): int
    {
        return self::unhealthy()->count();
    }

    /**
     * The unhealthy destinations as task rows: name, driver and the last failure (or last success when it only went stale).
     * No credentials, config or error details are exposed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function items(): array
    {
        return self::unhealthy()
            ->take(10)
            ->map(static function (BackupDestination $d): array {
                $failing = $d->last_failure_at !== null && ($d->last_success_at === null || $d->last_failure_at->gt($d->last_success_at));
                $at = $failing ? $d->last_failure_at : ($d->last_success_at ?? $d->created_at);

                return [
                    'title' => (string) $d->name,
                    'subtitle' => (string) $d->driver,
                    'route' => 'setting-backups',
                    'params' => [],
                    'at' => $at?->toIso8601String(),
                    'at_label' => 'text.my_tasks.at.' . ($failing ? 'failed' : ($d->last_success_at !== null ? 'last_success' : 'created')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Scheduled, enabled destinations that are failing or stale.
     *
     * @return \Illuminate\Support\Collection<int, BackupDestination>
     */
    private static function unhealthy(): \Illuminate\Support\Collection
    {
        $cutoff = now()->subHours(self::STALE_HOURS);

        return BackupDestination::query()
            ->where('enabled', true)
            ->whereNotNull('schedule')
            ->where('schedule', '!=', '')
            ->orderBy('id')
            ->get()
            ->filter(static function (BackupDestination $d) use ($cutoff): bool {
                if ($d->last_failure_at !== null && ($d->last_success_at === null || $d->last_failure_at->gt($d->last_success_at))) {
                    return true;
                }
                if ($d->last_success_at === null) {
                    return $d->created_at !== null && $d->created_at->lt($cutoff);
                }

                return $d->last_success_at->lt($cutoff);
            })
            ->values();
    }
}
