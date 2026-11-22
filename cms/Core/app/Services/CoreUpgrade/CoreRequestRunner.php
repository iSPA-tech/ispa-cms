<?php

declare(strict_types=1);

namespace Cms\Core\Services\CoreUpgrade;

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Throwable;

/**
 * One minute of the upgrade runner: write the
 * heartbeat, then pick up the core upgrade or restore the admin asked for (spec 4.3). The task is claimed atomically
 * from `queued`, so a request is worked on at most once, and the request file is removed before the work starts, so
 * a failure is never retried on the next minute.
 */
final class CoreRequestRunner
{
    private readonly string $base;

    public function __construct(
        private readonly CoreUpgrader $upgrader,
        private readonly CoreUpgradePreflight $preflight,
        private readonly BackgroundTaskRecorder $recorder,
        ?string $base = null,
    ) {
        $this->base = rtrim($base ?? $upgrader->basePath(), '/\\');
    }

    /**
     * @return string One of `cannot_run`, `idle`, `invalid`, `busy` (another runner, or a module install, is working), `not_claimed`, `succeeded`, `failed`.
     */
    public function tick(?int $now = null): string
    {
        // Code files the engine writes are never group-writable, even when cron starts with umask 002.
        $umask = umask(0022);
        try {
            return $this->work($now);
        } finally {
            umask($umask);
        }
    }

    private function work(?int $now): string
    {
        $tick = new CoreRunnerTick($this->base);
        if (! $tick->canRun()) {
            return 'cannot_run';
        }
        $tick->writeHeartbeat($now);
        if (! $tick->hasRequestFile()) {
            return 'idle';
        }
        $request = $tick->readRequest();
        if ($request === null) {
            $tick->removeRequest();

            return 'invalid';
        }

        $lock = CoreRunnerTick::acquireLock($this->base);
        if ($lock === null) {
            return 'busy';
        }

        $task = null;
        $files = [];
        try {
            // A module install, upgrade or uninstall changes code too: wait for it and keep the request. Checked after
            // the lock is taken, so an install claimed from now on sees the lock and waits for this run instead.
            if (CoreUpgradeRequests::moduleInstallRunning()) {
                return 'busy';
            }
            $claimed = BackgroundTask::query()
                ->where('uuid', $request['task_uuid'])
                ->where('type', CoreUpgradeRequests::TASK_TYPE)
                ->where('status', BackgroundTask::STATUS_QUEUED)
                ->update(['status' => BackgroundTask::STATUS_RUNNING, 'started_at' => now()]);
            if ($claimed !== 1) {
                $tick->removeRequest($request['task_uuid']);

                return 'not_claimed';
            }
            // Loaded before the request is removed, so a failure there still closes the task.
            $task = BackgroundTask::query()->where('uuid', $request['task_uuid'])->firstOrFail();
            $tick->removeRequest($request['task_uuid']);

            if ($request['action'] === 'restore') {
                $this->upgrader->restore();
                $this->recorder->succeed($task, ['restored' => true]);

                return 'succeeded';
            }

            $version = $request['version']
                ?? throw new CoreUpgradeException('CORE_UPGRADE_FAILED', 'The request names no core version.');
            $result = $this->preflight->check($this->upgrader->installed(), $version, true, false, null, null, [$request['web_php']]);
            if ($result->blocking !== []) {
                $first = $result->blocking[0];
                $files = array_map('strval', array_values((array) ($first['details']['files'] ?? [])));
                throw new CoreUpgradeException((string) $first['code'], (string) $first['message']);
            }
            $this->upgrader->run($version, false, $task, $task->started_by, [$request['web_php']]);

            return 'succeeded';
        } catch (Throwable $e) {
            // Cron discards the output and the task keeps only a translation key, so the details go to the log.
            if ($e instanceof CoreUpgradeException) {
                $code = $e->errorCode;
                logger()->warning('Core upgrade request failed.', ['code' => $code, 'message' => $e->getMessage(), 'task_uuid' => $request['task_uuid']]);
            } else {
                report($e);
                $code = 'CORE_UPGRADE_FAILED';
            }
            if ($task !== null) {
                $this->failOpen($task, $code, $files, $request['action'] === 'restore');
            }

            return 'failed';
        } finally {
            CoreRunnerTick::releaseLock($lock);
        }
    }

    /**
     * Fail the task unless the engine has already closed it. The message is a translation key
     * ({@see CoreUpgradeRequests::failureMessage()}); the code and the files are its params.
     *
     * @param list<string> $files Files named by a blocking preflight problem (e.g. modified core files).
     */
    private function failOpen(BackgroundTask $task, string $code, array $files, bool $restore): void
    {
        try {
            $task->refresh();
            if (in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
                $params = ['code' => $code];
                if ($files !== []) {
                    // Message params hold scalars only, so the list is stored as one line.
                    $params['files'] = implode(', ', array_slice($files, 0, 20));
                }
                $this->recorder->fail($task, CoreUpgradeRequests::failureMessage($code, $restore), $params);
            }
        } catch (Throwable) {
            // The admin closes a quiet task as CORE_RUNNER_DIED once the lock is free.
        }
    }
}
