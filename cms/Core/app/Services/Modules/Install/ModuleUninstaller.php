<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Jobs\Modules\RefreshRuntimeCachesJob;
use App\Models\User;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\ModuleDisableGuardRegistry;
use App\Support\Modules\ModuleStatusService;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Modules\Packages\RuntimeAssets;
use Cms\Core\Services\Modules\Packages\RuntimePlacement;
use Cms\Core\Services\Modules\Packages\SafeExtractor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Nwidart\Modules\Laravel\Module as LaravelModule;
use RuntimeException;
use Throwable;

/**
 * Removes one runtime module from the site. Release modules (`cms/`) and Core are never touched.
 *
 * Rules checked before anything is queued and again when the job runs ({@see self::check()}): the module is a runtime
 * module (a folder or link inside the runtime modules folder, with a link that stays inside it), no other ENABLED
 * module requires it (`MODULE_HAS_DEPENDENTS`), and "also drop its tables" is only allowed when the module's
 * `module.json` declares `uninstall.tables` (the file is covered by the signed package hashes). A declared table is
 * still only trusted when the module's own migrations create it (`Schema::create('<name>'`) and no release module
 * (`cms/`) or `database/migrations` creates it too; otherwise the request is refused (`MODULE_TABLE_NOT_OWNED`).
 * A module may also declare `uninstall.refuse_while_rows`: while any listed table exists and holds a row,
 * every uninstall, with or without dropping data, is refused (`MODULE_HAS_DATA`); a malformed declaration refuses too
 * (fail closed). Ecommerce lists its order and payment tables, so money records are never orphaned by an online
 * uninstall. The row check runs before queueing, again when the job starts, and once more after the module is
 * disabled (an order placed in between fails the job and enables the module again).
 *
 * Steps, each recorded on the install row (kind `uninstall`) and its background task:
 *  1. backing_up — BackupGate::ensure('pre_install'); a failure stops before anything changes.
 *  2. removing   — disable through ModuleStatusService (audit `module.disabled`), when the module is enabled.
 *  3. migrating  — only with `drop_data`: `module:migrate-reset <Name> --force` in a fresh process (the module's `down`
 *                  migrations). The keep-data path never touches tables or migration rows.
 *  4. removing   — delete the module link (or plain folder), every `.versions/<Name>-<version>` entry and every
 *                  `.previous/<Name>-<version>` folder of this module only; then its published front-end assets
 * (RuntimeAssets `<Name>/`, every version; a failure there is only logged, because the
 *                  code is already gone and static files cannot run); remove its status row.
 *  5. caches (RefreshRuntimeCachesJob inline), admin rebuild when the module had admin code, audit `module.uninstalled`,
 *     status `succeeded`.
 * A failure before step 4 puts the previous enabled state back unless tables were already rolled back; the row fails
 * naming the backup, audit `module.uninstall_failed`.
 *
 * The row stores the choice in `confirmed_irreversible` (true = tables dropped) and uses source `local`.
 */
class ModuleUninstaller
{
    public const ERROR_CORE = 'MODULE_CORE_PROTECTED';
    public const ERROR_NOT_RUNTIME = 'MODULE_NOT_RUNTIME';
    public const ERROR_NOT_INSTALLED = 'MODULE_NOT_INSTALLED';
    public const ERROR_HAS_DEPENDENTS = 'MODULE_HAS_DEPENDENTS';
    public const ERROR_HAS_DATA = 'MODULE_HAS_DATA';
    public const ERROR_NO_TABLES = 'MODULE_UNINSTALL_NO_TABLES';
    public const ERROR_TABLE_NOT_OWNED = 'MODULE_TABLE_NOT_OWNED';
    public const ERROR_DISABLE = 'MODULE_UNINSTALL_DISABLE_FAILED';
    public const ERROR_MIGRATE_DOWN = 'MODULE_UNINSTALL_MIGRATE_FAILED';
    public const ERROR_REMOVE = 'MODULE_UNINSTALL_REMOVE_FAILED';
    public const ERROR_UNEXPECTED = 'MODULE_UNINSTALL_FAILED';

    /** Seconds allowed for the `down` migrations. */
    private const MIGRATE_TIMEOUT = 900;

    private readonly ModuleCatalog $catalog;

    private readonly ModuleStatusService $statuses;

    private readonly ArtisanSubprocess $artisan;

    private readonly AdminRebuilder $adminRebuilder;

    private readonly ?DatabaseActivator $activator;

    /**
     * Every optional collaborator exists so tests can fake the environment.
     *
     * @param BackupGate               $backupGate  safety backup before anything changes
     * @param BackgroundTaskRecorder   $recorder    background task progress
     * @param ModuleCatalog|null       $catalog     installed state and folder names
     * @param ModuleStatusService|null $statuses    module status write path (disable)
     * @param ArtisanSubprocess|null   $artisan     fresh-process artisan runner
     * @param AdminRebuilder|null      $adminRebuilder admin build runner
     * @param DatabaseActivator|null   $activator   module status store
     * @param string|null              $releaseModulesPath release modules folder
     */
    public function __construct(
        private readonly BackupGate $backupGate,
        private readonly BackgroundTaskRecorder $recorder,
        ?ModuleCatalog $catalog = null,
        ?ModuleStatusService $statuses = null,
        ?ArtisanSubprocess $artisan = null,
        ?AdminRebuilder $adminRebuilder = null,
        ?DatabaseActivator $activator = null,
        private readonly ?string $releaseModulesPath = null,
    ) {
        $this->catalog = $catalog ?? new ModuleCatalog(null, $releaseModulesPath);
        $this->statuses = $statuses ?? new ModuleStatusService();
        $this->artisan = $artisan ?? new ArtisanSubprocess();
        $this->adminRebuilder = $adminRebuilder ?? new AdminRebuilder($this->artisan);
        $this->activator = $activator;
    }

    /**
     * Facts about an installed runtime module, or a refusal.
     *
     * @return array{name: string, version: ?string, enabled: bool, dir: string, tables: list<string>, dependents: list<string>}
     *
     * @throws PackageVerificationException MODULE_CORE_PROTECTED, MODULE_NOT_RUNTIME or MODULE_NOT_INSTALLED
     */
    public function inspect(string $name): array
    {
        if (strcasecmp($name, 'Core') === 0) {
            throw new PackageVerificationException(self::ERROR_CORE, 'the Core module can not be uninstalled');
        }
        if (preg_match(ReleaseManifest::NAME_PATTERN, $name) !== 1) {
            throw new PackageVerificationException(self::ERROR_NOT_INSTALLED, 'module name is not valid');
        }
        if (in_array($name, $this->catalog->releaseNames(), true)) {
            throw new PackageVerificationException(self::ERROR_NOT_RUNTIME, 'release modules can not be uninstalled');
        }
        $runtimeRoot = RuntimeModules::path();
        if ($runtimeRoot === null || ! in_array($name, $this->catalog->runtimeNames(), true)) {
            throw new PackageVerificationException(self::ERROR_NOT_INSTALLED, 'module is not installed in the runtime folder');
        }

        $dir = $runtimeRoot . DIRECTORY_SEPARATOR . $name;
        if (is_link($dir)) {
            $real = realpath($dir);
            $realRoot = realpath($runtimeRoot);
            if ($real === false || $realRoot === false || ! str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
                throw new PackageVerificationException(self::ERROR_NOT_RUNTIME, 'module link points outside the runtime folder');
            }
        }

        $state = $this->catalog->installedState($name);

        return [
            'name' => $name,
            'version' => $state['installed_version'],
            'enabled' => $this->activator()->hasStatus($name, true),
            'dir' => $dir,
            'tables' => $this->declaredTables($dir),
            'dependents' => $this->enabledDependents($name),
        ];
    }

    /**
     * Full pre-queue check of an uninstall request.
     *
     * @return array{name: string, version: ?string, enabled: bool, dir: string, tables: list<string>, dependents: list<string>}
     *
     * @throws PackageVerificationException a refusal code of {@see self::inspect()}, MODULE_HAS_DEPENDENTS, MODULE_UNINSTALL_DISABLE_FAILED (a disable guard refuses), MODULE_HAS_DATA, MODULE_UNINSTALL_NO_TABLES or MODULE_TABLE_NOT_OWNED
     */
    public function check(string $name, bool $dropData): array
    {
        $facts = $this->inspect($name);
        if ($facts['dependents'] !== []) {
            throw new PackageVerificationException(self::ERROR_HAS_DEPENDENTS, 'enabled modules require this module: ' . implode(', ', $facts['dependents']));
        }
        // An uninstall switches the module off first, so a module that refuses to be switched off (a registered disable
        // guard, e.g. Demo while the site is a demo) refuses the uninstall too, before anything is queued or backed up.
        if ($facts['enabled']) {
            $blocking = app(ModuleDisableGuardRegistry::class)->evaluate($name);
            if ($blocking !== []) {
                throw new PackageVerificationException(self::ERROR_DISABLE, 'the module refuses to be switched off: ' . implode(', ', $blocking));
            }
        }
        $this->refuseWhileRows($facts['dir']);
        if ($dropData && $facts['tables'] === []) {
            throw new PackageVerificationException(self::ERROR_NO_TABLES, 'the module does not declare data tables to drop');
        }
        if ($dropData) {
            $foreign = $this->unownedTables($facts['dir'], $facts['tables']);
            if ($foreign !== []) {
                throw new PackageVerificationException(self::ERROR_TABLE_NOT_OWNED, 'declared tables not created by this module: ' . implode(', ', $foreign));
            }
        }

        return $facts;
    }

    /**
     * Run the uninstall of a queued row. Never throws: failures are recorded on the row and task.
     */
    public function run(ModuleInstall $install): void
    {
        $task = $this->task($install);
        $install->forceFill(['started_at' => $install->started_at ?? Carbon::now(), 'error_code' => null])->save();

        $name = $install->name;
        $dropData = (bool) $install->confirmed_irreversible;
        $user = $install->requested_by !== null ? User::query()->find($install->requested_by) : null;
        $wasEnabled = false;
        $disabled = false;
        $rolledBack = false;
        $facts = null;
        $code = self::ERROR_UNEXPECTED;

        try {
            $this->step($install, $task, ModuleInstall::STATUS_BACKING_UP, 5, 'Taking a safety backup of the database.');
            $backup = $this->backupGate->ensure(Backup::REASON_PRE_INSTALL, $install->requested_by);
            $install->forceFill(['backup_uuid' => $backup->uuid])->save();

            $facts = $this->check($name, $dropData);
            $wasEnabled = $facts['enabled'];
            $hadAdminCode = is_dir($facts['dir'] . DIRECTORY_SEPARATOR . 'admin');

            $this->step($install, $task, ModuleInstall::STATUS_REMOVING, 30, 'Disabling the module.');
            if ($wasEnabled) {
                $code = self::ERROR_DISABLE;
                $this->statuses->change($name, false, $user, 'uninstall', false);
                $disabled = true;
            }
            // Rows written between the check above and the disable still stop the uninstall.
            $this->refuseWhileRows($facts['dir']);

            if ($dropData) {
                $code = self::ERROR_MIGRATE_DOWN;
                $this->step($install, $task, ModuleInstall::STATUS_MIGRATING, 50, 'Dropping the module data (down migrations).');
                $rolledBack = true;
                $this->artisanStep(['module:migrate-reset', $name, '--force'], self::MIGRATE_TIMEOUT);
            }

            $code = self::ERROR_REMOVE;
            $this->step($install, $task, ModuleInstall::STATUS_REMOVING, 70, 'Removing the module files.');
            $this->removeFiles($name);
            $this->activator()->delete(new LaravelModule(app(), $name, $facts['dir']));
            try {
                ModuleFacade::resetModules();
            } catch (Throwable) {
                // Best effort: the next request rescans the folders.
            }
        } catch (Throwable $e) {
            $this->fail($install, $task, $user, $code, $e, $facts, $disabled && ! $rolledBack, $name);

            return;
        }

        $this->recorder->progress($task, 85, 'text.background_tasks.step.module_install.refreshing');
        try {
            (new RefreshRuntimeCachesJob())->handle($this->artisan, app(Kernel::class));
        } catch (Throwable $e) {
            Log::warning('Module uninstall: cache refresh did not finish.', ['install' => $install->uuid, 'exception' => $e::class]);
        }

        $warnings = is_array($install->warnings) ? $install->warnings : [];
        if ($hadAdminCode) {
            $this->step($install, $task, ModuleInstall::STATUS_BUILDING_ADMIN, 92, 'Rebuilding the admin.');
            try {
                $built = $this->adminRebuilder->rebuild();
            } catch (Throwable $e) {
                Log::warning('Module uninstall: admin rebuild threw.', ['install' => $install->uuid, 'exception' => $e::class]);
                $built = ['ok' => false, 'errorCode' => AdminRebuilder::ERROR_FAILED];
            }
            if (! $built['ok']) {
                $notDeployed = ($built['errorCode'] ?? null) === AdminRebuilder::ERROR_NOT_DEPLOYED;
                $warnings[] = [
                    'code' => $notDeployed ? AdminRebuilder::ERROR_NOT_DEPLOYED : AdminRebuilder::ERROR_FAILED,
                    'message' => 'The module is removed, but the admin could not be rebuilt. A Webmaster can retry the admin rebuild.',
                    'details' => [],
                ];
            }
        }

        event(new AuditLogEvent('module.uninstalled', [
            'name' => $name,
            'version' => $facts['version'],
            'drop_data' => $dropData,
            'tables' => $dropData ? $facts['tables'] : [],
            'was_enabled' => $wasEnabled,
            'backup_uuid' => $install->backup_uuid,
        ], ModuleInstall::class, (int) $install->id, null, null, (int) ($install->requested_by ?? 0)));

        $install->forceFill([
            'status' => ModuleInstall::STATUS_SUCCEEDED,
            'step_message' => $dropData ? 'Module removed and its data dropped.' : 'Module removed; its data was kept.',
            'warnings' => $warnings,
            'error_code' => null,
            'finished_at' => Carbon::now(),
        ])->save();

        if (in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            $this->recorder->succeed($task, [
                'install_uuid' => $install->uuid,
                'name' => $name,
                'version' => $facts['version'],
                'drop_data' => $dropData,
                'backup_uuid' => $install->backup_uuid,
                'warnings' => array_values(array_filter(array_map(
                    static fn (mixed $w): ?string => is_array($w) && is_string($w['code'] ?? null) ? $w['code'] : null,
                    $warnings,
                ))),
            ]);
        }

        try {
            Artisan::call('cms:platform-report');
        } catch (Throwable $e) {
            Log::warning('Module uninstall: platform status report failed.', ['install' => $install->uuid, 'exception' => $e::class]);
        }
    }

    /**
     * Remove the module link (or plain folder), its `.versions` entries, its `.previous` folders and then its published
     * front-end asset folder; nothing else. Links are unlinked and never followed.
     *
     * @throws PackageVerificationException MODULE_UNINSTALL_REMOVE_FAILED
     */
    private function removeFiles(string $name): void
    {
        $runtimeRoot = RuntimeModules::path();
        if ($runtimeRoot === null) {
            throw new PackageVerificationException(self::ERROR_REMOVE, 'runtime modules folder is missing');
        }
        $runtimeRoot = rtrim($runtimeRoot, '/');

        $targets = [[$runtimeRoot . '/' . $name, $runtimeRoot]];
        foreach ([RuntimePlacement::VERSIONS_FOLDER, ModuleInstaller::PREVIOUS_FOLDER] as $folder) {
            $root = $runtimeRoot . '/' . $folder;
            if (@lstat($root) === false || is_link($root) || ! is_dir($root)) {
                continue;
            }
            foreach (@scandir($root) ?: [] as $entry) {
                if (preg_match('/^' . preg_quote($name, '/') . '-\d+\.\d+\.\d+$/', $entry) === 1) {
                    $targets[] = [$root . '/' . $entry, $root];
                }
            }
        }

        foreach ($targets as [$path, $root]) {
            if (@lstat($path) === false) {
                continue;
            }
            if (is_link($path)) {
                if (! @unlink($path)) {
                    throw new PackageVerificationException(self::ERROR_REMOVE, 'cannot remove a module link');
                }
            } elseif (! SafeExtractor::removeTree($path, $root)) {
                throw new PackageVerificationException(self::ERROR_REMOVE, 'cannot remove a module folder');
            }
        }
        // The signed integrity record of the module: a removed module must not leave one behind.
        $record = $runtimeRoot . '/' . ModuleIntegrity::FOLDER . '/' . $name . '.json';
        if (@lstat($record) !== false && ! @unlink($record)) {
            Log::warning('Module uninstall could not remove the integrity record.', ['module' => $name]);
        }
        // Published front-end assets, every version, after the code.
        if (! RuntimeAssets::remove($name)) {
            Log::warning('Module uninstall could not remove the published front-end assets.', ['module' => $name]);
        }
        // PHP keeps resolved real paths for a while; drop what this process knows.
        clearstatcache(true);
    }

    /**
     * Names of the other ENABLED modules (release and runtime) whose `module.json` requires this module.
     *
     * @return list<string>
     */
    private function enabledDependents(string $name): array
    {
        $folders = [];
        foreach ($this->catalog->releaseNames() as $module) {
            $folders[$module] = $this->catalog->releasePath() . DIRECTORY_SEPARATOR . $module;
        }
        $runtimeRoot = RuntimeModules::path();
        foreach ($runtimeRoot === null ? [] : $this->catalog->runtimeNames() as $module) {
            $folders[$module] = $runtimeRoot . DIRECTORY_SEPARATOR . $module;
        }

        $dependents = [];
        foreach ($folders as $module => $dir) {
            if ($module === $name) {
                continue;
            }
            $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'module.json');
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (! is_array($decoded) || ! is_array($decoded['requires'] ?? null)) {
                continue;
            }
            // Names compared without case: `probe` and `Probe` require the same module.
            if (ModuleVersionConstraint::constraintFor($decoded['requires'], $name) !== null && $this->activator()->hasStatus($module, true)) {
                $dependents[] = $module;
            }
        }
        sort($dependents);

        return $dependents;
    }

    /**
     * Declared tables the module may not drop: created by a release module or by `database/migrations`, or never
     * created by the module's own migrations.
     *
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private function unownedTables(string $dir, array $tables): array
    {
        $own = $this->createdTables([$dir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations']);
        $others = [database_path('migrations')];
        foreach ($this->catalog->releaseNames() as $module) {
            $others[] = $this->catalog->releasePath() . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        }
        $shared = $this->createdTables($others);

        return array_values(array_filter($tables, static fn (string $table): bool => ! in_array($table, $own, true) || in_array($table, $shared, true)));
    }

    /**
     * Table names created (`Schema::create('<name>'`) by the migration files under the given folders.
     *
     * @param list<string> $folders
     *
     * @return list<string>
     */
    private function createdTables(array $folders): array
    {
        $names = [];
        foreach ($folders as $folder) {
            if (! is_dir($folder)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $code = @file_get_contents($file->getPathname());
                if ($code !== false && preg_match_all('/Schema::create\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $code, $m) > 0) {
                    array_push($names, ...$m[1]);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Refuse while any table of the module's `uninstall.refuse_while_rows` exists and holds a row. A table that does not
     * exist holds nothing; a malformed declaration or a failed lookup refuses (fail closed).
     *
     * @throws PackageVerificationException MODULE_HAS_DATA
     */
    private function refuseWhileRows(string $dir): void
    {
        $tables = $this->declaredRefuseWhileRows($dir);
        if ($tables === null) {
            throw new PackageVerificationException(self::ERROR_HAS_DATA, 'the module declares uninstall.refuse_while_rows in a form that can not be checked');
        }

        $withRows = [];
        foreach ($tables as $table) {
            try {
                if (Schema::hasTable($table) && DB::table($table)->exists()) {
                    $withRows[] = $table;
                }
            } catch (Throwable) {
                throw new PackageVerificationException(self::ERROR_HAS_DATA, 'could not check whether the module still holds data in ' . $table);
            }
        }
        if ($withRows !== []) {
            throw new PackageVerificationException(self::ERROR_HAS_DATA, 'the module still holds records in: ' . implode(', ', $withRows));
        }
    }

    /**
     * `uninstall.refuse_while_rows` of the module's `module.json`: an empty list when not declared, null when the
     * declaration is malformed (not a list of at most MAX_UNINSTALL_TABLES safe table names).
     *
     * @return list<string>|null
     */
    private function declaredRefuseWhileRows(string $dir): ?array
    {
        $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'module.json');
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (! is_array($decoded) || ! array_key_exists('uninstall', $decoded)) {
            return [];
        }
        $uninstall = $decoded['uninstall'];
        if (! is_array($uninstall) || ($uninstall !== [] && array_is_list($uninstall))) {
            return null;
        }
        if (! array_key_exists('refuse_while_rows', $uninstall)) {
            return [];
        }
        $tables = $uninstall['refuse_while_rows'];
        if (! is_array($tables) || ($tables !== [] && ! array_is_list($tables)) || count($tables) > ReleaseManifest::MAX_UNINSTALL_TABLES) {
            return null;
        }
        foreach ($tables as $table) {
            if (! is_string($table) || preg_match(ReleaseManifest::TABLE_NAME_PATTERN, $table) !== 1) {
                return null;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * `uninstall.tables` of the module's `module.json`: safe table names only; anything else counts as not declared.
     *
     * @return list<string>
     */
    private function declaredTables(string $dir): array
    {
        $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'module.json');
        $decoded = $raw === false ? null : json_decode($raw, true);
        $tables = is_array($decoded) && is_array($decoded['uninstall'] ?? null) ? ($decoded['uninstall']['tables'] ?? null) : null;
        if (! is_array($tables) || ! array_is_list($tables) || count($tables) > ReleaseManifest::MAX_UNINSTALL_TABLES) {
            return [];
        }
        foreach ($tables as $table) {
            if (! is_string($table) || preg_match(ReleaseManifest::TABLE_NAME_PATTERN, $table) !== 1) {
                return [];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Failure: put the enabled state back when nothing was rolled back, fail row and task, audit.
     *
     * @param array<string, mixed>|null $facts
     */
    private function fail(ModuleInstall $install, BackgroundTask $task, ?User $user, string $code, Throwable $e, ?array $facts, bool $reenable, string $name): void
    {
        if ($e instanceof BackupRequiredException) {
            $code = BackupRequiredException::CODE;
        } elseif ($e instanceof PackageVerificationException) {
            $code = $e->errorCode;
        } else {
            Log::warning('Module uninstall failed.', ['install' => $install->uuid, 'module' => $name, 'code' => $code, 'exception' => $e::class]);
        }

        if ($reenable) {
            try {
                $this->statuses->change($name, true, $user, 'uninstall_failed');
            } catch (Throwable $again) {
                Log::error('Module uninstall could not enable the module again.', ['install' => $install->uuid, 'module' => $name, 'exception' => $again::class]);
            }
        }

        $status = $install->status;
        $backup = (string) $install->backup_uuid;
        $install->forceFill([
            'status' => ModuleInstall::STATUS_FAILED,
            'error_code' => mb_substr($code, 0, 100),
            'step_message' => mb_substr('Uninstall failed while ' . str_replace('_', ' ', $status) . ' (' . $code . '). The database was backed up first (backup ' . $backup . '): restore it if data changed.', 0, 500),
            'finished_at' => Carbon::now(),
        ])->save();

        if (in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            $this->recorder->fail($task, 'text.background_tasks.step.module_install.uninstall_failed', ['code' => $code]);
        }

        event(new AuditLogEvent('module.uninstall_failed', [
            'name' => $name,
            'version' => $facts['version'] ?? $install->version,
            'drop_data' => (bool) $install->confirmed_irreversible,
            'error_code' => $code,
            'backup_uuid' => $install->backup_uuid,
        ], ModuleInstall::class, (int) $install->id, null, null, (int) ($install->requested_by ?? 0)));
    }

    /**
     * Run one artisan command in a fresh process; a non-zero exit is a failure.
     *
     * @param list<string> $args
     */
    private function artisanStep(array $args, int $timeout): void
    {
        $result = $this->artisan->run($args, $timeout);
        if ($result->successful()) {
            return;
        }

        Log::warning('Module uninstall: artisan step failed.', [
            'command' => $args[0] ?? '',
            'exit_code' => $result->exitCode(),
            'error_output' => ArtisanSubprocess::truncate((string) $result->errorOutput()),
        ]);

        throw new RuntimeException('artisan ' . ($args[0] ?? '') . ' exited with code ' . (string) $result->exitCode());
    }

    /**
     * Update status, step message and task progress.
     */
    private function step(ModuleInstall $install, BackgroundTask $task, string $status, int $percent, string $message): void
    {
        $install->forceFill(['status' => $status, 'step_message' => $message])->save();
        $this->recorder->progress($task, $percent, 'text.background_tasks.step.module_install.' . $status);
    }

    /**
     * The row's background task; one is started when the row has none.
     */
    private function task(ModuleInstall $install): BackgroundTask
    {
        $task = $install->background_task_uuid !== null
            ? BackgroundTask::query()->where('uuid', $install->background_task_uuid)->first()
            : null;
        if ($task === null) {
            $task = $this->recorder->start(ModuleInstall::TASK_TYPE, 'Core', $install->requested_by);
            $install->forceFill(['background_task_uuid' => $task->uuid])->save();
        }

        return $task;
    }

    /**
     * Module status store.
     */
    private function activator(): DatabaseActivator
    {
        return $this->activator ?? new DatabaseActivator(app());
    }
}
