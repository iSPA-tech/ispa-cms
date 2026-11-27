<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\UpdateBatch;

use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Modules\Install\InstallRunDispatcher;
use Cms\Core\Services\Scheduler\SchedulerHeartbeat;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade-all runs (spec 2026-11-26), stored as background tasks of type {@see self::TASK_TYPE}: the task row gives the
 * task bar its progress, and `result` holds the confirmed items and their states (plan "Batch result contract").
 * At most one run is open (queued or running) at a time; while it is, {@see self::refusal()} answers every other start.
 */
final class UpdateBatches
{
    public const TASK_TYPE = 'core.update_batch';

    public const ERROR_RUNNING = 'UPDATE_BATCH_RUNNING';

    public function __construct(private readonly BackgroundTaskRecorder $recorder)
    {
    }

    /** The open run (queued or running), newest first. */
    public function open(): ?BackgroundTask
    {
        return BackgroundTask::query()
            ->where('type', self::TASK_TYPE)
            ->whereIn('status', [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING])
            ->orderByDesc('id')
            ->first();
    }

    /** The open run, else the newest finished one nobody hid ("Ẩn"). */
    public function latestVisible(): ?BackgroundTask
    {
        $open = $this->open();
        if ($open !== null) {
            return $open;
        }
        $latest = BackgroundTask::query()->where('type', self::TASK_TYPE)->orderByDesc('id')->first();

        return $latest !== null && ($latest->result['dismissed'] ?? false) !== true ? $latest : null;
    }

    /** A run of this site by its uuid. */
    public function find(string $uuid): ?BackgroundTask
    {
        return BackgroundTask::query()->where('type', self::TASK_TYPE)->where('uuid', $uuid)->first();
    }

    /**
     * Record a new run with the confirmed plan; every item starts `pending`.
     *
     * @param array{items: list<array<string, mixed>>, left_out: list<array<string, mixed>>} $plan
     * @param list<string>                                                                 $kinds
     */
    public function start(User $user, array $plan, array $kinds, bool $backup): BackgroundTask
    {
        $task = $this->recorder->start(self::TASK_TYPE, 'Core', (int) $user->id);
        $task->forceFill(['result' => [
            'kinds' => array_values($kinds),
            'backup' => $backup,
            'stop_requested' => false,
            'dismissed' => false,
            'stopped_reason' => null,
            'items' => array_values($plan['items']),
            'left_out' => array_values($plan['left_out']),
        ]])->save();

        event(new AuditLogEvent('update.batch_started', [
            'batch' => $task->uuid,
            'kinds' => array_values($kinds),
            'items' => array_map(static fn (array $item): string => $item['kind'] . ':' . $item['key'], $plan['items']),
            'backup' => $backup,
        ], BackgroundTask::class, (int) $task->id, null, null, (int) $user->id));

        return $task;
    }

    /**
     * Change a run's `result` under a row lock (the runner and the stop/hide buttons write the same row).
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    public function change(BackgroundTask $batch, callable $change): BackgroundTask
    {
        return DB::transaction(function () use ($batch, $change): BackgroundTask {
            /** @var BackgroundTask $locked */
            $locked = BackgroundTask::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['result' => $change((array) $locked->result)])->save();

            return $locked;
        });
    }

    /** Whether something will run the batch: a safe queue worker, or a scheduler seen in the last minutes. */
    public function runnerReady(): bool
    {
        return ! InstallRunDispatcher::schedulerRunsInstalls() || app(SchedulerHeartbeat::class)->isFresh();
    }

    /**
     * 409 answer for any other install or update start while a run is open; null when none is.
     */
    public function refusal(): mixed
    {
        if ($this->open() === null) {
            return null;
        }

        return ires()->conflict('An upgrade-all run is in progress on this site.')->errorCode(self::ERROR_RUNNING);
    }

    /**
     * The admin shape of a run: the result contract plus status, progress, times and counts. A running module item
     * also carries `step`, the status of its install row.
     *
     * @return array<string, mixed>
     */
    public function presented(BackgroundTask $batch): array
    {
        $result = (array) $batch->result;
        $items = array_map(static function (array $item): array {
            if ($item['kind'] === 'module' && $item['state'] === 'running' && is_string($item['install_uuid'] ?? null)) {
                $item['step'] = ModuleInstall::query()->where('uuid', $item['install_uuid'])->value('status');
            }

            return $item;
        }, (array) ($result['items'] ?? []));
        $count = static fn (string $state): int => count(array_filter($items, static fn (array $item): bool => $item['state'] === $state));

        return [
            'uuid' => (string) $batch->uuid,
            'status' => (string) $batch->status,
            'progress' => (int) $batch->progress,
            'started_at' => $batch->started_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
            'kinds' => (array) ($result['kinds'] ?? []),
            'backup' => (bool) ($result['backup'] ?? true),
            'stop_requested' => (bool) ($result['stop_requested'] ?? false),
            'stopped_reason' => $result['stopped_reason'] ?? null,
            'items' => $items,
            'left_out' => (array) ($result['left_out'] ?? []),
            'counts' => [
                'done' => $count('done'),
                'failed' => $count('failed'),
                'skipped' => $count('skipped'),
                'not_started' => $count('not_started'),
                'left_out' => count((array) ($result['left_out'] ?? [])),
            ],
        ];
    }
}
