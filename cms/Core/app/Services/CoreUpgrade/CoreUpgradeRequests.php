<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\CoreUpgrade;

use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The admin side of the upgrade runner: a core upgrade or restore asked from the admin is a queued background task
 * plus a request file that the runner picks up. This class creates those requests, finds
 * the open one and closes tasks the runner abandoned.
 */
final class CoreUpgradeRequests
{
    public const TASK_TYPE = 'core.upgrade';

    /** A request the runner has not claimed after this long times out. */
    public const REQUEST_TIMEOUT_SECONDS = 900;

    /** Key in the task's `result` that holds the uuid of the safety backup the web side took for the request. */
    public const PREPARED_BACKUP_KEY = 'prepared_backup_uuid';

    /** A running task without progress for this long, while no runner holds the lock, is dead. */
    public const RUNNER_QUIET_SECONDS = 120;

    /** Prefix of the admin translation keys a failed task stores as its message (the global task bar translates it). */
    public const RESULT_KEY_PREFIX = 'text.modules.core_update.result.';

    /** Codes with their own result key; every other code uses `failed` or `restore_failed`. */
    private const OWN_RESULT_KEYS = ['CORE_RUNNER_TIMEOUT', 'CORE_RUNNER_DIED'];

    /** Message of a task refused because modules updated on their own would not accept the new Core ({modules}). */
    public const NEEDS_MODULE_UPDATE_KEY = 'text.modules.core_update.needs_module_update';

    private readonly string $base;

    public function __construct(private readonly BackgroundTaskRecorder $recorder, ?string $base = null)
    {
        $this->base = rtrim($base ?? base_path(), '/\\');
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * Whether a module install, upgrade or uninstall is unfinished (queued included): a core upgrade waits for it.
     * Installs wait only for a core upgrade at work ({@see self::upgradeAtWork()}). False when the table is missing.
     */
    public static function moduleInstallRunning(): bool
    {
        try {
            return ModuleInstall::query()->unfinished()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether a core upgrade or restore is at work right now: the runner holds its lock, or a `core.upgrade` task is
     * `running` (after closing tasks the runner abandoned). A queued request does not count; module installs use
     * this, and the runner waits for them instead.
     */
    public function upgradeAtWork(): bool
    {
        if (CoreRunnerTick::lockHeld($this->base)) {
            return true;
        }
        $this->closeAbandoned();

        return BackgroundTask::query()
            ->where('type', self::TASK_TYPE)
            ->where('status', BackgroundTask::STATUS_RUNNING)
            ->exists();
    }

    /**
     * The translation key a failed core upgrade or restore task stores as its message; the code goes into
     * `message_params.code`. Raw error text never reaches the task (it may hold paths or SQL), only the log.
     */
    public static function failureMessage(string $code, bool $restore = false): string
    {
        if ($code === 'CORE_MODULE_NEEDS_UPDATE') {
            return self::NEEDS_MODULE_UPDATE_KEY;
        }
        if (in_array($code, self::OWN_RESULT_KEYS, true)) {
            return self::RESULT_KEY_PREFIX.$code;
        }

        return self::RESULT_KEY_PREFIX.($restore || $code === 'CORE_RESTORE_FAILED' ? 'restore_failed' : 'failed');
    }

    /**
     * Fail open tasks the runner will not finish: a request nobody claimed within 15 minutes (its request file is
     * removed), and a running task that has been quiet for 2 minutes while the runner lock is free.
     */
    public function closeAbandoned(): void
    {
        $now = now();
        $lockHeld = null;

        $tasks = BackgroundTask::query()
            ->where('type', self::TASK_TYPE)
            ->whereIn('status', [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING])
            ->get();

        foreach ($tasks as $task) {
            if ($task->status === BackgroundTask::STATUS_QUEUED) {
                if ($task->created_at !== null && $task->created_at->lt($now->copy()->subSeconds(self::REQUEST_TIMEOUT_SECONDS))) {
                    // Conditional on `queued`: a task the runner claimed after the read above is never overwritten.
                    $closed = BackgroundTask::query()->whereKey($task->id)
                        ->where('status', BackgroundTask::STATUS_QUEUED)
                        ->update(['status' => BackgroundTask::STATUS_FAILED, 'finished_at' => $now]);
                    if ($closed !== 1) {
                        continue;
                    }
                    $this->recorder->fail($task, self::failureMessage('CORE_RUNNER_TIMEOUT'), ['code' => 'CORE_RUNNER_TIMEOUT']);
                    (new CoreRunnerTick($this->base))->removeRequest($task->uuid);
                }

                continue;
            }

            if ($task->updated_at === null || ! $task->updated_at->lt($now->copy()->subSeconds(self::RUNNER_QUIET_SECONDS))) {
                continue;
            }
            $lockHeld ??= CoreRunnerTick::lockHeld($this->base);
            if (! $lockHeld) {
                // Conditional on the values read above: a task the runner finished or moved on meanwhile is never overwritten.
                $closed = BackgroundTask::query()->whereKey($task->id)
                    ->where('status', BackgroundTask::STATUS_RUNNING)
                    ->where('updated_at', $task->updated_at)
                    ->update(['status' => BackgroundTask::STATUS_FAILED, 'finished_at' => $now]);
                if ($closed !== 1) {
                    continue;
                }
                $this->recorder->fail($task, self::failureMessage('CORE_RUNNER_DIED'), ['code' => 'CORE_RUNNER_DIED']);
                // A claimed request is normally gone already; never leave one behind for a dead task.
                (new CoreRunnerTick($this->base))->removeRequest($task->uuid);
            }
        }
    }

    /**
     * The newest queued or running core upgrade task. Call closeAbandoned() first.
     */
    public function running(): ?BackgroundTask
    {
        return BackgroundTask::query()
            ->where('type', self::TASK_TYPE)
            ->whereIn('status', [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Queue an `upgrade` (to `$version`) or `restore` request for the runner.
     *
     * @throws \InvalidArgumentException When the request would be invalid; the task is then closed as failed.
     */
    public function create(string $action, ?string $version, int $userId): BackgroundTask
    {
        $task = $this->recorder->start(self::TASK_TYPE, 'Core', $userId);
        try {
            CoreRunnerTick::writeRequest($this->base, $action, $task->uuid, $version, PHP_VERSION, now()->getTimestamp());
        } catch (Throwable $e) {
            $this->recorder->fail($task, self::failureMessage('CORE_UPGRADE_FAILED', $action === 'restore'), ['code' => 'CORE_UPGRADE_FAILED']);

            throw $e;
        }

        return $task;
    }

    /**
     * Take the safety backup of the waiting upgrade request as the web user (`cms:core-upgrade-backup`, started by
     * the scheduler): the web user owns storage/backups, so the backup works even when the runner
     * cannot write there. The backup uuid goes into the task's `result` while the task stays `queued`, so the runner
     * claims it as before and reuses the backup ({@see CoreUpgrader::usePreparedBackup()}). A failed backup fails the
     * task and removes the request, so nothing is upgraded without a backup.
     *
     * @return string `idle` (no upgrade waiting, or its backup is ready), `busy` (another backup run), `prepared`, `failed`.
     */
    public function prepareBackup(BackupGate $gate): string
    {
        $tick = new CoreRunnerTick($this->base);
        $request = $tick->readRequest();
        if ($request === null || $request['action'] !== 'upgrade') {
            return 'idle';
        }
        $lock = Cache::lock('core-upgrade-prepare-backup', 3600);
        if (! $lock->get()) {
            return 'busy';
        }
        try {
            $task = BackgroundTask::query()
                ->where('uuid', $request['task_uuid'])
                ->where('type', self::TASK_TYPE)
                ->where('status', BackgroundTask::STATUS_QUEUED)
                ->first();
            if ($task === null || is_string($task->result[self::PREPARED_BACKUP_KEY] ?? null)) {
                return 'idle';
            }
            try {
                $backup = $gate->ensure(Backup::REASON_PRE_CORE_UPGRADE, $task->started_by);
            } catch (BackupRequiredException $e) {
                logger()->warning('Core upgrade backup failed.', ['code' => 'CORE_BACKUP_FAILED', 'backup_code' => $e->getMessage(), 'task_uuid' => $task->uuid]);
                $this->recorder->fail($task, self::failureMessage('CORE_BACKUP_FAILED'), ['code' => 'CORE_BACKUP_FAILED']);
                $tick->removeRequest($task->uuid);

                return 'failed';
            }
            // Only while the task is still waiting: a runner that already claimed it took its own backup.
            BackgroundTask::query()->whereKey($task->getKey())->where('status', BackgroundTask::STATUS_QUEUED)
                ->update(['result' => json_encode(array_merge((array) $task->result, [self::PREPARED_BACKUP_KEY => (string) $backup->uuid]))]);

            return 'prepared';
        } finally {
            $lock->release();
        }
    }

    /**
     * Who should run the runner, the cron line to add for that user, the site's base folder
     * (for the AI agent prompt on the admin card), and whether the code belongs to root (the runner never runs as
     * root, so one-click updates are not possible then).
     *
     * @return array{user: string, line: string, base: string, root_owner: bool}
     */
    public function runnerSetup(): array
    {
        // The base folder's owner: the same owner CoreRunnerTick::codeDirShared() checks, so the messages agree.
        $owner = @fileowner($this->base);

        return self::setupFor($this->base, $owner === false ? null : $owner);
    }

    /**
     * {@see self::runnerSetup()} for a given owner uid (null when unknown).
     *
     * @return array{user: string, line: string, base: string, root_owner: bool}
     */
    public static function setupFor(string $base, ?int $ownerUid): array
    {
        $base = rtrim($base, '/\\');
        $user = '';
        if ($ownerUid !== null && function_exists('posix_getpwuid')) {
            $user = (string) (posix_getpwuid($ownerUid)['name'] ?? '');
        }

        return [
            'user' => $user !== '' ? $user : ($ownerUid === 0 ? 'root' : get_current_user()),
            'line' => self::runnerLine($base),
            'base' => $base,
            'root_owner' => $ownerUid === 0,
        ];
    }

    /**
     * The crontab line of the upgrade runner, with the base folder shell-quoted.
     */
    public static function runnerLine(string $base): string
    {
        return '* * * * * cd '.escapeshellarg(rtrim($base, '/\\')).' && php ispa upgrade --run-requested >/dev/null 2>&1';
    }
}
