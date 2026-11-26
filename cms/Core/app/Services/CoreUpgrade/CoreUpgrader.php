<?php

namespace Cms\Core\Services\CoreUpgrade;

use Cms\Core\Services\Modules\Install\ModuleVersionConstraint;
use App\Support\Modules\RuntimePrecedence;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Modules\Packages\SafeExtractor;
use Cms\Core\Services\Toolchain\ProcessRunner;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * The core upgrade engine (spec 5.5), shared by `cms:core-upgrade` and the admin job.
 *
 * Every path it writes lies under the injected base path; nothing here reads `base_path()` except as the default
 * of that parameter. Flow of run(): preflight, DB backup (BackupGate, reason `pre_core_upgrade`), signed download,
 * safe unzip into `.ispa-runner/upgrade-staging/<to>`, tar backup of every core file that changes, state file,
 * swap, finish commands, health check, new manifest, cleanup. Any failure after the state file is written reverts
 * the files (the DB backup is kept; migrations are never reversed). A process killed during the swap leaves the state
 * file behind: the preflight then refuses to start and restore() puts the old files back.
 *
 * Flat install: files replaced in place, `vendor/` swapped by rename. Release layout (a `current` symlink beside
 * or above the base): the base is copied to a new release folder (shared `storage`, `runtime`, `.env` stay linked),
 * the upgrade is applied there and `current` is switched atomically; the old release stays as the backup.
 *
 * CI-deployed release (deploy-origin manifest, written by the deploy hook): upgraded in place inside the live release
 * like a flat install (the deploy script refuses non-numeric release folders, and earlier releases are its rollback),
 * with the narrower file plan of {@see CoreFileSwapper::plan()}; refused when the package's Core, Content or Widget is
 * older than the release's (CORE_PACKAGE_OLDER) or needs PHP packages the release's composer.lock lacks
 * (CORE_NEEDS_DEPLOY); the placed manifest stays a deploy manifest.
 *
 * Staging and backups live in `<base>/.ispa-runner/` (runner-only, 0700), never in the shared `storage/` tree: no
 * folder on the path from the base to a staged or backed-up file is writable by anyone but the code owner. Before any
 * work the base and that folder are checked ({@see CoreRunnerTick::codeDirShared()}); a base others could write
 * refuses with CORE_CODE_DIR_SHARED. The trusted state is `.ispa-runner/core-upgrade-state.json` (0600); only a marker
 * without paths goes to `storage/app/ispa` for the admin. The backup tar path is never read from a file: it is
 * always `.ispa-runner/upgrade-backups/<run>/files.tar`, `<run>` being one checked segment of the private state.
 */
class CoreUpgrader
{
    private readonly string $base;

    /** @var list<string> Non-fatal warnings of the last run() */
    private array $warnings = [];

    /** Safety backup the web side took for the next run() ({@see self::usePreparedBackup()}); used once. */
    private ?string $preparedBackupUuid = null;

    /** Runner-only folder under the base that holds staging, backups and the upgrade state. */
    public const RUNNER_DIR = '.ispa-runner';

    /** Hint the CLI prints after a failed upgrade. */
    public const RESTORE_HINT = 'The files were put back. Migrations are not reversed: if the new version already migrated the database and you need the old data shape, restore the database backup';

    public function __construct(
        ?string $base = null,
        private readonly ?CoreUpgradePreflight $preflightService = null,
        private readonly ?CorePackageSource $source = null,
        private readonly ?PackageVerifier $verifier = null,
        private readonly ?BackupGate $backupGate = null,
        private readonly ?ProcessRunner $runner = null,
        private readonly ?CoreHealthCheck $health = null,
        private readonly ?BackgroundTaskRecorder $recorder = null,
    ) {
        $this->base = rtrim($base ?? base_path(), '/\\');
    }

    /**
     * Warnings of the last run() (for the CLI to print).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function basePath(): string
    {
        return $this->base;
    }

    /**
     * Use the safety backup the web side took for this request (`cms:core-upgrade-backup`) instead of taking one in
     * run(). The runner is the code owner and may not reach the web user's backup folders; the web user always can.
     * run() still checks the backup ({@see BackupGate::prepared()}) and takes its own when it does not qualify.
     */
    public function usePreparedBackup(?string $uuid): void
    {
        $this->preparedBackupUuid = $uuid;
    }

    /**
     * @throws CoreUpgradeException
     */
    public function installed(): CoreManifest
    {
        try {
            $manifest = CoreManifest::installed($this->base);
        } catch (\InvalidArgumentException $e) {
            throw new CoreUpgradeException('CORE_NO_MANIFEST', 'core-manifest.json is not valid: '.$e->getMessage());
        }

        return $manifest ?? throw new CoreUpgradeException('CORE_NO_MANIFEST', 'This site was not installed from a core package (core-manifest.json is missing), so it cannot upgrade itself.');
    }

    public function preflight(?string $targetVersion, bool $forAdmin = false, bool $overwriteModified = false): \Cms\Core\Services\Modules\Install\PreflightResult
    {
        return $this->preflightService()->check($this->installed(), $targetVersion, $forAdmin, $overwriteModified);
    }

    /**
     * Download, verify and unzip the target and report what an upgrade would do. Changes nothing under the base.
     *
     * @return array{from: string, to: string, write: int, remove: int, replace: list<string>, skipped: list<string>, blocking: list<string>}
     */
    public function dryRun(string $targetVersion, bool $overwriteModified = false): array
    {
        $installed = $this->installed();
        $staging = $this->stagingPath($targetVersion);
        $this->assertCodeDir();
        try {
            [, $new] = $this->fetch($targetVersion, $staging);
            $result = $this->preflightService()->check($installed, $targetVersion, false, $overwriteModified, null, $new);
            $plan = (new CoreFileSwapper($this->base))->plan($installed, $new, $this->treeOf($staging), $this->customDependencies($installed));

            return [
                'from' => $installed->version, 'to' => $new->version,
                'write' => count($plan['write']), 'remove' => count($plan['remove']),
                'replace' => $plan['replace'], 'skipped' => $plan['skipped'], 'blocking' => $result->blockingCodes(),
            ];
        } finally {
            $this->cleanStaging($staging);
        }
    }

    /**
     * Run the upgrade.
     *
     * @param list<string> $extraPhpVersions Other PHP versions that run this site (e.g. the web user's when the runner upgrades), checked by the preflight.
     *
     * @throws CoreUpgradeException When refused or failed; files are already reverted when this is thrown after the swap.
     */
    public function run(string $targetVersion, bool $overwriteModified, ?BackgroundTask $task = null, ?int $userId = null, array $extraPhpVersions = []): void
    {
        $started = microtime(true);
        $this->warnings = [];
        $installed = $this->installed();
        $staging = $this->stagingPath($targetVersion);
        $backupUuid = null;
        $state = null;

        try {
            $this->assertCodeDir();
            $this->progress($task, 3, 'Checking the site.');
            $this->assertPreflight($this->preflightService()->check($installed, $targetVersion, false, $overwriteModified, null, null, $extraPhpVersions));
            if (ModuleVersionConstraint::compare($targetVersion, $installed->version) <= 0) {
                throw new CoreUpgradeException('CORE_NOT_NEWER', "Version {$targetVersion} is not newer than the installed {$installed->version}.");
            }

            $prepared = $this->preparedBackupUuid !== null
                ? $this->backupGate()->prepared($this->preparedBackupUuid, Backup::REASON_PRE_CORE_UPGRADE)
                : null;
            $this->preparedBackupUuid = null;
            if ($prepared !== null) {
                $this->progress($task, 8, 'Using the safety backup of the database the site took.');
                $backupUuid = (string) $prepared->uuid;
            } else {
                $this->progress($task, 8, 'Taking a safety backup of the database.');
                $this->assertBackupWritable();
                try {
                    $backupUuid = (string) $this->backupGate()->ensure(Backup::REASON_PRE_CORE_UPGRADE, $userId)->uuid;
                } catch (BackupRequiredException $e) {
                    throw new CoreUpgradeException('CORE_BACKUP_FAILED', 'The database backup failed, so nothing was changed.');
                }
            }

            $this->progress($task, 20, 'Downloading and verifying the core package.');
            [$signed, $new] = $this->fetch($targetVersion, $staging);
            $customDeps = $this->customDependencies($installed);
            $this->assertPreflight($this->preflightService()->check($installed, $targetVersion, false, $overwriteModified, $signed->zipSize, $new, $extraPhpVersions));
            $this->assertModulesKeepTheirPlace($staging);
            if ($installed->fromDeploy()) {
                $this->assertDeployCompatible($staging);
            }

            $layout = $installed->fromDeploy() ? null : $this->releaseLink();
            $tree = $this->treeOf($staging);
            if ($customDeps) {
                $this->resolveCustomDependencies($new, $tree);
            }
            $swapDir = $layout === null ? $this->base : $this->base.'-core-'.$new->version;
            $plan = (new CoreFileSwapper($this->base))->plan($installed, $new, $tree, $customDeps);
            $stamp = date('YmdHis');
            $from = $installed->version;
            $tar = $layout === null ? $this->backupsRoot().'/'.$from.'-'.$new->version.'/files.tar' : null;

            $this->progress($task, 40, 'Backing up the core files that change.');
            if ($tar !== null) {
                $this->privateDir($this->runnerDir(), 'CORE_STATE_INVALID');
                $this->privateDir($this->backupsRoot(), 'CORE_STATE_INVALID');
                $this->privateDir(dirname($tar), 'CORE_STATE_INVALID');
                (new CoreFileSwapper($this->base))->backup($plan, $tar);
            }

            $added = array_values(array_filter($plan['write'], fn (string $p): bool => ! is_file($this->base.'/'.$p)));
            $state = [
                'from' => $from, 'to' => $new->version, 'layout' => $layout === null ? 'flat' : 'release',
                'started_at' => date('c'), 'stamp' => $stamp, 'run' => $tar === null ? null : basename(dirname($tar)), 'backup_uuid' => $backupUuid,
                'added' => $added, 'aside' => [], 'staging' => $staging,
                'aside_planned' => array_map(static fn (string $p): array => ['path' => $p, 'old' => $p.'.old-'.$stamp], $plan['replace']),
            ];

            $this->progress($task, 55, 'Replacing the core files.');
            if ($layout === null) {
                $this->writeState($state);
            } else {
                $state += ['previous_release' => $this->base, 'new_release' => $swapDir, 'link' => $layout['link']];
                $this->writeState($state);
                CoreFileSwapper::copyRelease($this->base, $swapDir, $this->sharedNames(), [self::RUNNER_DIR]);
                // Again, now into the new release too: after the switch the CLI and the runner run from there.
                $this->writeState($state);
            }
            $this->assertOwnStaging($staging);
            $swapper = new CoreFileSwapper($swapDir);
            $state['aside'] = $swapper->apply($plan, $tree, $stamp, $new->checked);

            $this->progress($task, 70, 'Migrating and rebuilding.');
            $warnings = $this->warnings = $this->finish($swapDir, $customDeps, $installed->fromDeploy());

            if ($layout !== null) {
                $this->placeManifest($staging, $swapDir);
                $this->switchLink($layout['link'], $swapDir);
            }

            $this->progress($task, 88, 'Checking the site still answers.');
            $failures = $this->health()->check();
            if ($failures !== []) {
                throw new CoreUpgradeException('CORE_HEALTH_FAILED', 'The upgraded site did not pass the health check ('.implode('; ', $failures).').', $backupUuid);
            }

            if ($layout === null && $installed->fromDeploy()) {
                // The health check compiled views as the code owner; the web user cannot touch() those files (Blade
                // sets their mtime), so pages answered 500 until they were cleared (seen on a CI-deployed site).
                $this->runner()->run([(new PhpExecutableFinder())->find() ?: 'php', 'artisan', 'view:clear'], $this->base, [], true);
                $this->placeDeployManifest($installed, $new, $plan);
            } elseif ($layout === null) {
                $this->placeManifest($staging, $this->base);
            }
            $swapper->dropAside($state['aside']);
            $this->clearState($state);
            $this->cleanStaging($staging);
            $seconds = (int) round(microtime(true) - $started);
            $this->audit('core.upgraded', ['from' => $from, 'to' => $new->version, 'backup_uuid' => $backupUuid, 'seconds' => $seconds, 'warnings' => count($warnings)], $userId);
            $this->succeed($task, ['from' => $from, 'to' => $new->version, 'backup_uuid' => $backupUuid, 'seconds' => $seconds, 'warnings' => $warnings]);
        } catch (Throwable $e) {
            $error = $e instanceof CoreUpgradeException ? $e : $this->wrap($e, $backupUuid);
            if ($state !== null) {
                try {
                    $this->revertState($state);
                } catch (Throwable) {
                    $error = new CoreUpgradeException('CORE_RESTORE_FAILED', 'The upgrade failed and the files could not be put back automatically. Run `./ispa upgrade --restore`.', $backupUuid);
                }
            }
            $this->cleanStaging($staging);
            $this->audit('core.upgrade_failed', ['from' => $installed->version, 'to' => $targetVersion, 'code' => $error->errorCode, 'backup_uuid' => $backupUuid], $userId);
            $this->fail($task, $error);

            throw $error;
        }
    }

    /**
     * Put the files of an interrupted or failed upgrade back and clear the state file.
     *
     * @throws CoreUpgradeException When there is nothing to restore.
     */
    public function restore(): void
    {
        $this->assertCodeDir();
        $state = $this->readState();
        if ($state === null) {
            // A marker without a private state (a kill between the two unlinks, or an old-format file) would keep the
            // admin's restore button on for ever: remove it. unlink() never follows a link.
            @unlink($this->base.'/'.CoreUpgradePreflight::STATE_FILE);

            throw new CoreUpgradeException('CORE_NOTHING_TO_RESTORE', 'There is no interrupted core upgrade to restore.');
        }
        $this->revertState($state);
        $this->cleanStaging((string) ($state['staging'] ?? ''));
    }

    /**
     * Backup uuid and the restore hint of the state file, for the CLI.
     *
     * @return array{from: string, to: string, backup_uuid: ?string}|null
     */
    public function pendingState(): ?array
    {
        $s = $this->readState();

        return $s === null ? null : ['from' => (string) $s['from'], 'to' => (string) $s['to'], 'backup_uuid' => $s['backup_uuid'] ?? null];
    }

    // ---------------------------------------------------------------- internals

    /**
     * Download, verify and unzip into `<staging>/tree`.
     *
     * @return array{0: ReleaseManifest, 1: CoreManifest}
     */
    private function fetch(string $targetVersion, string $staging): array
    {
        $this->cleanStaging($staging);
        $zip = null;
        try {
            $this->privateDir($this->runnerDir(), 'CORE_STAGING');
            $this->privateDir($this->stagingRoot(), 'CORE_STAGING');
            $this->privateDir($staging, 'CORE_STAGING');
            $ticket = $this->source()->ticket($targetVersion);
            $signed = $this->verifier()->verify($ticket['signature_json']);
            // A hub countersign key signs partner module packages only; the core comes from a listed iSPA release key.
            if (in_array($signed->keyId, (array) config('core.module_packages.countersign_key_ids', []), true)
                || ! in_array($signed->keyId, (array) config('core.module_packages.core_package_key_ids', []), true)) {
                throw new CoreUpgradeException('CORE_PACKAGE_KEY', 'A core package must be signed with an iSPA release key.');
            }
            if ($signed->kind !== ReleaseManifest::KIND_CORE || $signed->name !== ReleaseManifest::CORE_NAME || $signed->version !== $targetVersion) {
                throw new CoreUpgradeException('CORE_PACKAGE_MISMATCH', 'The signed manifest is not the core package of version '.$targetVersion.'.');
            }
            $zip = $staging.'/package.zip';
            ($ticket['download'])($signed, $zip);
            ModuleInstaller::checkZip($zip, $signed);
            $this->assertPrivateDir($staging, 'CORE_STAGING');
            $new = (new CorePackageStaging())->extract($zip, $signed, $staging.'/tree');
            if ($new->version !== $targetVersion) {
                throw new CoreUpgradeException('CORE_PACKAGE_MISMATCH', 'core-manifest.json is for version '.$new->version.', not '.$targetVersion.'.');
            }
            @unlink($zip);
            return [$signed, $new];
        } catch (PackageVerificationException $e) {
            throw new CoreUpgradeException($e->errorCode, $e->getMessage());
        }
    }

    /**
     * Refuse (CORE_MODULE_NEEDS_UPDATE) when a module that now runs from its own update (`runtime-modules/<Name>`,
     * {@see RuntimePrecedence}) would lose its place after the upgrade because its `requires.core` range rejects the
     * staged Core version: boot would then fall back to the older package copy on a database the newer copy already
     * migrated. The same holds when the package ships a `runtime_modules.json` that no longer lists the module. A
     * package that ships a copy at least as new as the runtime one takes over instead (an upgrade).
     * Called after the download and before the backup tar and the state file, so nothing is changed.
     *
     * @throws CoreUpgradeException
     */
    private function assertModulesKeepTheirPlace(string $staging): void
    {
        $tree = $this->treeOf($staging);
        $core = $this->readJson($tree.'/cms/Core/module.json')['version'] ?? null;
        // The package's own runtime_modules.json replaces the site's list: a module it no longer lists falls back too.
        $shippedList = is_file($tree.'/'.RuntimePrecedence::LIST_FILE) ? RuntimePrecedence::readList($tree) : null;
        $rejected = [];
        foreach (RuntimePrecedence::listed($this->base) as $name) {
            $decision = RuntimePrecedence::decide($this->base, $name);
            if (! $decision['runtime']) {
                continue;
            }
            $shipped = $this->readJson($tree.'/cms/'.$name.'/module.json')['version'] ?? null;
            if (is_string($shipped) && ModuleVersionConstraint::compare($shipped, (string) $decision['runtime_version']) >= 0) {
                continue;
            }
            if ($shippedList !== null && ! in_array($name, $shippedList, true)) {
                $rejected[] = $name;

                continue;
            }
            $range = RuntimePrecedence::runtimeCoreRange($this->base, $name);
            if (! is_string($core) || $range === null || ! RuntimePrecedence::satisfies($core, $range)) {
                $rejected[] = $name;
            }
        }
        if ($rejected !== []) {
            sort($rejected, SORT_STRING);

            throw new CoreUpgradeException('CORE_MODULE_NEEDS_UPDATE', 'Update these modules first; after this upgrade they would no longer run from their own update: '.implode(', ', $rejected).'.', null, ['modules' => $rejected]);
        }
    }

    /**
     * A CI-deployed release keeps its own vendor/ and modules, so refuse (before the backup tar and the state file,
     * nothing changed yet) a package whose Core, Content or Widget is older than the release's own copy
     * (CORE_PACKAGE_OLDER: never downgrade a newer deploy), or whose composer.lock has packages the release's
     * composer.lock lacks (CORE_NEEDS_DEPLOY: only a deploy installs PHP packages).
     *
     * @throws CoreUpgradeException
     */
    private function assertDeployCompatible(string $staging): void
    {
        $tree = $this->treeOf($staging);
        $older = [];
        foreach (['Core', 'Content', 'Widget'] as $name) {
            $site = $this->readJson($this->base.'/cms/'.$name.'/module.json')['version'] ?? null;
            $shipped = $this->readJson($tree.'/cms/'.$name.'/module.json')['version'] ?? null;
            if (is_string($site) && is_string($shipped) && RuntimePrecedence::compareVersions($shipped, $site) < 0) {
                $older[] = $name.' '.$shipped.' < '.$site;
            }
        }
        if ($older !== []) {
            throw new CoreUpgradeException('CORE_PACKAGE_OLDER', 'This site already runs newer code than the core package ('.implode(', ', $older).'); it is updated by its deploy.', null, ['modules' => $older]);
        }

        $names = static function (array $lock): array {
            $out = [];
            foreach ((array) ($lock['packages'] ?? []) as $package) {
                if (is_array($package) && is_string($package['name'] ?? null)) {
                    $out[strtolower($package['name'])] = true;
                }
            }

            return $out;
        };
        $needed = $names($this->readJson($tree.'/composer.lock'));
        $missing = array_keys(array_diff_key($needed, $names($this->readJson($this->base.'/composer.lock'))));
        if ($missing !== []) {
            sort($missing, SORT_STRING);

            throw new CoreUpgradeException('CORE_NEEDS_DEPLOY', 'This core version needs PHP packages this site does not have yet ('.implode(', ', array_slice($missing, 0, 10)).'); sync the deploy repository and deploy instead.', null, ['modules' => array_slice($missing, 0, 10)]);
        }
    }

    /**
     * Names of the site's own packages the shipped lock lacks, sorted and unique: requirements of every
     * `cms/<Module>/composer.json` the new manifest does not list, plus root `require` entries the site added to
     * the shipped composer.json, minus what the shipped composer.lock already holds.
     *
     * @return list<string>
     */
    public function customPackages(CoreManifest $new, string $tree): array
    {
        $names = [];
        foreach (glob($this->base.'/cms/*/composer.json') ?: [] as $file) {
            if (! isset($new->checked['cms/'.basename(dirname($file)).'/composer.json'])) {
                $names = array_merge($names, array_keys($this->requires($file)));
            }
        }
        $names = array_merge($names, array_keys(array_diff_key($this->requires($this->base.'/composer.json'), $this->requires($tree.'/composer.json'))));

        $locked = [];
        foreach ((array) ($this->readJson($tree.'/composer.lock')['packages'] ?? []) as $package) {
            $locked[strtolower((string) ($package['name'] ?? ''))] = true;
        }
        $names = array_values(array_unique(array_filter(array_map('strtolower', $names), static fn (string $n): bool => ! isset($locked[$n]))));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Resolve the site's own packages against the new core in staging, before anything is swapped: copies the
     * site's own module composer.json files and root additions in, then runs a partial `composer update` that only
     * writes the merged composer.lock (no vendor changes). Never falls back to a full update.
     *
     * @throws CoreUpgradeException CORE_CUSTOM_DEPS_CONFLICT when composer cannot resolve them.
     */
    private function resolveCustomDependencies(CoreManifest $new, string $tree): void
    {
        $packages = $this->customPackages($new, $tree);
        if ($packages === []) {
            return; // Only the lock hash differs: nothing to resolve; install runs against the shipped lock.
        }
        foreach (glob($this->base.'/cms/*/composer.json') ?: [] as $file) {
            $relative = 'cms/'.basename(dirname($file)).'/composer.json';
            if (! isset($new->checked[$relative])) {
                @mkdir(dirname($tree.'/'.$relative), 0755, true);
                copy($file, $tree.'/'.$relative);
            }
        }
        $rootFile = $tree.'/composer.json';
        $original = (string) file_get_contents($rootFile);
        $merged = json_decode($original, true) ?: [];
        foreach (array_diff_key($this->requires($this->base.'/composer.json'), $this->requires($rootFile)) as $name => $constraint) {
            $merged['require'][$name] = $constraint;
        }
        file_put_contents($rootFile, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $exit = $this->runner()->run(array_merge(['composer', 'update'], $packages, ['--with-all-dependencies', '--no-dev', '--no-install', '--no-interaction']), $tree, [], false);
        // The shipped composer.json stays byte-identical to the manifest hash; only the lock keeps the result.
        file_put_contents($rootFile, $original);
        if ($exit !== 0) {
            throw new CoreUpgradeException('CORE_CUSTOM_DEPS_CONFLICT', 'Composer could not resolve this site\'s own packages against the new core (see the Composer output above). Packages: '.implode(', ', $packages).'.');
        }
    }

    /**
     * @return array<string, string> package => constraint (php and extensions left out)
     */
    private function requires(string $composerJson): array
    {
        $require = $this->readJson($composerJson)['require'] ?? [];

        return is_array($require)
            ? array_filter($require, static fn ($c, $n): bool => is_string($n) && is_string($c) && preg_match('#^(php|ext-|lib-|composer)#', $n) !== 1, ARRAY_FILTER_USE_BOTH)
            : [];
    }

    private function stagingPath(string $version): string
    {
        return $this->stagingRoot().'/'.preg_replace('/[^0-9A-Za-z.+-]/', '_', $version);
    }

    private function runnerDir(): string
    {
        return $this->base.'/'.self::RUNNER_DIR;
    }

    private function stagingRoot(): string
    {
        return $this->runnerDir().'/upgrade-staging';
    }

    private function backupsRoot(): string
    {
        return $this->runnerDir().'/upgrade-backups';
    }

    /**
     * Refuse any engine work when the base or the runner folder could be changed by another user
     * ({@see CoreRunnerTick::codeDirShared()}).
     *
     * @throws CoreUpgradeException CORE_CODE_DIR_SHARED
     */
    private function assertCodeDir(): void
    {
        if (CoreRunnerTick::codeDirShared($this->base, CoreRunnerTick::currentUid($this->base) ?? -1)) {
            throw new CoreUpgradeException('CORE_CODE_DIR_SHARED', 'The code folder or '.self::RUNNER_DIR.' is a link, belongs to another user or can be written by group or others; fix the folder permissions first.');
        }
    }

    private function treeOf(string $staging): string
    {
        return $staging.'/tree';
    }

    /**
     * Remove a run's staging folder: only a direct child of `.ispa-runner/upgrade-staging/` (one plain segment, never
     * `.` or `..`) whose parent resolves to the real staging root; nothing when the runner folder or the staging root
     * is a link. A staged link itself is unlinked, never followed.
     */
    private function cleanStaging(string $staging): void
    {
        $root = $this->stagingRoot();
        if ($staging === '' || ! str_starts_with($staging, $root.'/') || (! file_exists($staging) && ! is_link($staging))) {
            return;
        }
        if (is_link($this->runnerDir()) || is_link($root)) {
            return;
        }
        $name = substr($staging, strlen($root) + 1);
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, "\0")) {
            return;
        }
        $realRoot = realpath($root);
        if ($realRoot === false || realpath(dirname($staging)) !== $realRoot) {
            return;
        }
        SafeExtractor::removeTree($staging, $root);
    }

    /**
     * Create a folder only this process can enter (the leaf only; its parent must exist), or check the existing one.
     *
     * A parent may carry default ACLs that grant the web user access to new folders; chmod 0700 right after mkdir
     * also sets the ACL mask, so those inherited entries grant nothing and the web user cannot traverse in.
     *
     * @throws CoreUpgradeException $code when the folder cannot be created or is not private.
     */
    private function privateDir(string $dir, string $code): void
    {
        if (! file_exists($dir) && ! is_link($dir)) {
            if (! @mkdir($dir, 0700)) {
                throw new CoreUpgradeException($code, 'Cannot create a private folder for the upgrade.');
            }
            @chmod($dir, 0700);
        }
        $this->assertPrivateDir($dir, $code);
    }

    /**
     * The folder must be a real folder (not a link, nor under a linked parent), owned by this process and closed to
     * group and others (`mode & 0077 === 0`), so the web user cannot change what the runner reads from it.
     *
     * @throws CoreUpgradeException $code
     */
    private function assertPrivateDir(string $dir, string $code): void
    {
        clearstatcache(true, $dir);
        $stat = @lstat($dir);
        if (is_link(dirname($dir)) || ! CoreRunnerTick::ownedDirectory($dir, CoreRunnerTick::currentUid($this->base))
            || ! is_array($stat) || ($stat['mode'] & 0077) !== 0) {
            throw new CoreUpgradeException($code, 'An upgrade folder is a link, belongs to another user or is open to other users.');
        }
    }

    private function assertPreflight(\Cms\Core\Services\Modules\Install\PreflightResult $result): void
    {
        if (! $result->canInstall()) {
            $first = $result->blocking[0];
            throw new CoreUpgradeException($first['code'], $first['message']);
        }
    }

    private function customDependencies(CoreManifest $installed): bool
    {
        return (new CoreIntegrity())->customDependencies($installed, $this->base);
    }

    /**
     * The `current` symlink that points at this base, or null for a flat install.
     *
     * @return array{link: string, previous: string}|null
     */
    private function releaseLink(): ?array
    {
        return CoreRunnerTick::releaseLink($this->base);
    }

    /**
     * @return list<string>
     */
    private function sharedNames(): array
    {
        $shared = [];
        foreach (['storage', 'runtime', '.env'] as $name) {
            if (file_exists($this->base.'/'.$name) && ! is_link($this->base.'/'.$name)) {
                $shared[] = $name;
            }
        }

        return $shared;
    }

    /**
     * Copy the new installed manifest into place (the last file written, so a failed run keeps the old one).
     */
    private function placeManifest(string $staging, string $root): void
    {
        $source = $this->treeOf($staging).'/'.CoreManifest::FILE;
        $temp = $root.'/'.CoreManifest::FILE.'.core-new';
        copy($source, $temp);
        rename($temp, $root.'/'.CoreManifest::FILE);
    }

    /**
     * The manifest after a deploy-origin upgrade (the last file written): the new version, still origin `deploy`,
     * `checked` = the new package's hashes of the paths the plan may touch here (proven before, or added now), the
     * release's own composer.lock hash, no replaced folders, and `upgraded_from` so the next deploy can refuse an
     * older Core.
     *
     * @param array{write: list<string>, remove: list<string>, replace: list<string>, skipped: list<string>} $plan
     */
    private function placeDeployManifest(CoreManifest $installed, CoreManifest $new, array $plan): void
    {
        $written = array_flip($plan['write']);
        $checked = [];
        foreach ($new->checked as $path => $hash) {
            $path = (string) $path;
            if (! CoreFileSwapper::isProtected($path) && ! in_array($path, CoreFileSwapper::DEPLOY_OWNED_FILES, true)
                && (isset($installed->checked[$path]) || isset($written[$path]))) {
                $checked[$path] = $hash;
            }
        }
        $manifest = new CoreManifest($new->version, $new->phpMin, $checked, [], $installed->composerLockSha256, CoreManifest::ORIGIN_DEPLOY, $installed->version);
        $json = json_encode($manifest->toArray(date('c')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $this->writeAtomic($this->base.'/'.CoreManifest::FILE, $json, 0644);
    }

    private function switchLink(string $link, string $target): void
    {
        $temp = $link.'.core-'.getmypid();
        @unlink($temp);
        symlink($target, $temp);
        rename($temp, $link);
    }

    /**
     * Run migrate, permissions sync, cache clear, the admin config and, when this site built locally, the build.
     *
     * @return list<string> Warnings (non-fatal).
     */
    private function finish(string $root, bool $customDeps, bool $deploy = false): array
    {
        $warnings = [];
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $artisan = fn (array $args): int => $this->runner()->run(array_merge([$php, 'artisan'], $args), $root, [], true);

        if ($customDeps && $this->runner()->run(['composer', 'install', '--no-dev', '--no-interaction'], $root, [], true) !== 0) {
            throw new CoreUpgradeException('CORE_COMPOSER_FAILED', '`composer install --no-dev` failed. Is Composer installed and the network reachable?');
        }
        if ($artisan(['migrate', '--force']) !== 0) {
            throw new CoreUpgradeException('CORE_MIGRATE_FAILED', '`php artisan migrate --force` failed.');
        }
        if ($artisan(['cms:modules-sync-permissions']) !== 0) {
            throw new CoreUpgradeException('CORE_PERMISSIONS_FAILED', 'Syncing module permissions failed.');
        }
        $artisan(['optimize:clear']);
        if ($artisan(['cms:admin-config']) !== 0) {
            $warnings[] = 'Could not write public/iPanel/config.js; run `./ispa build` or `php artisan cms:admin-config`.';
        }
        if ($deploy) {
            // What the deploy does after activation: cached config and routes, translations imported from the new
            // sidecars, and the admin rebuilt from the new Core admin source (deploy script; best effort).
            if ($artisan(['config:cache']) !== 0) {
                $warnings[] = 'Could not cache the configuration; run `php artisan config:cache`.';
            }
            if ($artisan(['route:cache']) !== 0) {
                $artisan(['route:clear']);
                $warnings[] = 'Could not cache the routes; the site runs with uncached routes.';
            }
            if ($artisan(['cms:translation-sync', '--all']) !== 0) {
                $warnings[] = 'Could not import the new translations; run `php artisan cms:translation-sync --all`.';
            }
            if (is_file($root.'/.github/deployment/admin-rebuild.sh')) {
                try {
                    $built = (new \Cms\Core\Services\Modules\Install\AdminRebuilder(null, $root))->rebuild();
                } catch (Throwable $e) {
                    $built = ['ok' => false, 'message' => $e->getMessage()];
                }
                if (($built['ok'] ?? false) !== true) {
                    $warnings[] = 'The admin was not rebuilt; rebuild it from Quản lý Module or with the next deploy.';
                }
            }
        }

        $marker = $this->readJson($this->base.'/storage/app/ispa/custom-build.json');
        $flags = array_values(array_filter([
            ($marker['site'] ?? false) === true ? '--site' : null,
            ($marker['admin'] ?? false) === true ? '--admin' : null,
        ]));
        if ($flags !== []) {
            if ($this->runner()->run(['node', '--version'], null, [], true) !== 0) {
                $warnings[] = 'This site was built locally and Node.js was not found: run `./ispa build` to rebuild the assets.';
            } elseif ($artisan(array_merge(['cms:build'], $flags)) !== 0) {
                $warnings[] = 'The local rebuild failed; the packaged build is in place. Run `./ispa build` to see why.';
            }
        }

        return $warnings;
    }

    /**
     * Write the trusted state into the runner-only folder (0600), then the web side's marker (0664, no paths).
     *
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        foreach ($this->stateBases($state, true) as $base) {
            $this->privateDir($base.'/'.self::RUNNER_DIR, 'CORE_STAGING');
            $this->writeAtomic($base.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE, $json, 0600);
        }

        $marker = ['format' => 1, 'from' => (string) $state['from'], 'to' => (string) $state['to'], 'started_at' => (string) $state['started_at']];
        $path = $this->base.'/'.CoreUpgradePreflight::STATE_FILE;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $this->writeAtomic($path, json_encode($marker, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", 0664);
    }

    /**
     * Write a file through a random temp name opened with `x` (never follows a link someone planted), then rename.
     *
     * @throws CoreUpgradeException CORE_STAGING
     */
    private function writeAtomic(string $path, string $contents, int $mode): void
    {
        $temp = $path.'.tmp-'.bin2hex(random_bytes(8));
        $handle = @fopen($temp, 'x');
        if ($handle === false) {
            throw new CoreUpgradeException('CORE_STAGING', 'Cannot write the upgrade state file.');
        }
        $written = fwrite($handle, $contents);
        fclose($handle);
        @chmod($temp, $mode);
        if ($written !== strlen($contents) || ! rename($temp, $path)) {
            @unlink($temp);
            throw new CoreUpgradeException('CORE_STAGING', 'Cannot write the upgrade state file.');
        }
    }

    /**
     * The private state (never the web side's marker), trusted only as a regular file owned by this process
     * ({@see CoreRunnerTick::ownedRegularFile()}) in the private runner folder.
     *
     * @throws CoreUpgradeException CORE_STATE_INVALID for a link or a file of another user.
     *
     * @return array<string, mixed>|null
     */
    private function readState(): ?array
    {
        $path = $this->base.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE;
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        if (! CoreRunnerTick::ownedRegularFile($path, CoreRunnerTick::currentUid($this->base))) {
            throw new CoreUpgradeException('CORE_STATE_INVALID', 'The upgrade state file ('.CoreUpgradePreflight::PRIVATE_STATE_FILE.') is a link or belongs to another user, so it is not trusted. Check it, remove it, then try again.');
        }
        $this->assertPrivateDir($this->runnerDir(), 'CORE_STATE_INVALID');

        return $this->readJson($path) ?: ['from' => '?', 'to' => '?'];
    }

    /**
     * Right before the swap the staging folder must still be private to this process ({@see self::assertPrivateDir()})
     * and its tree a real folder owned by it (each written file is also hashed again while it is copied).
     */
    private function assertOwnStaging(string $staging): void
    {
        $this->assertPrivateDir($staging, 'CORE_STAGING');
        if (! CoreRunnerTick::ownedDirectory($this->treeOf($staging), CoreRunnerTick::currentUid($this->base))) {
            throw new CoreUpgradeException('CORE_STAGING', 'The staging folder is a link or belongs to another user.');
        }
    }

    /**
     * Remove the marker first, then every copy of the private state: a kill in between leaves a private state, which
     * is still restorable, never a lone marker.
     *
     * @param array<string, mixed>|null $state The validated state (release layout: both releases hold a copy).
     */
    private function clearState(?array $state = null): void
    {
        $bases = $this->stateBases($state, false);
        foreach ($bases as $base) {
            @unlink($base.'/'.CoreUpgradePreflight::STATE_FILE);
        }
        foreach ($bases as $base) {
            @unlink($base.'/'.CoreUpgradePreflight::PRIVATE_STATE_FILE);
        }
    }

    /**
     * The release folders that hold a copy of the private state: the running base, and in release layout both
     * releases (the new one only once it exists, when writing). The paths of a read state were validated by
     * {@see self::validReleaseState()} first.
     *
     * @param array<string, mixed>|null $state
     *
     * @return list<string>
     */
    private function stateBases(?array $state, bool $existingOnly): array
    {
        $bases = [$this->base];
        if ($state !== null && ($state['layout'] ?? 'flat') === 'release') {
            foreach ([$state['previous_release'] ?? null, $state['new_release'] ?? null] as $release) {
                if (is_string($release) && $release !== '' && (! $existingOnly || is_dir($release))) {
                    $bases[] = rtrim($release, '/');
                }
            }
        }

        return array_values(array_unique($bases));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function revertState(array $state): void
    {
        if (($state['layout'] ?? 'flat') === 'release') {
            [$previous, $new, $link] = $this->validReleaseState($state);
            if ($link !== '' && is_dir($previous)) {
                $this->switchLink($link, $previous);
            }
            if ($new !== '' && file_exists($new) && realpath($new) !== realpath($previous)) {
                SafeExtractor::removeTree($new, dirname($new));
            }
        } else {
            [$tar, $aside, $added] = $this->validFlatState($state);
            (new CoreFileSwapper($this->base))->revert($tar, $aside, $added);
        }
        $this->clearState($state);
    }

    /**
     * Release-layout keys of the private state: both releases must be siblings of the base (same parent, one plain
     * name) and the link the `current` link where {@see CoreRunnerTick::releaseLink()} looks, pointing at one of them.
     *
     * @param array<string, mixed> $state
     *
     * @throws CoreUpgradeException CORE_STATE_INVALID
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function validReleaseState(array $state): array
    {
        $invalid = new CoreUpgradeException('CORE_STATE_INVALID', 'The upgrade state file is not valid (release folders), so nothing was put back.');
        $parent = dirname($this->base);
        $sibling = static function (mixed $path) use ($parent): bool {
            if (! is_string($path) || $path === '' || str_contains($path, "\0") || dirname($path) !== $parent) {
                return false;
            }
            $name = basename($path);

            return $name !== '' && $name !== '.' && $name !== '..';
        };
        $previous = $state['previous_release'] ?? null;
        $new = $state['new_release'] ?? null;
        $link = $state['link'] ?? null;
        if (! $sibling($previous) || ! $sibling($new)) {
            throw $invalid;
        }
        // The `current` link beside or above the base (where releaseLink() looks), pointing at one of the two releases:
        // after the switch it points at the new release, so releaseLink() of the old base no longer finds it.
        $targets = array_filter([realpath((string) $previous), realpath((string) $new)]);
        $places = [dirname($this->base).'/current', dirname($this->base, 2).'/current'];
        if (! is_string($link) || ! in_array($link, $places, true) || ! is_link($link) || ! in_array(realpath($link), $targets, true)) {
            throw $invalid;
        }

        return [(string) $previous, (string) $new, (string) $link];
    }

    /**
     * Check every path a flat revert would touch before anything is touched: `added[]` and `aside_planned[]` must be
     * safe relative paths ({@see CoreFileSwapper::isSafeRelative()}), and the tar a regular file owned by this
     * process at `.ispa-runner/upgrade-backups/<run>/files.tar` (`<run>` one checked segment; the path is built here,
     * never read) whose entries are all safe. Every folder from the base to the tar is private to this process (the
     * base is checked by {@see self::assertCodeDir()}), and the same literal path is extracted.
     *
     * @param array<string, mixed> $state
     *
     * @throws CoreUpgradeException CORE_STATE_INVALID
     *
     * @return array{0: ?string, 1: list<array{path: string, old: string}>, 2: list<string>}
     */
    private function validFlatState(array $state): array
    {
        $invalid = static fn (string $why): CoreUpgradeException => new CoreUpgradeException('CORE_STATE_INVALID', 'The upgrade state file is not valid ('.$why.'), so nothing was put back.');
        $added = $state['added'] ?? [];
        $aside = $state['aside_planned'] ?? [];
        if (! is_array($added) || ! is_array($aside)) {
            throw $invalid('lists');
        }
        foreach ($added as $path) {
            if (! CoreFileSwapper::isSafeRelative($path)) {
                throw $invalid('added path');
            }
        }
        foreach ($aside as $item) {
            if (! is_array($item) || ! CoreFileSwapper::isSafeRelative($item['path'] ?? null) || ! CoreFileSwapper::isSafeRelative($item['old'] ?? null)) {
                throw $invalid('replaced folder');
            }
        }

        // The tar path is never taken from a file: always the literal backups path of the run named in the state.
        $run = $state['run'] ?? null;
        $tar = null;
        if ($run !== null) {
            if (! is_string($run) || preg_match('/^[0-9A-Za-z.+-]+$/', $run) !== 1 || $run === '.' || $run === '..') {
                throw $invalid('backup run');
            }
            $candidate = $this->backupsRoot().'/'.$run.'/files.tar';
            if (file_exists($candidate) || is_link($candidate)) {
                $this->assertPrivateDir($this->runnerDir(), 'CORE_STATE_INVALID');
                $this->assertPrivateDir($this->backupsRoot(), 'CORE_STATE_INVALID');
                $this->assertPrivateDir($this->backupsRoot().'/'.$run, 'CORE_STATE_INVALID');
                if (! CoreRunnerTick::ownedRegularFile($candidate, CoreRunnerTick::currentUid($this->base)) || ! CoreFileSwapper::tarIsSafe($candidate)) {
                    throw $invalid('backup');
                }
                $tar = $candidate;
            }
        }

        return [$tar, array_values(array_map(static fn (array $i): array => ['path' => (string) $i['path'], 'old' => (string) $i['old']], $aside)), array_values(array_map('strval', $added))];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }

    private function wrap(Throwable $e, ?string $backupUuid): CoreUpgradeException
    {
        return new CoreUpgradeException('CORE_UPGRADE_FAILED', 'The upgrade failed: '.class_basename($e).': '.mb_substr($e->getMessage(), 0, 400), $backupUuid);
    }

    // ---------------------------------------------------------------- recording

    private function progress(?BackgroundTask $task, int $percent, string $message): void
    {
        if ($task !== null) {
            $this->recorder()->progress($task, $percent, $message);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function succeed(?BackgroundTask $task, array $result): void
    {
        if ($task !== null) {
            $this->recorder()->succeed($task, $result);
        }
    }

    private function fail(?BackgroundTask $task, CoreUpgradeException $e): void
    {
        if ($task !== null) {
            // The task bar shows the message, so it is a translation key; the CLI prints the exception itself.
            $params = ['code' => $e->errorCode];
            $modules = $e->details['modules'] ?? null;
            if (is_array($modules) && $modules !== []) {
                // Message params hold scalars only, so the list is stored as one line.
                $params['modules'] = implode(', ', array_map('strval', $modules));
            }
            $this->recorder()->fail($task, CoreUpgradeRequests::failureMessage($e->errorCode), $params);
        }
    }

    /**
     * @param array<string, mixed> $changed
     */
    private function audit(string $action, array $changed, ?int $userId): void
    {
        try {
            event(new AuditLogEvent($action, $changed, self::class, 0, null, null, (int) ($userId ?? 0)));
        } catch (Throwable) {
            // Best effort: an audit failure never changes the upgrade result.
        }
    }

    private function preflightService(): CoreUpgradePreflight
    {
        return $this->preflightService ?? new CoreUpgradePreflight($this->base);
    }

    private function source(): CorePackageSource
    {
        return $this->source ?? app(CorePackageSource::class);
    }

    private function verifier(): PackageVerifier
    {
        return $this->verifier ?? new PackageVerifier();
    }

    private function backupGate(): BackupGate
    {
        return $this->backupGate ?? app(BackupGate::class);
    }

    /**
     * Refuse before the backup when this process cannot write the backup folders (the staging folder and the
     * built-in local destination): with the code owner as runner and the web user owning storage/, that happens when
     * the ACLs from INSTALL.md are missing. The message names the fix; nothing has changed yet.
     *
     * @throws CoreUpgradeException CORE_BACKUP_FAILED
     */
    private function assertBackupWritable(): void
    {
        $blocked = [];
        foreach ([(string) config('core.backup.staging_path'), (string) config('core.backup.local_root')] as $dir) {
            if ($dir === '') {
                continue;
            }
            $probe = $dir;
            while (! file_exists($probe) && dirname($probe) !== $probe) {
                $probe = dirname($probe);
            }
            if (! is_dir($probe) || ! is_writable($probe)) {
                $blocked[] = $dir;
            }
        }
        if ($blocked !== []) {
            $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'the code owner') : 'the code owner';
            throw new CoreUpgradeException('CORE_BACKUP_FAILED', 'The upgrade cannot write the backup folders ('.implode(', ', $blocked).') as '.$user.', so nothing was changed. '
                .'Give the web server user and the code owner access to storage/ (INSTALL.md, upgrade runner: setfacl -R -m u:'.$user.':rwX storage; setfacl -R -d -m u:'.$user.':rwX storage), '
                .'or start the upgrade from the admin, where the site takes the backup itself.');
        }
    }

    private function runner(): ProcessRunner
    {
        return $this->runner ?? app(ProcessRunner::class);
    }

    private function health(): CoreHealthCheck
    {
        return $this->health ?? app(CoreHealthCheck::class);
    }

    private function recorder(): BackgroundTaskRecorder
    {
        return $this->recorder ?? app(BackgroundTaskRecorder::class);
    }
}
