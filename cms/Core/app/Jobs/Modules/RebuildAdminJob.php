<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Jobs\Modules;

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Modules\Install\AdminRebuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Rebuilds the admin for the Webmaster retry endpoint, recording progress on a `core.admin_build` background task.
 */
final class RebuildAdminJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param string $taskUuid Background task to update.
     */
    public function __construct(public readonly string $taskUuid)
    {
        $this->timeout = (int) config('core.module_packages.job_timeout', 2700);
        $this->onQueue((string) config('core.module_packages.queue', 'default'));
    }

    /**
     * Run the build and close the task; the result never carries an absolute path.
     */
    public function handle(AdminRebuilder $rebuilder, BackgroundTaskRecorder $recorder): void
    {
        $task = BackgroundTask::query()->where('uuid', $this->taskUuid)->first();
        if ($task === null) {
            return;
        }

        $recorder->running($task);
        $result = $rebuilder->rebuild();

        if ($result['ok']) {
            $recorder->succeed($task, ['output' => basename((string) $result['outputDir'])]);

            return;
        }

        $recorder->fail($task, (string) $result['errorCode'] . ': ' . $result['message']);
    }

    /**
     * After a worker kill or timeout: close the task if it is still open.
     */
    public function failed(Throwable $e): void
    {
        $task = BackgroundTask::query()->where('uuid', $this->taskUuid)->first();
        if ($task !== null && in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            app(BackgroundTaskRecorder::class)->fail($task, AdminRebuilder::ERROR_FAILED . ': the admin rebuild job stopped.');
        }
    }
}
