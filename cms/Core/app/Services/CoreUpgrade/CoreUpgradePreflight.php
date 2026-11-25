<?php

namespace Cms\Core\Services\CoreUpgrade;

use Closure;
use Cms\Core\Services\Modules\Install\PreflightResult;

/**
 * What stops a core upgrade before it starts (spec 5.5 step 1). Every problem has a stable code:
 * CORE_UPGRADE_INTERRUPTED, CORE_MODIFIED, CORE_CUSTOM_DEPS (blocking for the admin, a warning on the CLI), CORE_NOT_WRITABLE (CLI only),
 * CORE_DISK, CORE_PHP, CORE_MODULE_INSTALL_RUNNING and, for the admin button only, CORE_NO_RUNNER, CORE_RELEASE_LAYOUT and
 * CORE_CODE_DIR_SHARED. Never writes anything.
 *
 * The admin button does not run the upgrade itself: it asks the upgrade runner to do it
 * (spec 4.4, 4.5). So in admin mode the web user's writability is not checked (the runner checks its own), a live
 * runner heartbeat is required, and the runner's PHP must meet the PHP minimum too.
 */
class CoreUpgradePreflight
{
    /**
     * Untrusted marker of an unfinished upgrade, for the web side only (`{format, from, to, started_at}`, no paths).
     * The runner side never reads it.
     */
    public const STATE_FILE = 'storage/app/ispa/core-upgrade-state.json';

    /** The trusted state of an unfinished upgrade, in the runner-only folder (0600, runner-owned). */
    public const PRIVATE_STATE_FILE = '.ispa-runner/core-upgrade-state.json';

    /** Package size assumed before the signed manifest is known. */
    private const DEFAULT_PACKAGE_BYTES = 150 * 1024 * 1024;

    private readonly string $base;

    private readonly CoreIntegrity $integrity;

    /** @var Closure(string): (float|int|false) */
    private readonly Closure $freeSpace;

    /** @var Closure(): (array{at: int, user: string, uid: int, php_version: string}|null) */
    private readonly Closure $heartbeat;

    /** @var Closure(): bool */
    private readonly Closure $lockHeld;

    /** @var Closure(): bool */
    private readonly Closure $moduleInstallRunning;

    /**
     * @param (callable(string): (float|int|false))|null $freeSpace Free bytes under a path (default disk_free_space).
     * @param (callable(): ?array)|null                  $heartbeat The fresh runner heartbeat or null (default {@see CoreRunnerTick::freshHeartbeat()}).
     * @param (callable(): bool)|null                    $lockHeld  Whether a runner holds its lock now (default {@see CoreRunnerTick::lockHeld()}).
     * @param (callable(): bool)|null                    $moduleInstallRunning Whether a module install is unfinished (default {@see CoreUpgradeRequests::moduleInstallRunning()}).
     */
    public function __construct(?string $base = null, ?CoreIntegrity $integrity = null, ?callable $freeSpace = null, ?callable $heartbeat = null, ?callable $lockHeld = null, ?callable $moduleInstallRunning = null)
    {
        $root = $this->base = rtrim($base ?? base_path(), '/\\');
        $this->integrity = $integrity ?? new CoreIntegrity();
        $this->freeSpace = $freeSpace !== null ? Closure::fromCallable($freeSpace) : static fn (string $path) => @disk_free_space($path);
        $this->heartbeat = $heartbeat !== null ? Closure::fromCallable($heartbeat) : static fn (): ?array => CoreRunnerTick::freshHeartbeat($root);
        $this->lockHeld = $lockHeld !== null ? Closure::fromCallable($lockHeld) : static fn (): bool => CoreRunnerTick::lockHeld($root);
        $this->moduleInstallRunning = $moduleInstallRunning !== null ? Closure::fromCallable($moduleInstallRunning) : static fn (): bool => CoreUpgradeRequests::moduleInstallRunning();
    }

    /**
     * Whether the state file of an unfinished upgrade exists.
     */
    public function interrupted(): bool
    {
        return is_file($this->base.'/'.self::PRIVATE_STATE_FILE) || is_link($this->base.'/'.self::PRIVATE_STATE_FILE);
    }

    /**
     * Whether the marker of an unfinished upgrade exists while no runner works: a runner holding its lock is in the
     * middle of an upgrade, so its marker is not an interrupted one (checked first, so the runner itself, which holds
     * the lock, never reads the marker). Only a plain file owned by the runner (the uid of its heartbeat) counts;
     * without a heartbeat nothing is restorable from the admin. The marker only switches the restore button on; the
     * restore itself reads the runner's private state ({@see self::PRIVATE_STATE_FILE}).
     */
    public function interruptedForAdmin(): bool
    {
        if (($this->lockHeld)()) {
            return false;
        }
        $heartbeat = ($this->heartbeat)();

        return $heartbeat !== null
            && CoreRunnerTick::ownedRegularFile($this->base.'/'.self::STATE_FILE, $heartbeat['uid']);
    }

    /**
     * @param string|null     $targetVersion    Version to upgrade to (null = not known yet, e.g. `--check` without `--to`).
     * @param bool            $forAdmin         The admin button path: no overwrite, a live runner needed, no custom dependencies.
     * @param bool            $overwriteModified CLI `--overwrite-modified`: modified core files do not block.
     * @param int|null        $packageBytes     Signed package size when known (disk check uses 3x).
     * @param CoreManifest|null $new            The new manifest when known (its PHP minimum is checked instead of the installed one).
     * @param list<string>    $extraPhpVersions Other PHP versions that run this site's code (e.g. the web user's), checked like PHP_VERSION.
     */
    public function check(CoreManifest $installed, ?string $targetVersion, bool $forAdmin, bool $overwriteModified = false, ?int $packageBytes = null, ?CoreManifest $new = null, array $extraPhpVersions = []): PreflightResult
    {
        $blocking = [];
        $warnings = [];
        $add = static function (array &$list, string $code, string $message, array $details = []): void {
            $list[] = ['code' => $code, 'message' => $message, 'details' => $details];
        };

        $heartbeat = $forAdmin ? ($this->heartbeat)() : null;

        if ($forAdmin ? $this->interruptedForAdmin() : $this->interrupted()) {
            $add($blocking, 'CORE_UPGRADE_INTERRUPTED', 'A previous core upgrade did not finish. Run `./ispa upgrade --restore` to put the old files back, then try again.');
        }

        if (($this->moduleInstallRunning)()) {
            $add($blocking, 'CORE_MODULE_INSTALL_RUNNING', 'A module is being installed, updated or removed. Try again when it has finished.');
        }

        $modified = array_values(array_filter(
            $this->integrity->modified($installed, $this->base),
            static fn (string $path): bool => ! CoreFileSwapper::isProtected($path)
        ));
        if ($modified !== []) {
            $message = 'Core files were changed on this site: '.count($modified).'.';
            if ($overwriteModified && ! $forAdmin) {
                $add($warnings, 'CORE_MODIFIED', $message.' They go into the upgrade backup and are overwritten.', ['files' => $modified]);
            } else {
                $add($blocking, 'CORE_MODIFIED', $message.' Put customisations in your own project or module, or use --overwrite-modified on the command line.', ['files' => $modified]);
            }
        }

        if ($this->integrity->customDependencies($installed, $this->base)) {
            if ($forAdmin) {
                $add($blocking, 'CORE_CUSTOM_DEPS', 'This site has its own PHP dependencies; upgrade it from the command line (`./ispa upgrade`).', ['cli_only' => true]);
            } else {
                $add($warnings, 'CORE_CUSTOM_DEPS', 'This site has its own PHP dependencies: its own packages are resolved against the new core with a partial `composer update`, then `composer install --no-dev` runs instead of replacing vendor/, so Composer must be available.', ['cli_only' => true]);
            }
        }

        // In admin mode the runner (another Unix user) writes the files, so the web user's writability says nothing.
        $unwritable = $forAdmin ? [] : $this->unwritable($installed, $new);
        if ($unwritable !== []) {
            $add($blocking, 'CORE_NOT_WRITABLE', 'These core paths are not writable by the user running PHP: '.implode(', ', $unwritable).'.', ['paths' => $unwritable]);
        }

        $needed = 3 * ($packageBytes ?? self::DEFAULT_PACKAGE_BYTES);
        $free = ($this->freeSpace)($this->base);
        if (is_numeric($free) && $free < $needed) {
            $add($blocking, 'CORE_DISK', 'Not enough free disk space for the upgrade (needs about '.(int) ceil($needed / 1048576).' MB).', ['free_bytes' => (int) $free, 'needed_bytes' => $needed]);
        }

        $phpMin = ($new ?? $installed)->phpMin;
        $versions = array_merge([PHP_VERSION], $heartbeat !== null ? [$heartbeat['php_version']] : [], array_map('strval', $extraPhpVersions));
        if (preg_match('/\d+(\.\d+){0,2}/', $phpMin, $m) === 1) {
            $tooOld = array_values(array_unique(array_filter($versions, static fn (string $v): bool => version_compare($v, $m[0], '<'))));
            if ($tooOld !== []) {
                $add($blocking, 'CORE_PHP', "PHP {$m[0]} or newer is required (found ".implode(', ', $tooOld).').', ['required' => $m[0], 'running' => $tooOld[0], 'too_old' => $tooOld]);
            }
        }

        // On simple hosting the web user owns the code and starts the runner itself from the admin request: no cron needed.
        $selfRunner = $forAdmin && $heartbeat === null && CoreRunnerTick::selfRunner($this->base);
        if ($forAdmin && $heartbeat === null && ! $selfRunner) {
            $add($blocking, 'CORE_NO_RUNNER', 'No upgrade runner has checked in during the last 3 minutes. Add the runner line to the crontab of the user that owns the code: `'.CoreUpgradeRequests::runnerLine($this->base).'`.');
        }

        if ($forAdmin && CoreRunnerTick::releaseLink($this->base) !== null) {
            $add($blocking, 'CORE_RELEASE_LAYOUT', 'This site is deployed as releases behind a `current` link; upgrade it from the command line (`./ispa upgrade`).', ['cli_only' => true]);
        }

        // Visible to the web user too: the base folder's owner and mode (the runner refuses the same at run time).
        if ($forAdmin && ! $selfRunner && CoreRunnerTick::codeDirShared($this->base, $heartbeat['uid'] ?? null)) {
            $add($blocking, 'CORE_CODE_DIR_SHARED', 'The code folder can be written by group or others, or belongs to another user than the runner; updates from the admin stay off until the folder permissions are fixed.');
        }

        $changes = ['Back up the database and the core files that change.', 'Replace the core files and vendor/, then migrate and rebuild caches.'];

        return new PreflightResult(CoreManifest::FILE, $targetVersion ?? '', $blocking, $warnings, $changes, 'upgrade', $installed->version);
    }

    /**
     * Folders the swap writes into that the running user cannot write ({@see CoreRunnerTick::unwritablePaths()}).
     *
     * @return list<string>
     */
    private function unwritable(CoreManifest $installed, ?CoreManifest $new): array
    {
        return CoreRunnerTick::unwritablePaths($this->base, $installed, $new);
    }
}
