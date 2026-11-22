<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\Backup\BackupCopy;
use Cms\Core\Models\Backup\BackupDestination;
use Cms\Core\Services\Backup\BackupDispatcher;
use Cms\Core\Services\Backup\OAuth\OAuthConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Queues one scheduled backup for every destination whose `daily@HH:MM` schedule (Asia/Ho_Chi_Minh) is due and that
 * has not received a scheduled backup today. Meant to run every 15 minutes; one dump serves all due destinations.
 */
class BackupScheduledCommand extends Command
{
    private const TIMEZONE = 'Asia/Ho_Chi_Minh';

    protected $signature = 'cms:backup-scheduled';

    protected $description = 'Queue one scheduled backup for destinations whose daily schedule is due.';

    /**
     * Find due destinations and dispatch a single backup for them; refusals are logged once per day and never fail.
     */
    public function handle(BackupDispatcher $dispatcher): int
    {
        $now = now(self::TIMEZONE);
        $date = $now->format('Y-m-d');
        $dbTimezone = (string) config('app.timezone');
        $dayStart = $now->copy()->startOfDay()->setTimezone($dbTimezone);
        $dayEnd = $now->copy()->endOfDay()->setTimezone($dbTimezone);

        $ids = [];
        $destinations = BackupDestination::query()->where('enabled', true)->whereNotNull('schedule')->orderBy('id')->get();
        foreach ($destinations as $destination) {
            if (! $this->isDue($destination, $now)) {
                continue;
            }
            if ($destination->last_error_code === OAuthConnector::RECONNECT) {
                continue;
            }
            if (Cache::has($this->markerKey($date, (int) $destination->id))) {
                continue;
            }
            $hasCopyToday = BackupCopy::query()
                ->where('destination_id', $destination->id)
                ->where('kind', BackupCopy::KIND_BACKUP)
                ->whereHas('backup', fn ($q) => $q->where('reason', Backup::REASON_SCHEDULED)->whereBetween('created_at', [$dayStart, $dayEnd]))
                ->exists();
            if ($hasCopyToday) {
                continue;
            }
            $ids[] = (int) $destination->id;
        }

        if ($ids === []) {
            $this->line('No scheduled backup due.');

            return self::SUCCESS;
        }

        sort($ids);
        try {
            $dispatcher->dispatch(Backup::REASON_SCHEDULED, null, $ids);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if (Cache::add('core.backup.scheduled.refused:' . $date, true, $now->copy()->endOfDay()->addHour())) {
                Log::warning('Scheduled backup refused.', ['code' => $code]);
            }
            $this->line($code);

            return self::SUCCESS;
        }

        $expires = $now->copy()->endOfDay()->addHour();
        foreach ($ids as $id) {
            Cache::put($this->markerKey($date, $id), true, $expires);
        }
        $this->line('Scheduled backup queued for ' . count($ids) . ' destination(s).');

        return self::SUCCESS;
    }

    /**
     * Whether the destination's `daily@HH:MM` time has been reached today (Asia/Ho_Chi_Minh); invalid schedules never are.
     */
    private function isDue(BackupDestination $destination, \Illuminate\Support\Carbon $now): bool
    {
        if (preg_match('/^daily@([01]\d|2[0-3]):([0-5]\d)$/', (string) $destination->schedule, $m) !== 1) {
            return false;
        }

        return $now->greaterThanOrEqualTo($now->copy()->setTime((int) $m[1], (int) $m[2], 0));
    }

    /**
     * Cache key marking that a scheduled run was dispatched for a destination on a VN date.
     */
    private function markerKey(string $date, int $destinationId): string
    {
        return "core.backup.scheduled.dispatched:{$date}:{$destinationId}";
    }
}
