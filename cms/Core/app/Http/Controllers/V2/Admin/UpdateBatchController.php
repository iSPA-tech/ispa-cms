<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Models\User;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Jobs\UpdateBatch\AdvanceUpdateBatchJob;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\InstallRunDispatcher;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\UpdateBatch\UpdateBatches;
use Cms\Core\Services\UpdateBatch\UpdateBatchPlanner;
use Cms\Core\Services\UpdateBatch\UpdateBatchRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * "Nâng cấp tất cả" (upgrade-all spec 2026-11-26): the plan, the start, the open or last run, stop and hide.
 * Webmaster only, like module installs. A run is addressed by its background task uuid.
 */
final class UpdateBatchController extends AdminController
{
    /** Seconds the start lock is held at most. */
    private const LOCK_SECONDS = 60;

    /**
     * What a run would do now: `{items, left_out, runner_ready}`. Query `kinds[]` (default every kind).
     *
     * @return mixed
     */
    public function plan(Request $request, UpdateBatchPlanner $planner, UpdateBatches $batches)
    {
        $user = $this->webmaster($request);
        if (! $user instanceof User) {
            return $user;
        }
        $kinds = $this->kinds($request);

        try {
            $plan = $planner->plan($user, $kinds);
        } catch (PlatformHubException $e) {
            return $this->hubUnavailable($e);
        }

        return ires()->data($plan + ['runner_ready' => $batches->runnerReady()]);
    }

    /**
     * Start a run. Body: `{kinds, items: [{kind, key, to}], backup}` (the items the person confirmed). Refused while
     * another run, an install or a core upgrade is open, without a runner, with nothing to do, or when the plan no longer
     * matches the confirmation. Answers 202 with the run.
     *
     * @return mixed
     */
    public function store(Request $request, UpdateBatchPlanner $planner, UpdateBatches $batches)
    {
        $user = $this->webmaster($request);
        if (! $user instanceof User) {
            return $user;
        }
        $data = $request->validate([
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['string', Rule::in(UpdateBatchPlanner::KINDS)],
            'items' => ['required', 'array'],
            'items.*.kind' => ['required', 'string', Rule::in(UpdateBatchPlanner::KINDS)],
            'items.*.key' => ['required', 'string', 'max:200'],
            'items.*.to' => ['nullable', 'string', 'max:64'],
            'backup' => ['nullable', 'boolean'],
        ]);
        $kinds = $this->kinds($request);

        $lock = Cache::lock('core:update-batch:start:' . sha1((string) config('ispa-cms.cms_domain', '') . '|' . (string) config('app.url', '')), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $batches->refusal() ?? ires()->conflict('An upgrade-all run is being started.')->errorCode(UpdateBatches::ERROR_RUNNING);
        }

        try {
            $refused = $batches->refusal() ?? $this->otherWorkRefusal($batches);
            if ($refused !== null) {
                return $refused;
            }

            try {
                $plan = $planner->plan($user, $kinds);
            } catch (PlatformHubException $e) {
                return $this->hubUnavailable($e);
            }
            if ($plan['items'] === []) {
                return ires()->error()->code(422)->errorCode('UPDATE_BATCH_EMPTY')->message('Nothing on this site can be upgraded now.');
            }
            if ($planner->signature($plan['items']) !== $planner->signature($data['items'])) {
                return ires()->conflict('The list of upgrades changed; review it again.')->errorCode('UPDATE_BATCH_PLAN_CHANGED');
            }

            $batch = $batches->start($user, $plan, $kinds, ($data['backup'] ?? true) !== false);
        } finally {
            $lock->release();
        }

        AdvanceUpdateBatchJob::kick();

        return ires()->accepted($batches->presented($batch->refresh()), 'Upgrade-all run started.');
    }

    /**
     * The open run, else the last finished one not hidden; `data: null` when none.
     *
     * @return mixed
     */
    public function current(Request $request, UpdateBatches $batches)
    {
        $user = $this->webmaster($request);
        if (! $user instanceof User) {
            return $user;
        }
        $batch = $batches->latestVisible();

        return ires()->data($batch !== null ? $batches->presented($batch) : null);
    }

    /**
     * "Dừng": an install at work finishes, one nobody picked up is closed, the rest are not started; a run with nothing
     * at work ends at once.
     *
     * @return mixed
     */
    public function stop(Request $request, string $uuid, UpdateBatches $batches)
    {
        $user = $this->webmaster($request);
        if (! $user instanceof User) {
            return $user;
        }
        $batch = $batches->find($uuid);
        if ($batch === null) {
            return ires()->notFound('No such upgrade-all run.')->errorCode('UPDATE_BATCH_NOT_FOUND');
        }
        if (! $this->isOpen($batch)) {
            return ires()->conflict('This upgrade-all run has already finished.')->errorCode('UPDATE_BATCH_NOT_OPEN');
        }
        $batches->change($batch, static fn (array $result): array => ['stop_requested' => true] + $result);
        // End the run now when nothing is at work (no runner picked it up, or only a queued install waits); an install
        // already running finishes first. Never starts an item: the stop flag ends the run before the next one.
        app(UpdateBatchRunner::class)->advance(itemsAllowed: false);

        return ires()->data($batches->presented($batch->refresh()));
    }

    /**
     * "Ẩn": hide a finished run's outcome.
     *
     * @return mixed
     */
    public function dismiss(Request $request, string $uuid, UpdateBatches $batches)
    {
        $user = $this->webmaster($request);
        if (! $user instanceof User) {
            return $user;
        }
        $batch = $batches->find($uuid);
        if ($batch === null) {
            return ires()->notFound('No such upgrade-all run.')->errorCode('UPDATE_BATCH_NOT_FOUND');
        }
        if ($this->isOpen($batch)) {
            return ires()->conflict('This upgrade-all run is still open.')->errorCode('UPDATE_BATCH_OPEN');
        }
        $batch = $batches->change($batch, static fn (array $result): array => ['dismissed' => true] + $result);

        return ires()->data($batches->presented($batch));
    }

    /**
     * The Webmaster making the request, or the 403 answer.
     */
    private function webmaster(Request $request): mixed
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isWebmaster()) {
            return ires()->forbidden('Only a Webmaster can use this endpoint.')->errorCode('WEBMASTER_ONLY');
        }

        return $user;
    }

    /**
     * Requested kinds in the canonical order; every kind when none is given.
     *
     * @return list<string>
     */
    private function kinds(Request $request): array
    {
        $asked = array_filter((array) $request->input('kinds', []), 'is_string');

        return $asked === [] ? UpdateBatchPlanner::KINDS : array_values(array_intersect(UpdateBatchPlanner::KINDS, $asked));
    }

    /**
     * A module install or a core upgrade at work, or no runner: the answer that refuses a start, else null.
     */
    private function otherWorkRefusal(UpdateBatches $batches): mixed
    {
        if (app(InstallRunDispatcher::class)->hasUnfinished()) {
            return ires()->conflict('Another module install is still running on this site.')->errorCode('MODULE_INSTALL_RUNNING');
        }
        $core = app(CoreUpgradeRequests::class);
        $core->closeAbandoned();
        if ($core->running() !== null) {
            return ires()->conflict('An iSPA CMS update is queued or running on this site.')->errorCode('CORE_UPGRADE_RUNNING');
        }
        if (! $batches->runnerReady()) {
            return ires()->error()->code(422)->errorCode(InstallPreflight::NO_RUNNER)
                ->message('The scheduler (cron) has not run in the last few minutes, so nothing would run the upgrades.');
        }

        return null;
    }

    private function hubUnavailable(PlatformHubException $e): mixed
    {
        return ires()->error()->code(502)->errorCode('MODULE_CATALOG_UNAVAILABLE')
            ->message('The module catalog is not available from the platform hub (' . $e->reason() . ').');
    }

    private function isOpen(BackgroundTask $batch): bool
    {
        return in_array($batch->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true);
    }
}
