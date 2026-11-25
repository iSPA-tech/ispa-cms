<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\CoreUpgrade\CoreRequestRunner;
use Cms\Core\Services\CoreUpgrade\CoreRunnerTick;
use Cms\Core\Services\CoreUpgrade\CoreUpdateChecker;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeException;
use Cms\Core\Services\CoreUpgrade\CoreUpgrader;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Illuminate\Console\Command;
use Throwable;

/**
 * `./ispa upgrade`: upgrades the iSPA CMS core from the signed hub package (spec 5.5).
 *
 * `--check` and `--dry-run` change nothing under the site. A real swap is refused on a git checkout (a `.git` folder
 * in the base path) unless `--allow-git-checkout` is given, so a developer checkout is never overwritten by accident.
 *
 * `--run-requested` is the upgrade runner: it picks up an upgrade or restore
 * the admin asked for. A real run and `--restore` take the same runner lock, so the CLI and the runner never work at
 * the same time and the admin never marks the CLI's task as dead.
 */
class CoreUpgradeCommand extends Command
{
    protected $signature = 'cms:core-upgrade {--to= : Version to upgrade to (default: the newest the hub offers)} {--check : Print the installed and available versions and the preflight result only} {--dry-run : Download and verify, then print what would change} {--overwrite-modified : Back up modified core files and overwrite them} {--restore : Put back the files of an interrupted upgrade} {--allow-git-checkout : Allow a real swap in a folder that contains .git} {--run-requested : Upgrade runner: pick up an upgrade or restore asked from the admin (cron, every minute)}';

    protected $description = 'Upgrade the iSPA CMS core (ispa upgrade).';

    public function handle(CoreUpgrader $upgrader, CoreUpdateChecker $checker, BackgroundTaskRecorder $recorder): int
    {
        if ((bool) $this->option('run-requested')) {
            return $this->runRequested();
        }

        try {
            if ((bool) $this->option('restore')) {
                return $this->locked($upgrader, fn (): int => $this->restore($upgrader));
            }

            $installed = $upgrader->installed();
            $to = trim((string) $this->option('to'));
            $latest = $to === '' ? ($checker->latest()['version'] ?? null) : $to;

            if ((bool) $this->option('check')) {
                return $this->check($upgrader, $installed->version, $latest);
            }
            if ($latest === null) {
                $this->components->error('No core version was found. Connect the site to the hub or pass --to=<version>.');

                return self::FAILURE;
            }
            // Nothing to do when the target is the installed version, or the hub offers nothing newer.
            if (ModuleVersionConstraint::compare($latest, $installed->version) === 0 || ($to === '' && ModuleVersionConstraint::compare($latest, $installed->version) < 0)) {
                $this->components->info("The core is already on {$installed->version}; nothing to upgrade.");

                return self::SUCCESS;
            }
            if ((bool) $this->option('dry-run')) {
                return $this->dryRun($upgrader, $latest);
            }
            if (($refused = $this->gitGuard($upgrader)) !== null) {
                return $refused;
            }

            return $this->locked($upgrader, fn (): int => $this->upgrade($upgrader, $recorder, $installed->version, $latest));
        } catch (CoreUpgradeException $e) {
            return $this->failed($e);
        }
    }

    private function upgrade(CoreUpgrader $upgrader, BackgroundTaskRecorder $recorder, string $installed, string $latest): int
    {
        $task = null;
        try {
            $task = $recorder->start(CoreUpgradeRequests::TASK_TYPE, 'Core', null);
        } catch (Throwable) {
            // The task row is progress for the admin only; the CLI works without it.
        }
        $this->components->info("Upgrading the core {$installed} -> {$latest}");
        $upgrader->run($latest, (bool) $this->option('overwrite-modified'), $task instanceof BackgroundTask ? $task : null);
        foreach ($upgrader->warnings() as $warning) {
            $this->components->warn($warning);
        }
        $this->components->info("Core upgraded to {$latest}.");

        return self::SUCCESS;
    }

    private function failed(CoreUpgradeException $e): int
    {
        $this->components->error('['.$e->errorCode.'] '.$e->getMessage());
        if ($e->backupUuid !== null) {
            $this->line(CoreUpgrader::RESTORE_HINT.' '.$e->backupUuid.' (admin > Backups).');
        }

        return self::FAILURE;
    }

    /**
     * Run `$work` while holding the runner lock for the whole run; refuse when the runner (or another CLI run) has it.
     *
     * @param callable(): int $work
     */
    private function locked(CoreUpgrader $upgrader, callable $work): int
    {
        $lock = CoreRunnerTick::acquireLock($upgrader->basePath());
        if ($lock === null) {
            $this->components->error('A core upgrade is already running on this site.');

            return self::FAILURE;
        }
        try {
            return $work();
        } catch (CoreUpgradeException $e) {
            return $this->failed($e);
        } finally {
            CoreRunnerTick::releaseLock($lock);
        }
    }

    /**
     * One runner minute. Cron discards the output; one line is printed only when something happened.
     */
    private function runRequested(): int
    {
        $result = app(CoreRequestRunner::class)->tick();
        if (! in_array($result, ['idle', 'cannot_run', 'busy', 'waiting_backup'], true)) {
            $this->line('Core upgrade request: '.$result);
        }

        return $result === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function gitGuard(CoreUpgrader $upgrader): ?int
    {
        if (is_dir($upgrader->basePath().'/.git') && ! (bool) $this->option('allow-git-checkout')) {
            $this->components->error('This folder is a git checkout (.git found), so the upgrade will not replace its files.');
            $this->line('Use a site installed from the core package, or pass --allow-git-checkout if you really mean it.');

            return self::FAILURE;
        }

        return null;
    }

    private function restore(CoreUpgrader $upgrader): int
    {
        if (($refused = $this->gitGuard($upgrader)) !== null) {
            return $refused;
        }
        $pending = $upgrader->pendingState();
        $upgrader->restore();
        $this->components->info('The core files were put back.');
        if ($pending !== null && $pending['backup_uuid'] !== null) {
            $this->line(CoreUpgrader::RESTORE_HINT.' '.$pending['backup_uuid'].' (admin > Backups).');
        }

        return self::SUCCESS;
    }

    private function check(CoreUpgrader $upgrader, string $installed, ?string $latest): int
    {
        $this->line("Installed core: {$installed}");
        $this->line('Available core: '.($latest ?? 'unknown (hub not connected or no core package published)'));
        $this->runnerLine($upgrader->basePath());
        $result = $upgrader->preflight($latest, false, (bool) $this->option('overwrite-modified'));
        foreach ($result->warnings as $warning) {
            $this->components->warn('['.$warning['code'].'] '.$warning['message']);
        }
        foreach ($result->blocking as $problem) {
            $this->components->error('['.$problem['code'].'] '.$problem['message']);
            foreach (array_slice((array) ($problem['details']['files'] ?? []), 0, 20) as $file) {
                $this->line('  '.$file);
            }
        }
        if ($result->canInstall()) {
            $this->components->info('Preflight passed. Nothing was changed.');
        }

        return $result->canInstall() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The `--check` line about the upgrade runner: last seen, quiet (a stale heartbeat) or not set up.
     */
    private function runnerLine(string $base): void
    {
        $heartbeat = CoreRunnerTick::freshHeartbeat($base);
        if ($heartbeat !== null) {
            $this->line('Upgrade runner: last seen '.date('c', $heartbeat['at']).' as '.$heartbeat['user']);

            return;
        }
        $setup = (new CoreUpgradeRequests(app(BackgroundTaskRecorder::class), $base))->runnerSetup();
        if ($setup['root_owner']) {
            // The runner never runs as root: there is no line to add.
            $this->line('Upgrade runner: not possible. '.InstallCommand::ROOT_OWNER_TEXT);

            return;
        }
        $raw = is_file($base.'/'.CoreRunnerTick::HEARTBEAT_FILE)
            ? json_decode((string) @file_get_contents($base.'/'.CoreRunnerTick::HEARTBEAT_FILE, false, null, 0, CoreRunnerTick::REQUEST_MAX_BYTES), true)
            : null;
        if (is_array($raw) && is_int($raw['at'] ?? null)) {
            $this->line('Upgrade runner: not seen in the last 3 minutes (last as '.(is_string($raw['user'] ?? null) ? $raw['user'] : 'unknown').' at '.date('c', $raw['at']).').');
            $this->line('Runner line for the crontab of '.$setup['user'].': '.$setup['line']);

            return;
        }
        $this->line('Upgrade runner: not set up. Add to the crontab of '.$setup['user'].': '.$setup['line']);
    }

    private function dryRun(CoreUpgrader $upgrader, string $latest): int
    {
        $report = $upgrader->dryRun($latest, (bool) $this->option('overwrite-modified'));
        $this->line("Would upgrade {$report['from']} -> {$report['to']}: {$report['write']} files written, {$report['remove']} removed, replaced folders: ".implode(', ', $report['replace']));
        if ($report['blocking'] !== []) {
            $this->components->warn('Preflight would stop it: '.implode(', ', $report['blocking']));
        }
        $this->components->info('Dry run: nothing was changed.');

        return $report['blocking'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
