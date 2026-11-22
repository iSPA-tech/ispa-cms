<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Backup;

use Cms\Core\Jobs\Backup\RunBackupJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use RuntimeException;

/**
 * Queues a backup as a recorded background task, after checking that the queue can run it safely.
 */
class BackupDispatcher
{
    /**
     * @param BackgroundTaskRecorder|null $recorder Task recorder (default recorder when null).
     */
    public function __construct(private readonly ?BackgroundTaskRecorder $recorder = null)
    {
    }

    /**
     * Create the task (type `core.backup`, module `Core`) and dispatch the job on `core.backup.queue`.
     *
     * @param string $reason One of the Backup::REASON_* values.
     * @param int|null $userId Starting user id.
     * @param array<int, int> $destinationIds Destination ids; empty = the runner's default set.
     *
     * @throws RuntimeException BACKUP_QUEUE_SYNC or BACKUP_QUEUE_TIMEOUT_TOO_LOW
     */
    public function dispatch(string $reason, ?int $userId, array $destinationIds = []): BackgroundTask
    {
        $connection = (string) config('queue.default');
        if ($connection === 'sync') {
            throw new RuntimeException('BACKUP_QUEUE_SYNC');
        }

        $retryAfter = config("queue.connections.{$connection}.retry_after");
        if ($retryAfter !== null && (int) $retryAfter <= (int) config('core.backup.job_timeout')) {
            throw new RuntimeException('BACKUP_QUEUE_TIMEOUT_TOO_LOW');
        }

        $task = ($this->recorder ?? new BackgroundTaskRecorder())->start('core.backup', 'Core', $userId);
        RunBackupJob::dispatch((string) $task->uuid, $reason, $userId, $destinationIds)
            ->onQueue((string) config('core.backup.queue', 'default'));

        return $task;
    }
}
