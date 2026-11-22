<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Scheduler;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Proof that `schedule:run` is called on this site: a scheduled callback writes the time every minute.
 *
 * Stored as a small file under `storage/app/core/` (config `core.scheduler.heartbeat_path` overrides it), so a cache
 * clear does not make the site look as if it had no scheduler.
 */
final class SchedulerHeartbeat
{
    /** A heartbeat older than this many seconds means the scheduler is not running. */
    public const FRESH_SECONDS = 180;

    /**
     * File holding the last heartbeat (unix time).
     */
    public function path(): string
    {
        $configured = config('core.scheduler.heartbeat_path');

        return is_string($configured) && $configured !== '' ? $configured : storage_path('app/core/scheduler-heartbeat');
    }

    /**
     * Record that the scheduler ran now. Never throws: a failed write only makes the next check stricter.
     */
    public function beat(): void
    {
        $path = $this->path();
        try {
            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }
            @file_put_contents($path, (string) Carbon::now()->getTimestamp(), LOCK_EX);
        } catch (Throwable) {
            // Ignored on purpose (see above).
        }
    }

    /**
     * Time of the last heartbeat, or null when none was recorded.
     */
    public function lastSeen(): ?Carbon
    {
        $path = $this->path();
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if (! is_string($raw) || ! ctype_digit(trim($raw))) {
            return null;
        }

        return Carbon::createFromTimestamp((int) trim($raw));
    }

    /**
     * Whether the scheduler ran within the last {@see self::FRESH_SECONDS} seconds.
     */
    public function isFresh(int $seconds = self::FRESH_SECONDS): bool
    {
        $last = $this->lastSeen();

        return $last !== null && $last->getTimestamp() >= Carbon::now()->getTimestamp() - $seconds;
    }
}
