<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Services\UpdateBatch\UpdateBatches;
use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\Backup\BackupFolderAccess;
use Cms\Core\Services\CoreUpgrade\CoreManifest;
use Cms\Core\Services\CoreUpgrade\CoreRunnerLauncher;
use Cms\Core\Services\CoreUpgrade\CoreRunnerTick;
use Cms\Core\Services\CoreUpgrade\CoreUpdateChecker;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeException;
use Cms\Core\Services\CoreUpgrade\CoreUpgrader;
use Cms\Core\Services\CoreUpgrade\CoreUpgradePreflight;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Core update card of the module management page (Webmaster only, like the module installs): what is installed, what
 * the hub offers, whether the preflight allows the admin button, and asking the upgrade runner (cron job of the code
 * owner) to upgrade or to restore an interrupted upgrade.
 *
 * The route middleware `admin.webmaster` refuses other admins; the controller checks `isWebmaster()` again.
 */
final class CoreUpdateController extends AdminController
{
    private const LOCK_SECONDS = 60;

    public const COMMAND = './ispa upgrade';

    /**
     * Query `refresh=1` makes the newest version come from the hub, not from the cache (see CoreUpdateChecker::latest).
     *
     * @return mixed
     */
    public function show(Request $request, CoreUpgrader $upgrader, CoreUpgradePreflight $preflight, CoreUpdateChecker $checker, CoreUpgradeRequests $requests)
    {
        if (! $this->isWebmaster($request)) {
            return $this->forbidden();
        }
        $this->prepareSelfRunner($requests->base());
        $requests->closeAbandoned();

        // `?refresh=1` (the page's reload button): ask the hub now instead of answering from the hour-long cache.
        $latest = $checker->latest($request->boolean('refresh'));
        $manifest = $this->manifest($upgrader);
        $installed = $manifest?->version ?? (string) config('ispa-cms.app_version', '');
        $issues = $manifest === null
            ? [['code' => 'CORE_NO_MANIFEST', 'message' => 'This site was not installed from a core package, so it cannot upgrade itself.']]
            : $this->issues($preflight, $manifest, $latest['version'] ?? null);
        $running = $requests->running();
        $heartbeat = CoreRunnerTick::freshHeartbeat($requests->base(), now()->getTimestamp());

        $newer = $latest !== null && $installed !== '' && ModuleVersionConstraint::compare($latest['version'], $installed) > 0;

        return ires()->data([
            'installed' => $installed,
            'latest' => $latest['version'] ?? null,
            'notes_url' => $latest['notes_url'] ?? null,
            'can_update' => $newer && $issues === [] && $running === null,
            'preflight' => ['ok' => $issues === [], 'issues' => $issues],
            'running' => $running === null ? null : ['uuid' => $running->uuid, 'progress' => (int) $running->progress, 'status' => $running->status],
            'command' => self::COMMAND,
            'runner' => $heartbeat === null ? null : ['seen_at' => Carbon::createFromTimestamp($heartbeat['at'])->toIso8601String(), 'user' => $heartbeat['user']],
            'runner_setup' => $requests->runnerSetup(),
            'web_user' => self::webUser(),
            'can_restore' => $manifest !== null && $preflight->interruptedForAdmin() && $heartbeat !== null && $running === null,
            // No core-manifest.json: a site iSPA deploys itself; the card shows only that iSPA keeps it up to date.
            'managed' => $manifest === null,
        ]);
    }

    /**
     * Body `{ version, confirm_backup: true }`. Answers 202 `{ background_task_uuid }`; 422 without the confirmation or
     * with a bad version; 409 CORE_UPGRADE_RUNNING while one runs, or with the preflight issues when it blocks.
     *
     * @return mixed
     */
    public function store(Request $request, CoreUpgrader $upgrader, CoreUpgradePreflight $preflight, CoreUpgradeRequests $requests)
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isWebmaster()) {
            return $this->forbidden();
        }
        if (($refused = app(UpdateBatches::class)->refusal()) !== null) {
            return $refused;
        }
        $this->prepareSelfRunner($requests->base());
        $requests->closeAbandoned();

        $data = $request->validate([
            'version' => ['required', 'string', 'regex:/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/'],
            'confirm_backup' => ['required', 'accepted'],
        ]);
        if ($request->input('confirm_backup') !== true) {
            return ires()->error()->code(422)->errorCode('CORE_BACKUP_CONFIRMATION_REQUIRED')
                ->message('Confirm that you have a backup to start the upgrade.');
        }
        $version = (string) $data['version'];

        $lock = $this->startLock();
        if (! $lock->get()) {
            return $this->runningAnswer();
        }

        try {
            if ($requests->running() !== null) {
                return $this->runningAnswer();
            }

            $manifest = $this->manifest($upgrader);
            if ($manifest === null) {
                return ires()->conflict('This site was not installed from a core package.')->errorCode('CORE_NO_MANIFEST');
            }
            $issues = $this->issues($preflight, $manifest, $version);
            if ($issues !== []) {
                return ires()->conflict('The upgrade cannot start from the admin.')->errorCode('CORE_PREFLIGHT_FAILED')
                    ->data(['preflight' => ['ok' => false, 'issues' => $issues]]);
            }

            $task = $requests->create('upgrade', $version, (int) $user->id);
        } finally {
            $lock->release();
        }

        try {
            event(new AuditLogEvent('core.upgrade_started', ['from' => $manifest->version, 'to' => $version, 'task_uuid' => $task->uuid], BackgroundTask::class, (int) $task->id, null, null, (int) $user->id));
        } catch (Throwable) {
            // Best effort: an audit failure never blocks the upgrade.
        }

        $this->startInBackground();

        return ires()->accepted(['background_task_uuid' => $task->uuid], 'Core upgrade requested.');
    }

    /**
     * Body `{ confirm: true }`. Asks the runner to put back the files of an interrupted upgrade. Answers 202
     * `{ background_task_uuid }`; 422 CORE_CONFIRMATION_REQUIRED without the confirmation; 409 CORE_UPGRADE_RUNNING,
     * CORE_NOT_INTERRUPTED or CORE_NO_RUNNER.
     *
     * @return mixed
     */
    public function restore(Request $request, CoreUpgradePreflight $preflight, CoreUpgradeRequests $requests)
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isWebmaster()) {
            return $this->forbidden();
        }
        if (($refused = app(UpdateBatches::class)->refusal()) !== null) {
            return $refused;
        }
        $this->prepareSelfRunner($requests->base());
        if ($request->input('confirm') !== true) {
            return ires()->error()->code(422)->errorCode('CORE_CONFIRMATION_REQUIRED')
                ->message('Confirm to put back the files of the interrupted upgrade.');
        }
        $requests->closeAbandoned();

        $lock = $this->startLock();
        if (! $lock->get()) {
            return $this->runningAnswer();
        }

        try {
            if ($requests->running() !== null) {
                return $this->runningAnswer();
            }
            // The runner first: only a state file owned by the runner (its heartbeat uid) counts as interrupted.
            if (CoreRunnerTick::freshHeartbeat($requests->base(), now()->getTimestamp()) === null && ! CoreRunnerTick::selfRunner($requests->base())) {
                return ires()->conflict('The upgrade runner is not running on this server.')->errorCode('CORE_NO_RUNNER');
            }
            if (! $preflight->interruptedForAdmin()) {
                return ires()->conflict('No interrupted core upgrade to restore.')->errorCode('CORE_NOT_INTERRUPTED');
            }

            $task = $requests->create('restore', null, (int) $user->id);
        } finally {
            $lock->release();
        }

        try {
            event(new AuditLogEvent('core.restore_started', ['task_uuid' => $task->uuid], BackgroundTask::class, (int) $task->id, null, null, (int) $user->id));
        } catch (Throwable) {
            // Best effort: an audit failure never blocks the restore.
        }

        $this->startInBackground();

        return ires()->accepted(['background_task_uuid' => $task->uuid], 'Core restore requested.');
    }

    private function isWebmaster(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->isWebmaster();
    }

    private function forbidden(): mixed
    {
        return ires()->forbidden('Only a Webmaster can use this endpoint.')->errorCode('WEBMASTER_ONLY');
    }

    private function manifest(CoreUpgrader $upgrader): ?CoreManifest
    {
        try {
            return $upgrader->installed();
        } catch (CoreUpgradeException) {
            return null;
        }
    }

    /**
     * Blocking preflight problems in admin mode, as `{code, message, files?}`.
     *
     * @return list<array{code: string, message: string, files?: list<string>}>
     */
    private function issues(CoreUpgradePreflight $preflight, CoreManifest $manifest, ?string $version): array
    {
        $issues = [];
        foreach ($preflight->check($manifest, $version, true)->blocking as $item) {
            $issue = ['code' => (string) $item['code'], 'message' => (string) $item['message']];
            $files = $item['details']['files'] ?? null;
            if (is_array($files)) {
                $issue['files'] = array_values(array_map('strval', $files));
            }
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * The account PHP runs as here (the web server user), for the one-line fix the card offers: give the site folder
     * to this account and updates run on their own. Null when it cannot be told or is root.
     */
    private static function webUser(): ?string
    {
        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid') || posix_geteuid() === 0) {
            return null;
        }
        $name = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');

        return preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/i', $name) === 1 ? $name : null;
    }

    /**
     * One upgrade or restore request at a time per site.
     */
    private function startLock(): Lock
    {
        return Cache::lock('core:upgrade:start:'.sha1((string) config('app.url', '')), self::LOCK_SECONDS);
    }

    private function runningAnswer(): mixed
    {
        return ires()->conflict('A core upgrade is already running on this site.')->errorCode('CORE_UPGRADE_RUNNING');
    }

    /**
     * Start the backup and the runner now, in the background, instead of waiting for cron: on simple hosting (the web
     * user owns the code) the upgrade then needs no cron at all. Best effort; the scheduler and the runner cron pick
     * the request up otherwise.
     */
    private function startInBackground(): void
    {
        try {
            app(CoreRunnerLauncher::class)->tick();
        } catch (Throwable $e) {
            report($e);
        }
    }


    /**
     * Simple hosting (the web user owns the code): drop a group/other write bit from the code folder, which zip
     * unpacking or `chmod -R 775` leaves and the runner refuses, and check in as the runner, so the card, the
     * preflight and the restore check need no runner cron. Does nothing when another user owns the code.
     */
    private function prepareSelfRunner(string $base): void
    {
        try {
            if (CoreRunnerTick::tightenBase($base)) {
                logger()->info('Core update: removed group/other write access from the code folder.');
            }
            if (CoreRunnerTick::selfRunner($base)) {
                (new CoreRunnerTick($base))->writeHeartbeat();
            }
            // The runner writes the pre-upgrade backup there.
            if (($opened = BackupFolderAccess::openForRunner(storage_path('backups'))) > 0) {
                logger()->info('Core update: opened '.$opened.' backup folder(s) to the web group for the upgrade runner.');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

}
