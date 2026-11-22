<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Jobs\Backup;

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Runs one backup for a background task created by {@see \Cms\Core\Services\Backup\BackupDispatcher}. The worker timeout is
 * `core.backup.job_timeout`; the dispatcher refuses queues whose `retry_after` is not above it, so a run is never handed
 * to a second worker (`tries = 1`).
 */
final class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /**
     * @param string $taskUuid Background task row to update.
     * @param string $reason Backup reason.
     * @param int|null $userId Starting user id.
     * @param array<int, int> $destinationIds Destination ids (empty = default set).
     */
    public function __construct(
        public readonly string $taskUuid,
        public readonly string $reason,
        public readonly ?int $userId = null,
        public readonly array $destinationIds = [],
    ) {
        $this->timeout = (int) config('core.backup.job_timeout');
    }

    /**
     * Run the backup; the runner records progress and the final state on the task.
     */
    public function handle(BackupRunner $runner, BackgroundTaskRecorder $recorder): void
    {
        $task = BackgroundTask::query()->where('uuid', $this->taskUuid)->first();
        if ($task === null) {
            return;
        }

        $task = $recorder->running($task);
        try {
            $runner->run($this->reason, $this->userId, $this->destinationIds, $task);
        } catch (Throwable $e) {
            $code = preg_match('/^(BACKUP_[A-Z0-9_]+)/', $e->getMessage(), $m) === 1 ? $m[1] : 'BACKUP_UNEXPECTED';
            $recorder->fail($task, 'text.backup.task.failed', ['code' => $code]);
        }
    }

    /**
     * After a worker kill or timeout: close the task and any backup row still marked running for it.
     */
    public function failed(Throwable $e): void
    {
        $task = BackgroundTask::query()->where('uuid', $this->taskUuid)->first();
        if ($task !== null && in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            (new BackgroundTaskRecorder())->fail($task, 'text.backup.task.failed', ['code' => 'BACKUP_JOB_FAILED']);
        }
        Backup::query()->where('background_task_uuid', $this->taskUuid)->where('status', Backup::STATUS_RUNNING)
            ->update(['status' => Backup::STATUS_FAILED, 'error_code' => 'BACKUP_JOB_FAILED', 'finished_at' => now()]);
    }
}
