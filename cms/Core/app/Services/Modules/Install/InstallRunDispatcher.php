<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use Cms\Core\Jobs\Modules\InstallModuleJob;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Cms\Core\Services\Scheduler\SchedulerHeartbeat;
use Illuminate\Support\Carbon;

/**
 * Hands a recorded install (or uninstall) row to whatever runs it (spec 2026-11-22, decision 5).
 *
 * With a real queue the row goes to a worker as {@see InstallModuleJob}. When the default queue is `sync` the job is
 * not run inside the web request: the row stays `queued` as a pending run, and the scheduled command
 * `cms:module-install-run-pending` claims it ({@see self::claimNext()}) and runs the same job in its own process.
 *
 * A row left for the scheduler that nobody claims would block every install (only one unfinished row is allowed), so
 * {@see self::expireUnclaimed()} closes it as failed after {@see self::UNCLAIMED_EXPIRE_SECONDS}, or at once when the
 * site now has a real queue. Decision: such a row is failed, not handed to the new queue: its preflight ran under
 * the old set-up and possibly long ago, so the Webmaster starts it again and gets a fresh preflight.
 */
final class InstallRunDispatcher
{
    /** Step message of a row waiting for the scheduled runner. */
    public const STEP_WAITING_FOR_SCHEDULER = 'Waiting for the scheduled run (at most about a minute).';

    /** Step message of a row the scheduled runner has claimed. */
    public const STEP_CLAIMED = 'Picked up by the scheduled run.';

    /** A row left for the scheduler and not claimed within this many seconds is closed as failed. */
    public const UNCLAIMED_EXPIRE_SECONDS = 900;

    /** Error code of a row closed by {@see self::expireUnclaimed()}. */
    public const ERROR_NOT_PICKED_UP = 'MODULE_INSTALL_NOT_PICKED_UP';

    /** Step message of a row closed by {@see self::expireUnclaimed()}. */
    public const STEP_NOT_PICKED_UP = 'The install was not started because no scheduled run picked it up. Nothing was changed; start it again.';

    /** Extra minutes the runner's overlap lock holds beyond the job timeout. */
    public const LOCK_MARGIN_MINUTES = 15;

    /** Extra seconds a claim must be older than the overlap lock before it counts as stale. */
    public const STALE_MARGIN_SECONDS = 300;

    /**
     * @param string|null $coreBase Base folder of the core upgrade runner files (default base_path()).
     */
    public function __construct(private readonly SchedulerHeartbeat $heartbeat, private readonly ?string $coreBase = null)
    {
    }

    /**
     * Whether a core upgrade or restore is at work: the runner holds its lock, or a `core.upgrade` task is `running`.
     * A pending install is not claimed meanwhile (it stays pending for a later minute), since both replace code.
     *
     * A merely `queued` core request does not count: the runner itself waits for every unfinished install, so
     * deferring here too would make both wait on each other until both time out.
     */
    public function coreUpgradeBusy(): bool
    {
        return (new CoreUpgradeRequests(app(BackgroundTaskRecorder::class), $this->coreBase))->upgradeAtWork();
    }

    /**
     * Whether installs run through the scheduler (no queue worker: the default queue is `sync`).
     */
    public function usesScheduler(): bool
    {
        return (string) config('queue.default', 'sync') === 'sync';
    }

    /**
     * Queue the row for a worker, or leave it pending for the scheduled runner.
     *
     * A `sync` queue without a fresh scheduler heartbeat keeps the old inline run: the install preflight already
     * refuses installs there (MODULE_INSTALL_NO_RUNNER), so only an uninstall, which has no queue check, gets here,
     * and a pending row would never be picked up.
     */
    public function dispatch(ModuleInstall $install): void
    {
        if ($this->usesScheduler() && $this->heartbeat->isFresh()) {
            $install->forceFill(['step_message' => self::STEP_WAITING_FOR_SCHEDULER])->save();

            return;
        }

        InstallModuleJob::dispatch($install->uuid);
    }

    /**
     * Claim the oldest pending run, or null when there is none, another process claimed it first, or a core upgrade
     * is at work ({@see self::coreUpgradeBusy()}).
     *
     * The claim is one conditional UPDATE (`status = queued AND started_at IS NULL`), so of two concurrent claims
     * only one gets the row. The status stays `queued` so the job's own "only a queued row" guard still passes.
     */
    public function claimNext(): ?ModuleInstall
    {
        if ($this->coreUpgradeBusy()) {
            return null;
        }
        $candidate = ModuleInstall::query()
            ->where('status', ModuleInstall::STATUS_QUEUED)
            ->whereNull('started_at')
            ->whereNull('finished_at')
            ->orderBy('id')
            ->first();

        return $candidate !== null ? $this->claim($candidate) : null;
    }

    /**
     * Claim this pending row; null when another process claimed it first (or it is no longer pending).
     */
    public function claim(ModuleInstall $candidate): ?ModuleInstall
    {
        $claimed = ModuleInstall::query()
            ->whereKey($candidate->getKey())
            ->where('status', ModuleInstall::STATUS_QUEUED)
            ->whereNull('started_at')
            ->update(['started_at' => Carbon::now(), 'step_message' => self::STEP_CLAIMED, 'updated_at' => Carbon::now()]);

        return $claimed === 1 ? $candidate->fresh() : null;
    }

    /**
     * Lifetime in minutes of the runner's `withoutOverlapping` lock: the job timeout plus a margin.
     */
    public static function lockMinutes(): int
    {
        return (int) ceil(((int) config('core.module_packages.job_timeout', 2700)) / 60) + self::LOCK_MARGIN_MINUTES;
    }

    /**
     * Age in seconds after which a claim is stale: strictly longer than the overlap lock, so a run that still holds
     * its lock is never closed as failed.
     */
    public static function staleClaimSeconds(): int
    {
        return self::lockMinutes() * 60 + self::STALE_MARGIN_SECONDS;
    }

    /**
     * Whether an unfinished install (or uninstall) blocks a new one, after closing rows nobody will pick up.
     */
    public function hasUnfinished(): bool
    {
        $this->expireUnclaimed();

        return ModuleInstall::query()->unfinished()->exists();
    }

    /**
     * Close rows left for the scheduler that were never claimed: older than {@see self::UNCLAIMED_EXPIRE_SECONDS},
     * or any age once the site has a real queue (the scheduled runner no longer runs there). Each is marked failed
     * with a plain message and its staging folder removed. Rows queued for a worker are never touched.
     *
     * @return list<string> install uuids closed
     */
    public function expireUnclaimed(): array
    {
        $query = ModuleInstall::query()
            ->where('status', ModuleInstall::STATUS_QUEUED)
            ->where('step_message', self::STEP_WAITING_FOR_SCHEDULER)
            ->whereNull('started_at')
            ->whereNull('finished_at');
        if ($this->usesScheduler()) {
            $query->where('updated_at', '<', Carbon::now()->subSeconds(self::UNCLAIMED_EXPIRE_SECONDS));
        }

        $closed = [];
        foreach ($query->orderBy('id')->get() as $install) {
            // Same condition as a claim, so a row claimed meanwhile is left alone.
            $updated = ModuleInstall::query()
                ->whereKey($install->getKey())
                ->where('status', ModuleInstall::STATUS_QUEUED)
                ->whereNull('started_at')
                ->update(['started_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            if ($updated !== 1) {
                continue;
            }
            $install->refresh();
            $installer = app(ModuleInstaller::class);
            $installer->markFailed($install, self::ERROR_NOT_PICKED_UP);
            $install->forceFill(['step_message' => self::STEP_NOT_PICKED_UP])->save();
            $installer->cleanStaging($install);
            $closed[] = (string) $install->uuid;
        }

        return $closed;
    }

    /**
     * Claimed runs whose process died without closing them (started longer ago than {@see self::staleClaimSeconds()}).
     *
     * @return list<string> install uuids
     */
    public function staleClaims(): array
    {
        $limit = self::staleClaimSeconds();

        return ModuleInstall::query()
            ->unfinished()
            ->whereNotNull('started_at')
            ->where('started_at', '<', Carbon::now()->subSeconds($limit))
            ->pluck('uuid')
            ->map(static fn ($uuid): string => (string) $uuid)
            ->all();
    }
}
