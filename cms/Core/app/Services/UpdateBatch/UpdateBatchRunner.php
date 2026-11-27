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
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\InstallRecorder;
use Cms\Core\Services\Modules\Install\InstallRunDispatcher;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Widget\Hub\WidgetBundleUntrusted;
use Cms\Core\Services\Widget\Hub\WidgetHubException;
use Cms\Core\Services\Widget\Hub\WidgetHubInstaller;
use Cms\Core\Services\Widget\Hub\WidgetHubUnreachable;
use Cms\Core\Services\Workflow\Hub\WorkflowHubException;
use Cms\Core\Services\Workflow\Hub\WorkflowHubInstaller;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Moves the open upgrade-all run on (spec 2026-11-26, decisions 1 and 4).
 *
 * One call: close the running item when its work ended, then start the next. A module item gets its own preflight now
 * (requirements are read from the site as it is after the earlier items) and becomes an ordinary install row for the
 * worker or the scheduled runner; the call then returns and the next call picks the run up when that row is finished.
 * Widget and workflow items run here, one after another. An item the preflight or the hub refuses is skipped; a
 * failure that would repeat for every next item ({@see self::STOP_CODES}) ends the run. Calls are serialised by a
 * cache lock; a call that cannot get it returns at once.
 */
final class UpdateBatchRunner
{
    /** Cache lock that serialises advances. */
    public const LOCK_NAME = 'core:update-batch:advance';

    /** Lifetime of the advance lock in seconds; one call stops starting items after {@see self::ITEM_BUDGET_SECONDS}. */
    public const LOCK_SECONDS = 600;

    /** Seconds one call may spend on widget and workflow items before it leaves the rest to the next call. */
    public const ITEM_BUDGET_SECONDS = 240;

    /** Failures that would repeat for every next item: the run stops and the rest is not started. */
    public const STOP_CODES = [
        InstallRunDispatcher::ERROR_NOT_PICKED_UP,
        'MODULE_INSTALL_JOB_FAILED',
        BackupRequiredException::CODE,
        ModuleInstaller::ERROR_HUB,
        'MODULE_CATALOG_UNAVAILABLE',
        InstallPreflight::INSTALL_DISABLED,
        InstallPreflight::FORBIDDEN_BY_SERVER,
        InstallPreflight::FORBIDDEN_BY_HUB,
        InstallPreflight::NO_RUNNER,
        InstallPreflight::NO_RUNTIME_FOLDER,
        InstallPreflight::NO_TRUSTED_KEY,
        InstallPreflight::QUEUE_SYNC,
        InstallPreflight::QUEUE_TIMEOUT,
        WidgetHubUnreachable::ERROR_CODE,
        WidgetBundleUntrusted::ERROR_CODE,
        WorkflowHubException::UNREACHABLE,
        WorkflowHubException::UNTRUSTED,
    ];

    /** The person who started the run no longer exists, so nothing can be recorded in their name. */
    public const ERROR_STARTER_MISSING = 'UPDATE_BATCH_STARTER_MISSING';

    /** Code of an install row "Dừng" closed before any runner picked it up (nothing was changed). */
    public const ERROR_STOPPED = 'UPDATE_BATCH_STOPPED';

    public function __construct(
        private readonly UpdateBatches $batches,
        private readonly InstallPreflight $preflight,
        private readonly InstallRecorder $installs,
        private readonly InstallRunDispatcher $dispatcher,
        private readonly BackgroundTaskRecorder $tasks,
        private readonly WidgetHubInstaller $widgets,
        private readonly WorkflowHubInstaller $workflows,
    ) {
    }

    /**
     * Move the open run on.
     *
     * Returns true when the run is still open and this call stopped with work left that nothing else will start: the
     * lock was busy, the item budget ran out, or widget/workflow items were left for another process. False when the
     * run ended, or it waits for an install row (whose job end moves it on).
     *
     * @param bool $itemsAllowed false leaves widget and workflow items to a later call in a process of its own
     */
    public function advance(bool $itemsAllowed = true): bool
    {
        $lock = Cache::lock(self::LOCK_NAME, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $this->batches->open() !== null;
        }
        try {
            return $this->step($itemsAllowed, microtime(true));
        } finally {
            $lock->release();
        }
    }

    /**
     * End the run: pending items become `not_started`, the stop reason is stored, the task succeeds (ran to the end or
     * stopped by its owner) or fails (any other reason), and `update.batch_finished` is audited.
     */
    public function finish(BackgroundTask $batch, ?string $stoppedReason): void
    {
        $batch = $this->batches->change($batch, static function (array $result) use ($stoppedReason): array {
            $result['items'] = array_map(static fn (array $item): array => $item['state'] === 'pending' ? ['state' => 'not_started'] + $item : $item, $result['items']);
            $result['stopped_reason'] = $stoppedReason;

            return $result;
        });

        if ($stoppedReason === null || $stoppedReason === 'owner') {
            $this->tasks->succeed($batch, (array) $batch->result);
        } else {
            $this->tasks->fail($batch, 'The upgrade-all run stopped: ' . $stoppedReason . '.', ['code' => $stoppedReason]);
        }

        $counts = $this->batches->presented($batch->refresh())['counts'];
        // The starter may have been deleted (UPDATE_BATCH_STARTER_MISSING): the audit row then names nobody.
        $starter = User::query()->whereKey((int) $batch->started_by)->exists() ? (int) $batch->started_by : null;
        event(new AuditLogEvent('update.batch_finished', [
            'batch' => $batch->uuid,
            'counts' => $counts,
            'stopped_reason' => $stoppedReason,
        ], BackgroundTask::class, (int) $batch->id, null, null, $starter));
    }

    /**
     * @return bool see {@see self::advance()}
     */
    private function step(bool $itemsAllowed, float $since): bool
    {
        $batch = $this->batches->open();
        if ($batch === null) {
            return false;
        }
        if ($batch->status === BackgroundTask::STATUS_QUEUED) {
            $this->tasks->running($batch);
        }

        while (true) {
            $batch = $batch->fresh();
            $result = (array) $batch->result;
            $items = (array) $result['items'];

            $current = $this->firstIndex($items, 'running');
            if ($current !== null) {
                if ($items[$current]['kind'] !== 'module') {
                    // A widget or workflow item left running by a process that died.
                    $this->setItem($batch, $current, ['state' => 'failed', 'reason_code' => 'UPDATE_BATCH_ITEM_FAILED']);

                    continue;
                }
                $row = ModuleInstall::query()->where('uuid', (string) $items[$current]['install_uuid'])->first();
                if ($row !== null && $row->isUnfinished()) {
                    // "Dừng" closes an install nobody picked up yet (no runner, or not yet); one at work finishes.
                    if (($result['stop_requested'] ?? false) === true && $this->cancelUnclaimed($row)) {
                        $this->setItem($batch, $current, ['state' => 'not_started', 'reason_code' => self::ERROR_STOPPED]);

                        continue;
                    }

                    return false;
                }
                $succeeded = $row?->status === ModuleInstall::STATUS_SUCCEEDED;
                $code = $succeeded ? null : (string) ($row?->error_code ?: 'MODULE_INSTALL_JOB_FAILED');
                $batch = $this->setItem($batch, $current, ['state' => $succeeded ? 'done' : 'failed', 'reason_code' => $code]);
                if ($code !== null && in_array($code, self::STOP_CODES, true)) {
                    $this->finish($batch, $code);

                    return false;
                }

                continue;
            }

            if (($result['stop_requested'] ?? false) === true) {
                $this->finish($batch, 'owner');

                return false;
            }
            $next = $this->firstIndex($items, 'pending');
            if ($next === null) {
                $this->finish($batch, null);

                return false;
            }

            if ($items[$next]['kind'] === 'module') {
                if (! $this->startModule($batch, $next, $items[$next], (bool) ($result['backup'] ?? true))) {
                    return false;
                }

                continue;
            }
            if (! $itemsAllowed || microtime(true) - $since > self::ITEM_BUDGET_SECONDS) {
                return true;
            }
            if (! $this->runItem($batch, $next, $items[$next])) {
                return false;
            }
        }
    }

    /**
     * Close an install row no runner has picked up, with the same conditional claim the runners use, so a row a runner
     * claims meanwhile is left alone. Its staging folder is removed; nothing on the site changed.
     */
    private function cancelUnclaimed(ModuleInstall $row): bool
    {
        $claimed = ModuleInstall::query()
            ->whereKey($row->getKey())
            ->where('status', ModuleInstall::STATUS_QUEUED)
            ->whereNull('started_at')
            ->update(['started_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }
        $installer = app(ModuleInstaller::class);
        $row->refresh();
        $installer->markFailed($row, self::ERROR_STOPPED);
        $installer->cleanStaging($row);

        return true;
    }

    /**
     * Preflight a module item now and record its install row, or skip it (or end the run) on a blocking code.
     *
     * @param array<string, mixed> $item
     *
     * @return bool true when the run should look at the next item now (the item was skipped)
     */
    private function startModule(BackgroundTask $batch, int $index, array $item, bool $backup): bool
    {
        if ($this->dispatcher->hasUnfinished()) {
            return false;
        }
        try {
            $check = $this->preflight->check((string) $item['key'], (string) $item['to']);
        } catch (PlatformHubException) {
            $this->finish($batch, 'MODULE_CATALOG_UNAVAILABLE');

            return false;
        }
        $blocking = $check->blockingCodes();
        if ($blocking !== []) {
            $code = (string) $blocking[0];
            if (in_array($code, self::STOP_CODES, true)) {
                $this->finish($batch, $code);

                return false;
            }
            $this->setItem($batch, $index, ['state' => 'skipped', 'reason_code' => $code]);

            return true;
        }
        // The single flow asks for a typed confirmation here; a run never gives it (spec decision 2).
        if (in_array(InstallPreflight::WARN_IRREVERSIBLE, $check->warningCodes(), true)) {
            $this->setItem($batch, $index, ['state' => 'skipped', 'reason_code' => 'IRREVERSIBLE']);

            return true;
        }

        $starter = User::query()->find((int) $batch->started_by);
        if (! $starter instanceof User) {
            $this->finish($batch, self::ERROR_STARTER_MISSING);

            return false;
        }
        $row = $this->installs->record($starter, (string) $item['key'], (string) $item['to'], ModuleInstall::SOURCE_HUB, $check, false, null, $backup);
        $batch = $this->setItem($batch, $index, ['state' => 'running', 'install_uuid' => (string) $row->uuid]);
        $this->progress($batch, $index);
        $this->dispatcher->dispatch($row);

        return false;
    }

    /**
     * Run one widget or workflow item as the person who started the run: `done` with the new version, `skipped` with
     * the refusal code, `failed` on an unexpected error; a hub failure in {@see self::STOP_CODES} ends the run.
     *
     * @param array<string, mixed> $item
     *
     * @return bool true when the run should look at the next item now
     */
    private function runItem(BackgroundTask $batch, int $index, array $item): bool
    {
        $starter = User::query()->find((int) $batch->started_by);
        if (! $starter instanceof User) {
            $this->finish($batch, self::ERROR_STARTER_MISSING);

            return false;
        }
        $batch = $this->setItem($batch, $index, ['state' => 'running']);
        $this->progress($batch, $index);

        try {
            if ($item['kind'] === 'widget') {
                $widget = $this->widgets->update((int) $item['widget_id'], (int) $starter->id);
                $fields = ['state' => 'done', 'to' => UpdateBatchPlanner::widgetVersion((int) $widget->version)];
            } else {
                $updated = $this->workflows->updateInPlaceOnly((string) $item['key'], $starter);
                $fields = ['state' => 'done', 'to' => $updated['version_label'], 'project_id' => $updated['project_id'], 'setup_url' => $updated['setup_url']];
            }
        } catch (WidgetHubException|WorkflowHubException $e) {
            $fields = ['state' => 'skipped', 'reason_code' => $e->errorCode];
        } catch (WidgetBundleUntrusted) {
            $fields = ['state' => 'skipped', 'reason_code' => WidgetBundleUntrusted::ERROR_CODE];
        } catch (Throwable $e) {
            report($e);
            $fields = ['state' => 'failed', 'reason_code' => 'UPDATE_BATCH_ITEM_FAILED'];
        }

        $stop = $fields['state'] === 'skipped' && in_array($fields['reason_code'], self::STOP_CODES, true);
        $batch = $this->setItem($batch, $index, $stop ? ['state' => 'failed'] + $fields : $fields);
        if ($stop) {
            $this->finish($batch, (string) $fields['reason_code']);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function setItem(BackgroundTask $batch, int $index, array $fields): BackgroundTask
    {
        return $this->batches->change($batch, static function (array $result) use ($index, $fields): array {
            $result['items'][$index] = $fields + $result['items'][$index];

            return $result;
        });
    }

    /**
     * Task bar progress: items no longer pending or running, out of all items.
     */
    private function progress(BackgroundTask $batch, int $index): void
    {
        $items = (array) $batch->result['items'];
        $done = count(array_filter($items, static fn (array $item): bool => ! in_array($item['state'], ['pending', 'running'], true)));
        $this->tasks->progress($batch, (int) floor($done * 100 / max(1, count($items))),
            'Upgrading ' . ($index + 1) . ' of ' . count($items) . ': ' . $items[$index]['label'] . '.');
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function firstIndex(array $items, string $state): ?int
    {
        foreach ($items as $index => $item) {
            if ($item['state'] === $state) {
                return (int) $index;
            }
        }

        return null;
    }
}
