<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Support\Modules\RuntimeModuleAutoloader;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Scheduler\SchedulerHeartbeat;
use Closure;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Throwable;

/**
 * Explains, before anything starts, why a module install would be blocked and what it would change.
 *
 * Reads only: configuration (`core.module_packages.*`, `modules.activator`, `queue.*`), the folders on disk, the
 * migration repository and the hub catalog. Settings it relies on: ModuleInstallPolicy (`core.module_packages.install_enabled` + the admin switch)
 * (env MODULE_INSTALL_ENABLED), `.trusted_keys` / `.revoked_key_ids`, `.job_timeout` (seconds) and the site's
 * default queue connection (`queue.default`, its `retry_after`), which decide whether the scheduler runs installs.
 *
 * MODULE_ALREADY_PRESENT vs MODULE_NAME_CONFLICT: ALREADY_PRESENT means the very same module folder (exact name)
 * is installed already, in the release folder or as a runtime module; Runtime install only installs new modules.
 * NAME_CONFLICT means the name can not be used even though it is not a plain duplicate: any module with that name
 * exists in the release folder `cms/` (case-insensitive, nwidart lower-cases names so a runtime module would
 * replace it), a runtime folder differing only in case, or a runtime module that the autoloader skipped as invalid
 * and that still occupies the name. A release module with the exact name reports both codes.
 *
 * Upgrade: a package for a module that is installed under `runtime-modules/` (never one in the release
 * folder) is accepted as kind `upgrade` when its version is newer than the installed one; the result then carries
 * `previousVersion`. Same version is MODULE_SAME_VERSION, a lower one MODULE_DOWNGRADE, an installed version that can
 * not be read MODULE_INSTALLED_VERSION_UNKNOWN (MODULE_ALREADY_PRESENT), and an installed module whose `requires`
 * constraint on this module would not accept the new version is MODULE_DEPENDENT_PINS_VERSION.
 *
 * Release-to-runtime handover: a module the release ships that the project lists in `runtime_modules.json`
 * is not ALREADY_PRESENT / SHADOWS_RELEASE / NAME_CONFLICT. Its package is accepted as kind `upgrade` (previous version
 * = the higher of the release copy and a present runtime copy) when it is newer, and only when the package declares a
 * Core range (`requires: {"core": ">=A,<B"}`, else MODULE_HANDOVER_NEEDS_CORE_RANGE) that the site's Core meets
 * (checked with the other requirements). The release copy stays in place as the fallback; RuntimePrecedence decides
 * at boot which copy runs.
 *
 * Hub errors (PlatformHubException) propagate: an untrusted hub answer must never read as "nothing blocks".
 *
 * Offline upload: {@see self::checkUpload} runs the same site, name, requirement, PHP, extension,
 * migration and queue checks, but reads the module facts (requires, php_min, irreversible, custom_code) from the
 * signed manifest instead of the hub catalog, and adds MODULE_UPLOAD_LIMIT_TOO_LOW when PHP's `upload_max_filesize`
 * or `post_max_size` is below `core.module_packages.max_zip_bytes` (post also needs room for the signature file).
 * Hub revocation is not visible offline; revoked signing keys are still refused by PackageVerifier.
 *
 * Package facts of the signed manifest (, {@see self::packageBlocking}): MODULE_ADMIN_PACKAGE_MISSING when
 * the manifest's `admin_packages` names a shared admin package that the site's admin shell list does not provide, or
 * provides with another major version; a site without a readable shell list blocks every package that has
 * `admin_packages`. MODULE_THEME_COUPLED when `frontend.styles` is `project-theme`: such modules ship only with their
 * website's deploy, so this is a second line of defence behind the builder.
 * Upload preflight runs these checks; the hub catalog does not carry these facts, so a hub install is checked by
 * ModuleInstaller at its verifying step, before anything is placed. Results name packages, never file paths.
 *
 * Reinstall over kept tables: an uninstall that keeps the data leaves the module's tables and migration
 * rows, so `module:migrate` skips those `create` migrations and the module uses the old rows again; the backup advice
 * then reports `earlier_data` (switch on, tables named). A first install whose `<ts>_create_<table>_table` has NOT
 * run while that table exists would stop inside `module:migrate` ("table already exists"), or, for a `create` that
 * guards with Schema::hasTable, quietly take over a table it did not make; so it is MODULE_TABLE_EXISTS with
 * `details.tables` (read from migration names, the same static check as the advice; the files are not read).
 *
 * Partner modules: MODULE_WARN_THIRD_PARTY_FIRST_INSTALL when the module is not published by iSPA and this
 * site has no earlier succeeded, confirmed install from the same publisher ({@see self::thirdPartyPublisher()},
 * {@see self::confirmedBefore()}). Its details carry the publisher and, when the hub has it, the technical sheet's
 * permissions, menus, routes and database impact of the version. The warning does not block; the install endpoints
 * refuse it without `confirm_third_party` and the installer refuses a partner package whose row has no confirmation.
 */
final class InstallPreflight
{
    public const INSTALL_DISABLED = 'MODULE_INSTALL_DISABLED';
    public const FORBIDDEN_BY_SERVER = 'MODULE_INSTALL_FORBIDDEN_BY_SERVER';
    public const FORBIDDEN_BY_HUB = 'MODULE_INSTALL_FORBIDDEN_BY_HUB';
    public const NEEDS_DB_STATUS = 'MODULE_INSTALL_NEEDS_DB_STATUS';
    public const NO_RUNTIME_FOLDER = 'MODULE_INSTALL_NO_RUNTIME_FOLDER';
    public const NO_TRUSTED_KEY = 'MODULE_INSTALL_NO_TRUSTED_KEY';
    public const NOT_IN_CATALOG = 'MODULE_NOT_IN_CATALOG';
    public const ALREADY_PRESENT = 'MODULE_ALREADY_PRESENT';
    public const NAME_CONFLICT = 'MODULE_NAME_CONFLICT';
    public const SHADOWS_RELEASE = 'MODULE_SHADOWS_RELEASE';
    public const DOWNGRADE = 'MODULE_DOWNGRADE';
    public const SAME_VERSION = 'MODULE_SAME_VERSION';
    public const DEPENDENT_PINS_VERSION = 'MODULE_DEPENDENT_PINS_VERSION';
    public const VERSION_UNKNOWN = 'MODULE_INSTALLED_VERSION_UNKNOWN';
    public const REVOKED = 'MODULE_REVOKED';
    public const REQUIRES_UNMET = 'MODULE_REQUIRES_UNMET';
    public const PHP_TOO_OLD = 'MODULE_PHP_TOO_OLD';
    public const EXTENSION_MISSING = 'MODULE_EXTENSION_MISSING';
    public const PENDING_MIGRATIONS = 'MODULE_PENDING_MIGRATIONS';
    public const QUEUE_SYNC = 'MODULE_INSTALL_QUEUE_SYNC';
    public const QUEUE_TIMEOUT = 'MODULE_INSTALL_QUEUE_TIMEOUT';
    public const NO_RUNNER = 'MODULE_INSTALL_NO_RUNNER';
    public const UPLOAD_LIMIT_TOO_LOW = 'MODULE_UPLOAD_LIMIT_TOO_LOW';
    public const ADMIN_PACKAGE_MISSING = 'MODULE_ADMIN_PACKAGE_MISSING';
    public const THEME_COUPLED = 'MODULE_THEME_COUPLED';
    public const HANDOVER_NEEDS_CORE_RANGE = 'MODULE_HANDOVER_NEEDS_CORE_RANGE';
    public const TABLE_EXISTS = 'MODULE_TABLE_EXISTS';

    /** The admin shell's shared package list inside an admin source folder (base-admin layout,). */
    public const SHELL_PACKAGES_FILE = 'src/config/shared-packages.json';

    /** Bytes allowed for the uploaded signature file (also counted against `post_max_size`). */
    public const SIGNATURE_MAX_BYTES = 2 * 1024 * 1024;

    public const WARN_IRREVERSIBLE = 'MODULE_WARN_IRREVERSIBLE_MIGRATIONS';
    public const WARN_CUSTOM_CODE = 'MODULE_WARN_CUSTOM_CODE';
    public const WARN_RESOURCE_ONLY = 'MODULE_WARN_RESOURCE_ONLY';
    public const WARN_THIRD_PARTY_FIRST_INSTALL = 'MODULE_WARN_THIRD_PARTY_FIRST_INSTALL';

    /** Error code of an install of a partner module that was not explicitly confirmed. */
    public const THIRD_PARTY_CONFIRMATION_REQUIRED = 'MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED';

    /** Publisher type of iSPA's own modules. */
    public const PUBLISHER_ISPA = 'ispa';

    /** PHP extensions a package install needs. */
    public const REQUIRED_EXTENSIONS = ['sodium', 'zip'];

    private string $phpVersion;

    /** @var Closure(string): bool */
    private Closure $extensionLoaded;

    /** @var (Closure(): list<string>)|null */
    private ?Closure $migrationPaths;

    /** @var (Closure(): list<string>)|null */
    private ?Closure $ranMigrations;

    /** @var (Closure(): array<string, mixed>)|null */
    private ?Closure $skippedRuntime;

    /** @var Closure(string): (string|false) */
    private Closure $iniGet;

    /** @var (Closure(string): bool)|null */
    private ?Closure $hasTable;

    private ?ModuleListingClient $listings;

    /**
     * Every closure is optional and exists so tests can fake the environment.
     *
     * @param string|null                              $phpVersion      running PHP version (default PHP_VERSION)
     * @param (callable(string): bool)|null            $extensionLoaded extension_loaded() replacement
     * @param (callable(): list<string>)|null          $migrationPaths  migration folders of the enabled modules
     * @param (callable(): list<string>)|null          $ranMigrations   migration names recorded as run
     * @param (callable(): array<string, mixed>)|null  $skippedRuntime  RuntimeModuleAutoloader::skipped() replacement
     * @param (callable(string): (string|false))|null  $iniGet          ini_get() replacement (upload limits)
     * @param (callable(string): bool)|null            $hasTable        Schema::hasTable() replacement (backup advice)
     * @param ModuleListingClient|null                 $listings        full hub listings (technical sheet of a partner module)
     */
    public function __construct(
        private readonly ModuleCatalog $catalog,
        ?string $phpVersion = null,
        ?callable $extensionLoaded = null,
        ?callable $migrationPaths = null,
        ?callable $ranMigrations = null,
        ?callable $skippedRuntime = null,
        ?callable $iniGet = null,
        ?callable $hasTable = null,
        ?ModuleListingClient $listings = null,
    ) {
        $this->phpVersion = $phpVersion ?? PHP_VERSION;
        $this->extensionLoaded = $extensionLoaded !== null ? Closure::fromCallable($extensionLoaded) : 'extension_loaded'(...);
        $this->migrationPaths = $migrationPaths !== null ? Closure::fromCallable($migrationPaths) : null;
        $this->ranMigrations = $ranMigrations !== null ? Closure::fromCallable($ranMigrations) : null;
        $this->skippedRuntime = $skippedRuntime !== null ? Closure::fromCallable($skippedRuntime) : null;
        $this->iniGet = $iniGet !== null ? Closure::fromCallable($iniGet) : 'ini_get'(...);
        $this->hasTable = $hasTable !== null ? Closure::fromCallable($hasTable) : null;
        $this->listings = $listings;
    }

    /**
     * Check whether $name at $version can be installed now.
     *
     * @throws PlatformHubException when the hub catalog can not be fetched or trusted
     */
    public function check(string $name, string $version): PreflightResult
    {
        $blocking = [];
        $warnings = [];
        $changes = [];

        $add = static function (array &$list, string $code, string $message, array $details = []): void {
            $list[] = ['code' => $code, 'message' => $message, 'details' => $details];
        };

        $this->checkSite($add, $blocking);
        $upgrade = $this->checkNameUse($name, $version, $add, $blocking);

        $found = $this->catalog->find($name, $version);
        $backup = null;
        if ($found === null) {
            $add($blocking, self::NOT_IN_CATALOG, "Module {$name} {$version} is not published on the platform hub.");
        } else {
            $entry = $found['version'];
            if ($entry['revoked']) {
                $add($blocking, self::REVOKED, "Module {$name} {$version} was revoked by the publisher.", [
                    'reason' => $entry['revoked_reason'],
                ]);
            }

            $this->checkEntry($name, $version, $entry, false, $add, $blocking, $warnings, $changes);
            $this->checkHandoverRange($name, (array) ($entry['requires'] ?? []), $add, $blocking);
            $backup = $this->backupAdvice(
                in_array(self::WARN_IRREVERSIBLE, array_column($warnings, 'code'), true),
                InstallBackupAdvice::migrationsFromCatalog($entry['migrations'] ?? null),
                $this->isFirstInstall($name, $upgrade),
            );
            $this->checkTableConflicts($name, $backup, $add, $blocking);
            $this->checkThirdParty($name, $version, null, false, $add, $warnings);
        }

        $this->checkEnvironment($add, $blocking);

        return $this->result($name, $version, $blocking, $warnings, $changes, $upgrade, $backup ?? $this->backupAdvice(false, null));
    }

    /**
     * Check whether an uploaded package, whose signed manifest is already verified, can be installed now.
     *
     * Same checks as {@see self::check()} with the module facts taken from the manifest (no hub request), plus the
     * PHP upload limit check.
     */
    public function checkUpload(ReleaseManifest $manifest): PreflightResult
    {
        $name = $manifest->name;
        $version = $manifest->version;
        $blocking = [];
        $warnings = [];
        $changes = [];

        $add = static function (array &$list, string $code, string $message, array $details = []): void {
            $list[] = ['code' => $code, 'message' => $message, 'details' => $details];
        };

        $this->checkSite($add, $blocking);
        $upgrade = $this->checkNameUse($name, $version, $add, $blocking);
        $this->checkEntry($name, $version, [
            'requires' => $manifest->requires,
            'php_min' => $manifest->phpMin,
            'irreversible' => $manifest->irreversibleMigrations !== [] ? $manifest->irreversibleMigrations : $manifest->irreversible,
            'custom_code' => $manifest->customCode,
        ], true, $add, $blocking, $warnings, $changes);
        $this->checkHandoverRange($name, (array) $manifest->requires, $add, $blocking);
        foreach (self::packageBlocking($manifest) as $item) {
            $blocking[] = $item;
        }
        foreach ($this->uploadLimitBlocking((int) config('core.module_packages.max_zip_bytes', 50 * 1024 * 1024)) as $item) {
            $blocking[] = $item;
        }
        $this->checkEnvironment($add, $blocking);
        $backup = $this->backupAdvice(
            in_array(self::WARN_IRREVERSIBLE, array_column($warnings, 'code'), true),
            // isset(): a manifest built without its file list (never from a verified signature) reads as unknown.
            isset($manifest->files) ? InstallBackupAdvice::migrationsFromFiles($manifest->files) : null,
            $this->isFirstInstall($name, $upgrade),
        );
        $this->checkTableConflicts($name, $backup, $add, $blocking);
        $this->checkThirdParty($name, $version, $manifest->keyId, true, $add, $warnings);

        return $this->result($name, $version, $blocking, $warnings, $changes, $upgrade, $backup);
    }

    /**
     * The publisher of $name when iSPA does not publish it, or null for an iSPA module.
     *
     * Sources, any of which marks the module as a partner module:
     *  - the hub catalog entry: its `publisher` (sent for every non-iSPA module) or its listing summary's publisher;
     *  - the signing key: a package signed with a hub countersign key (`core.module_packages.countersign_key_ids`)
     *    is a partner package whatever the hub says; the only way a partner package verifies on a site.
     * A module the hub does not name (offline upload with no hub, hub unreachable, an older hub) is decided by its
     * signing key alone; on the hub path the key is not known before the download, so the installer checks it again.
     * `key` is the hub's publisher key, or ModuleInstall::PUBLISHER_UNKNOWN when only the signing key tells.
     *
     * @param string|null $keyId     key id of the signed manifest, when known
     * @param bool        $hubOptional read the hub only when this site is connected to it (offline upload path)
     *
     * @return array{key: string, type: string, name: ?string}|null
     */
    public function thirdPartyPublisher(string $name, ?string $keyId, bool $hubOptional = false): ?array
    {
        $publisher = $this->hubPublisher($name, $hubOptional);
        if ($publisher !== null && $publisher['type'] !== self::PUBLISHER_ISPA) {
            return ['key' => $publisher['key'], 'type' => $publisher['type'], 'name' => $publisher['name']];
        }
        if ($keyId !== null && in_array($keyId, self::countersignKeyIds(), true)) {
            return ['key' => ModuleInstall::PUBLISHER_UNKNOWN, 'type' => 'partner', 'name' => null];
        }

        return null;
    }

    /**
     * Key ids of the hub countersign keys (`core.module_packages.countersign_key_ids`).
     *
     * @return list<string>
     */
    public static function countersignKeyIds(): array
    {
        return array_values(array_map('strval', (array) config('core.module_packages.countersign_key_ids', [])));
    }

    /**
     * Whether this site already installed a module of publisher $key with an explicit confirmation, and that install
     * succeeded. An unknown publisher (null or ModuleInstall::PUBLISHER_UNKNOWN) never counts as confirmed.
     */
    public static function confirmedBefore(?string $key): bool
    {
        if ($key === null || $key === '' || $key === ModuleInstall::PUBLISHER_UNKNOWN) {
            return false;
        }

        try {
            return ModuleInstall::query()
                ->where('third_party_publisher', $key)
                ->where('status', ModuleInstall::STATUS_SUCCEEDED)
                ->whereNotNull('third_party_confirmed_at')
                ->exists();
        } catch (Throwable) {
            // A site whose installs table predates the columns has no earlier confirmation.
            return false;
        }
    }

    /**
     * MODULE_WARN_THIRD_PARTY_FIRST_INSTALL for a partner module this site never confirmed.
     *
     * @param Closure $add
     * @param array   $warnings
     */
    private function checkThirdParty(string $name, string $version, ?string $keyId, bool $hubOptional, Closure $add, array &$warnings): void
    {
        $publisher = $this->thirdPartyPublisher($name, $keyId, $hubOptional);
        if ($publisher === null || self::confirmedBefore($publisher['key'])) {
            return;
        }

        $add($warnings, self::WARN_THIRD_PARTY_FIRST_INSTALL, "Module {$name} is published by a partner, not by iSPA, and runs with full rights on this site. Confirm that you understand this to install it.", [
            'publisher' => $publisher['key'] === ModuleInstall::PUBLISHER_UNKNOWN ? null : ['key' => $publisher['key'], 'type' => $publisher['type'], 'name' => $publisher['name']],
            'impact' => $this->impact($name, $version, $hubOptional),
        ]);
    }

    /**
     * Publisher `{key, type, name}` the hub names for $name, or null when it names none or can not be read.
     */
    private function hubPublisher(string $name, bool $hubOptional): ?array
    {
        if ($hubOptional && !$this->hubConnected()) {
            return null;
        }

        try {
            foreach ($this->catalog->hubEntries() as $entry) {
                if (($entry['name'] ?? null) === $name) {
                    $publisher = $entry['publisher'] ?? ($entry['listing']['publisher'] ?? null);

                    return is_array($publisher) && is_string($publisher['type'] ?? null) ? $publisher : null;
                }
            }
        } catch (Throwable) {
            // Unknown publisher: decided by the signing key.
        }

        return null;
    }

    /**
     * Permissions, admin menus, routes and database impact of $name $version from the hub technical sheet; null when
     * the hub has no sheet for it or can not be read. Names and counts only, never a path of the site.
     *
     * @return array{permissions: list<string>, menus: int, routes: array{public: int, admin: int, api: int}, tables_created: list<string>, tables_altered: list<string>, irreversible: bool, migrations: int}|null
     */
    private function impact(string $name, string $version, bool $hubOptional): ?array
    {
        if ($hubOptional && !$this->hubConnected()) {
            return null;
        }

        try {
            $listing = ($this->listings ?? new ModuleListingClient())->listing($name);
        } catch (Throwable) {
            return null;
        }
        foreach ((array) ($listing['versions'] ?? []) as $candidate) {
            if (($candidate['version'] ?? null) !== $version || !is_array($candidate['sheet'] ?? null)) {
                continue;
            }
            $sheet = $candidate['sheet'];
            $irreversible = $sheet['database']['irreversible'] ?? false;

            return [
                'permissions' => array_values((array) ($sheet['admin']['permissions'] ?? [])),
                'menus' => (int) ($sheet['admin']['menus'] ?? 0),
                'routes' => [
                    'public' => (int) ($sheet['routes']['public'] ?? 0),
                    'admin' => (int) ($sheet['routes']['admin'] ?? 0),
                    'api' => (int) ($sheet['routes']['api'] ?? 0),
                ],
                'tables_created' => array_values((array) ($sheet['database']['tables_created'] ?? [])),
                'tables_altered' => array_values((array) ($sheet['database']['tables_altered'] ?? [])),
                'irreversible' => $irreversible === true || (is_array($irreversible) && $irreversible !== []),
                'migrations' => (int) ($sheet['database']['migrations'] ?? 0),
            ];
        }

        return null;
    }

    /**
     * Whether this site is connected to the hub (credentials and hub URL set); the upload path never needs it.
     */
    private function hubConnected(): bool
    {
        try {
            return trim((string) config('ispa-cms.platform_hub_url', '')) !== '' && (new HubCredentials())->isConfigured();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Default of the pre-install backup switch plus the site's last own backup.
     *
     * @param list<string>|null $migrations   migration names the version ships, null when not known
     * @param bool $firstInstall the module is not installed yet (its own tables count as new)
     *
     * @return array{recommended: bool, reason: string, new_migrations: int, changes_database: bool, altered_tables: list<string>, last_backup: array{at: ?string, source: ?string}}
     */
    private function backupAdvice(bool $irreversible, ?array $migrations, bool $firstInstall = false): array
    {
        return InstallBackupAdvice::advise($irreversible, $migrations, $this->ran(), $this->hasTable, $firstInstall)
            + ['last_backup' => InstallBackupAdvice::lastBackup()];
    }

    /**
     * True when $name is not on this site at all. No upgrade is not enough: a same-version or older request
     * for an installed module is blocked with no upgrade, and its live tables must not read as an earlier install's.
     */
    private function isFirstInstall(string $name, ?string $upgrade): bool
    {
        return $upgrade === null && $this->catalog->installedState($name)['installed'] === 'none';
    }

    /**
     * MODULE_TABLE_EXISTS when a first install would run a `create_<table>_table` for a table that already exists
     *: the migration has no row on this site, so `module:migrate` would run it and stop half way.
     *
     * @param array<string, mixed> $backup advice from {@see self::backupAdvice()}
     * @param array                $blocking
     */
    private function checkTableConflicts(string $name, array $backup, Closure $add, array &$blocking): void
    {
        $tables = array_values(array_map('strval', (array) ($backup['conflicting_tables'] ?? [])));
        if ($tables === []) {
            return;
        }
        $add($blocking, self::TABLE_EXISTS, "This site already has tables that module {$name} would create, without a record that the module created them: " . implode(', ', $tables) . '. The install could stop part way or take over a table it did not make; nothing was changed.', [
            'tables' => $tables,
        ]);
    }

    /**
     * Build the result; an upgrade replaces the install change list with the upgrade steps.
     *
     * @param list<array{code: string, message: string, details: array<string, mixed>}> $blocking
     * @param list<array{code: string, message: string, details: array<string, mixed>}> $warnings
     * @param list<string>                                                              $changes
     * @param string|null                                                               $upgrade installed version being replaced, null for a first install
     * @param array<string, mixed>                                                      $backup  pre-install backup advice ({@see self::backupAdvice()})
     */
    private function result(string $name, string $version, array $blocking, array $warnings, array $changes, ?string $upgrade, array $backup = []): PreflightResult
    {
        if ($upgrade === null) {
            return new PreflightResult($name, $version, $blocking, $warnings, $changes, 'install', null, $backup);
        }

        $handover = $this->catalog->isHandover($name);
        $changes = array_map(static fn (string $c): string => match (true) {
            str_starts_with($c, 'Place ') && $handover => "Keep the release copy of {$name} as the fallback, place {$version} in runtime-modules and keep the module enabled as it is.",
            str_starts_with($c, 'Place ') => "Keep {$name} {$upgrade} in runtime-modules/.previous, place {$version} and keep the module enabled as it is.",
            str_starts_with($c, 'Run the module migrations') => 'Run only the new migrations and refresh caches.',
            default => $c,
        }, $changes);

        return new PreflightResult($name, $version, $blocking, $warnings, $changes, 'upgrade', $upgrade, $backup);
    }

    /**
     * Blocking items from the package facts of a signed manifest: MODULE_THEME_COUPLED and MODULE_ADMIN_PACKAGE_MISSING.
     *
     * Used by {@see self::checkUpload()} and by ModuleInstaller at its verifying step. Details carry package names and
     * major versions only, never a path of the site.
     *
     * @param string|null $basePath code folder of the site (default: base_path()); see {@see self::shellAdminPackages()}
     *
     * @return list<array{code: string, message: string, details: array<string, mixed>}>
     */
    public static function packageBlocking(ReleaseManifest $manifest, ?string $basePath = null): array
    {
        $blocking = [];

        if (($manifest->frontend['styles'] ?? null) === 'project-theme') {
            $blocking[] = [
                'code' => self::THEME_COUPLED,
                'message' => "Module {$manifest->name} relies on its website's theme; it ships only with that website and can not be installed here.",
                'details' => [],
            ];
        }

        $needed = $manifest->adminPackages ?? [];
        if ($needed !== []) {
            $shell = self::shellAdminPackages($basePath);
            $missing = [];
            ksort($needed, SORT_STRING);
            foreach ($needed as $package => $major) {
                $available = $shell[$package] ?? null;
                if ($available !== $major) {
                    $missing[] = ['name' => (string) $package, 'required_major' => $major, 'shell_major' => $available];
                }
            }
            if ($missing !== []) {
                $names = array_column($missing, 'name');
                $blocking[] = [
                    'code' => self::ADMIN_PACKAGE_MISSING,
                    'message' => $shell === null
                        ? 'This site\'s admin has no shared package list, so it can not provide the admin packages the module needs: ' . implode(', ', $names) . '. Deploy a current admin first.'
                        : 'This site\'s admin does not provide the shared admin packages the module needs (missing or another major version): ' . implode(', ', $names) . '. Deploy a current admin first.',
                    'details' => ['packages' => $names, 'missing' => $missing, 'shell_list' => $shell !== null],
                ];
            }
        }

        return $blocking;
    }

    /**
     * The site's admin shell list (package name => major), or null when no readable format 1 list exists.
     *
     * Source: `core.module_packages.shared_admin_packages_file` when set (then the only source); else the first
     * existing of, relative to the site's code folder $basePath (default base_path()):
     *  1. `<site>/runtime/admin-workspace/admin/src/config/shared-packages.json` when $basePath is a deployed release
     *     (`<site>/releases/<release>`): deploy.sh deletes `<release>/admin` after the build and keeps the admin source
     *     in the runtime admin workspace, which the admin rebuild of a module install uses;
     *  2. `<base>/admin/src/config/shared-packages.json` (release layout, before the admin folder is removed);
     *  3. `<base>/../base-admin/src/config/shared-packages.json` (workspace checkout).
     *
     * @return array<string, int>|null
     */
    public static function shellAdminPackages(?string $basePath = null): ?array
    {
        $configured = config('core.module_packages.shared_admin_packages_file');
        if (is_string($configured) && $configured !== '') {
            return self::readShellList($configured);
        }

        $base = rtrim($basePath ?? base_path(), '/');
        $real = realpath($base);
        $candidates = [];
        if ($real !== false && basename(dirname($real)) === 'releases') {
            $candidates[] = dirname($real, 2) . '/runtime/admin-workspace/admin/' . self::SHELL_PACKAGES_FILE;
        }
        $candidates[] = $base . '/admin/' . self::SHELL_PACKAGES_FILE;
        $candidates[] = $base . '/../base-admin/' . self::SHELL_PACKAGES_FILE;

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return self::readShellList($candidate);
            }
        }

        return null;
    }

    /**
     * Parse one shell list file: `{"format": 1, "packages": {"<npm name>": {"major": <int>, ...}}}`; null when the
     * file is missing, unreadable or malformed (any invalid entry makes the whole list unusable).
     *
     * @return array<string, int>|null
     */
    private static function readShellList(string $path): ?array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $list = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($list) || ($list['format'] ?? null) !== 1 || ! is_array($list['packages'] ?? null) || $list['packages'] === [] || array_is_list($list['packages'])) {
            return null;
        }

        $out = [];
        foreach ($list['packages'] as $name => $entry) {
            $major = is_array($entry) ? ($entry['major'] ?? null) : null;
            if (preg_match(ReleaseManifest::NPM_NAME_PATTERN, (string) $name) !== 1 || ! is_int($major) || $major < 0) {
                return null;
            }
            $out[(string) $name] = $major;
        }

        return $out;
    }

    /**
     * MODULE_UPLOAD_LIMIT_TOO_LOW items when PHP would drop an upload of $maxZipBytes (plus the signature file).
     *
     * `upload_max_filesize` must hold the zip; `post_max_size` the zip and the signature file. 0, -1 or an unset
     * value means unlimited.
     *
     * @return list<array{code: string, message: string, details: array<string, mixed>}>
     */
    public function uploadLimitBlocking(int $maxZipBytes): array
    {
        $upload = self::iniBytes(($this->iniGet)('upload_max_filesize'));
        $post = self::iniBytes(($this->iniGet)('post_max_size'));
        $postNeeded = $maxZipBytes + self::SIGNATURE_MAX_BYTES;

        if (($upload === null || $upload >= $maxZipBytes) && ($post === null || $post >= $postNeeded)) {
            return [];
        }

        return [[
            'code' => self::UPLOAD_LIMIT_TOO_LOW,
            'message' => 'PHP upload limits (upload_max_filesize, post_max_size) are lower than the module package limit; raise them before uploading.',
            'details' => [
                'upload_max_filesize' => $upload,
                'post_max_size' => $post,
                'max_zip_bytes' => $maxZipBytes,
                'post_needed' => $postNeeded,
            ],
        ]];
    }

    /**
     * Bytes of a php.ini size value such as `100M`, `2G`, `512K` or `1048576`; null when unlimited (0, -1, empty).
     */
    public static function iniBytes(string|false|null $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^(-?\d+)\s*([kmgKMG]?)$/', $value, $m)) {
            return null;
        }
        $number = (int) $m[1];
        if ($number <= 0) {
            return null;
        }

        return match (strtolower($m[2])) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Whether a version's `irreversible` flag (hub catalog or signed manifest: bool, null or a list of migration
     * names) says it has migrations that can not be rolled back. Shared with the upgrade-all planner.
     */
    public static function isIrreversible(mixed $flag): bool
    {
        return $flag !== false && $flag !== [] && $flag !== null;
    }

    /**
     * Requirement, PHP version, warnings and changes of one module version (hub catalog entry or signed manifest).
     *
     * @param array<string, mixed> $entry    requires, php_min, irreversible, custom_code
     * @param bool                 $uploaded the package was uploaded (no download)
     * @param Closure              $add
     * @param array                $blocking
     * @param array                $warnings
     * @param list<string>         $changes
     */
    private function checkEntry(string $name, string $version, array $entry, bool $uploaded, Closure $add, array &$blocking, array &$warnings, array &$changes): void
    {
        $unmet = $this->unmetRequirements((array) $entry['requires']);
        if ($unmet !== []) {
            $core = collect($unmet)->first(static fn (array $row): bool => ModuleVersionConstraint::sameModule($row['module'], 'Core') && $row['reason'] === 'version');
            $message = $core !== null
                ? "Core {$core['installed_version']} is too old for {$name} (needs {$core['constraint']}). Deploy or upgrade Core first."
                : 'Required modules are missing, disabled or have the wrong version.';
            $add($blocking, self::REQUIRES_UNMET, $message, [
                'unmet' => $unmet,
            ]);
        }

        if (is_string($entry['php_min']) && $entry['php_min'] !== '' && version_compare($this->phpVersion, $entry['php_min'], '<')) {
            $add($blocking, self::PHP_TOO_OLD, "Module {$name} needs PHP {$entry['php_min']} or newer.", [
                'required' => $entry['php_min'],
                'running' => $this->phpVersion,
            ]);
        }

        $this->describeChanges($name, $version, $entry, $uploaded, $add, $warnings, $changes);
    }

    /**
     * Extensions, pending migrations and queue.
     *
     * @param Closure $add
     * @param array   $blocking
     */
    private function checkEnvironment(Closure $add, array &$blocking): void
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn (string $ext): bool => !($this->extensionLoaded)($ext)));
        if ($missing !== []) {
            $add($blocking, self::EXTENSION_MISSING, 'Required PHP extensions are not loaded: ' . implode(', ', $missing) . '.', [
                'extensions' => $missing,
            ]);
        }

        $pending = $this->pendingMigrations();
        if ($pending !== []) {
            $add($blocking, self::PENDING_MIGRATIONS, 'Enabled modules have migrations that have not run yet; run them first.', [
                'count' => count($pending),
                'migrations' => array_slice($pending, 0, 20),
            ]);
        }

        $this->checkQueue($add, $blocking);
    }

    /**
     * Site-level switches: install setting, activator, runtime folder and trusted signing key.
     *
     * @param Closure $add
     * @param array   $blocking
     */
    private function checkSite(Closure $add, array &$blocking): void
    {
        // Server, hub and the Webmaster's switch (spec 2026-11-22); "switched off" points at the admin switch.
        // While it is off, the activator and the folder are not reported: switching it on prepares both (unless
        // `.env` forces the file activator, which only the server can change).
        $state = app(ModuleInstallPolicy::class)->state();
        $switchFixes = $state === ModuleInstallPolicy::STATE_DISABLED;
        match ($state) {
            ModuleInstallPolicy::STATE_FORBIDDEN_BY_SERVER => $add($blocking, self::FORBIDDEN_BY_SERVER, 'The server has locked module install on this site (MODULE_INSTALL_ENABLED=false).'),
            ModuleInstallPolicy::STATE_FORBIDDEN_BY_HUB => $add($blocking, self::FORBIDDEN_BY_HUB, 'iSPA has locked module install for this website.'),
            ModuleInstallPolicy::STATE_DISABLED => $add($blocking, self::INSTALL_DISABLED, 'Module install is switched off; switch on "Allow module install" on the Modules page.'),
            default => null,
        };
        if (config('modules.activator') !== 'database' && !($switchFixes && empty(config('modules.activator_env')))) {
            $add($blocking, self::NEEDS_DB_STATUS, 'Module install needs module status in the database (MODULES_ACTIVATOR=database).');
        }
        if (RuntimeModules::path() === null && !$switchFixes) {
            $add($blocking, self::NO_RUNTIME_FOLDER, 'The runtime modules folder does not exist on this site.');
        }

        $revoked = array_map('strval', (array) config('core.module_packages.revoked_key_ids', []));
        $usable = array_diff(array_map('strval', array_keys((array) config('core.module_packages.trusted_keys', []))), $revoked);
        if ($usable === []) {
            $add($blocking, self::NO_TRUSTED_KEY, 'No trusted signing key is configured, so no package can be verified.');
        }
    }

    /**
     * Duplicate, upgrade and name-collision checks; see the class docblock for the difference.
     *
     * @param Closure $add
     * @param array   $blocking
     *
     * @return string|null the installed version when the package is a valid upgrade of a runtime module, else null
     */
    private function checkNameUse(string $name, string $version, Closure $add, array &$blocking): ?string
    {
        $release = $this->catalog->releaseNames();
        $runtime = $this->catalog->runtimeNames();
        $skipped = array_map('strval', array_keys($this->skippedRuntime !== null
            ? ($this->skippedRuntime)()
            : RuntimeModuleAutoloader::skipped()));

        $inRelease = in_array($name, $release, true);
        $inRuntime = in_array($name, $runtime, true) && !in_array($name, $skipped, true);
        $handover = $inRelease && $this->catalog->isHandover($name);
        $upgrade = null;
        if ($handover) {
            $upgrade = $this->checkHandover($name, $version, $add, $blocking);
        } elseif ($inRelease) {
            $add($blocking, self::ALREADY_PRESENT, "Module {$name} is already installed.", ['location' => 'release']);
        } elseif ($inRuntime) {
            $upgrade = $this->checkUpgrade($name, $version, $add, $blocking);
        }

        $lower = strtolower($name);
        $releaseClash = array_values(array_filter($release, fn (string $n): bool => strtolower($n) === $lower && !($handover && $n === $name)));
        $runtimeClash = array_values(array_filter(
            $runtime,
            fn (string $n): bool => strtolower($n) === $lower && $n !== $name
        ));
        // A handover module's own runtime copy is ignored at boot while the release copy wins; the upgrade replaces it.
        $skippedClash = array_values(array_filter($skipped, fn (string $n): bool => strtolower($n) === $lower && !($handover && $n === $name)));

        if ($inRelease && !$handover) {
            $add($blocking, self::SHADOWS_RELEASE, "A runtime copy of {$name} would shadow the release module of the same name in cms/.", [
                'release' => $name,
            ]);
        }

        if ($releaseClash !== [] || $runtimeClash !== [] || $skippedClash !== []) {
            $add($blocking, self::NAME_CONFLICT, "The name {$name} collides with an existing module folder.", [
                'release' => $releaseClash,
                'runtime' => $runtimeClash,
                'skipped_runtime' => $skippedClash,
            ]);
        }

        return $upgrade;
    }

    /**
     * Version rules of a handover package for a listed release module: newer than the release copy and
     * than a present runtime copy, and accepted by installed dependents.
     *
     * @param Closure $add
     * @param array   $blocking
     *
     * @return string|null the version being replaced when $version is acceptable, else null
     */
    private function checkHandover(string $name, string $version, Closure $add, array &$blocking): ?string
    {
        $baseline = $this->catalog->handoverBaseline($name);
        if ($baseline === null) {
            $add($blocking, self::VERSION_UNKNOWN, "The installed version of {$name} can not be read, so it can not be upgraded.");

            return null;
        }

        $order = ModuleVersionConstraint::compare($version, $baseline);
        if ($order === 0) {
            $add($blocking, self::SAME_VERSION, "Module {$name} {$baseline} is already installed.", ['installed_version' => $baseline]);

            return null;
        }
        if ($order < 0) {
            $add($blocking, self::DOWNGRADE, "Module {$name} {$baseline} is installed; {$version} is older.", [
                'installed_version' => $baseline,
                'package_version' => $version,
            ]);

            return null;
        }

        $pins = $this->dependentsPinning($name, $version);
        if ($pins !== []) {
            $add($blocking, self::DEPENDENT_PINS_VERSION, "Installed modules require another version of {$name} than {$version}.", [
                'dependents' => $pins,
            ]);

            return null;
        }

        return $baseline;
    }

    /**
     * A handover package must carry a Core range, or the site would never run it (RuntimePrecedence rule 5).
     *
     * @param array<array-key, mixed> $requires package `requires`
     * @param Closure $add
     * @param array   $blocking
     */
    private function checkHandoverRange(string $name, array $requires, Closure $add, array &$blocking): void
    {
        if (!in_array($name, $this->catalog->releaseNames(), true) || !$this->catalog->isHandover($name)) {
            return;
        }
        $core = ModuleVersionConstraint::constraintFor($requires, 'Core') ?? '*';
        if (trim($core) === '*' || trim($core) === '') {
            $add($blocking, self::HANDOVER_NEEDS_CORE_RANGE, "The {$name} package declares no Core version range, so the site would keep running the release copy.");
        }
    }

    /**
     * Version rules of an upgrade of the installed runtime module $name to $version.
     *
     * @param Closure $add
     * @param array   $blocking
     *
     * @return string|null the installed version when $version is newer, else null
     */
    private function checkUpgrade(string $name, string $version, Closure $add, array &$blocking): ?string
    {
        $installed = $this->catalog->installedState($name)['installed_version'];
        if ($installed === null || preg_match('/^\d+(?:\.\d+)*$/', $installed) !== 1) {
            $add($blocking, self::ALREADY_PRESENT, "Module {$name} is already installed and its version can not be read.", ['location' => 'runtime']);
            $add($blocking, self::VERSION_UNKNOWN, "The installed version of {$name} can not be read, so it can not be upgraded.");

            return null;
        }

        $order = ModuleVersionConstraint::compare($version, $installed);
        if ($order === 0) {
            $add($blocking, self::SAME_VERSION, "Module {$name} {$installed} is already installed.", ['installed_version' => $installed]);

            return null;
        }
        if ($order < 0) {
            $add($blocking, self::DOWNGRADE, "Module {$name} {$installed} is installed; {$version} is older.", [
                'installed_version' => $installed,
                'package_version' => $version,
            ]);

            return null;
        }

        $pins = $this->dependentsPinning($name, $version);
        if ($pins !== []) {
            $add($blocking, self::DEPENDENT_PINS_VERSION, "Installed modules require another version of {$name} than {$version}.", [
                'dependents' => $pins,
            ]);

            return null;
        }

        return $installed;
    }

    /**
     * Installed modules whose `requires` constraint on $name does not accept $version.
     *
     * @return list<array{module: string, constraint: string}>
     */
    private function dependentsPinning(string $name, string $version): array
    {
        $folders = [];
        foreach ($this->catalog->releaseNames() as $module) {
            $folders[$module] = $this->catalog->releasePath() . DIRECTORY_SEPARATOR . $module;
        }
        $runtimeRoot = RuntimeModules::path();
        foreach ($runtimeRoot === null ? [] : $this->catalog->runtimeNames() as $module) {
            $folders[$module] = $runtimeRoot . DIRECTORY_SEPARATOR . $module;
        }

        $pins = [];
        foreach ($folders as $module => $dir) {
            if ($module === $name) {
                continue;
            }
            $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . 'module.json');
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (!is_array($decoded) || !is_array($decoded['requires'] ?? null)) {
                continue;
            }
            // Names compared without case: `questionbank` pins the module `QuestionBank`.
            $constraint = ModuleVersionConstraint::constraintFor($decoded['requires'], $name);
            if ($constraint !== null && !ModuleVersionConstraint::satisfies($version, $constraint)) {
                $pins[] = ['module' => $module, 'constraint' => $constraint];
            }
        }

        return $pins;
    }

    /**
     * Requirements that this site does not meet.
     *
     * @param array<array-key, mixed> $requires list form (any version) or object form (name => constraint)
     *
     * @return list<array{module: string, constraint: string, reason: string, installed_version: ?string}>
     */
    private function unmetRequirements(array $requires): array
    {
        $unmet = [];
        foreach (ModuleVersionConstraint::parseRequires($requires) as $module => $constraint) {
            // `questionbank` studly-cases to `Questionbank`; look the module up under its folder name.
            $module = $this->catalog->installedName($module);
            $state = $this->catalog->installedState($module);
            $installedVersion = $state['installed_version'];

            $reason = null;
            if ($state['installed'] === 'none') {
                $reason = 'missing';
            } elseif (!$state['enabled']) {
                $reason = 'disabled';
            } elseif ($constraint !== '*' && ($installedVersion === null || !ModuleVersionConstraint::satisfies($installedVersion, $constraint))) {
                $reason = 'version';
            }

            if ($reason !== null) {
                $unmet[] = [
                    'module' => $module,
                    'constraint' => $constraint,
                    'reason' => $reason,
                    'installed_version' => $installedVersion,
                ];
            }
        }

        return $unmet;
    }

    /**
     * Warnings and the plain list of changes the install would make.
     *
     * @param array<string, mixed> $entry    hub version entry or the same facts from a signed manifest
     * @param bool                 $uploaded the package was uploaded, so nothing is downloaded
     * @param Closure              $add
     * @param array                $warnings
     * @param list<string>         $changes
     */
    private function describeChanges(string $name, string $version, array $entry, bool $uploaded, Closure $add, array &$warnings, array &$changes): void
    {
        $changes[] = $uploaded
            ? "Take a database backup when chosen, then verify the uploaded {$name} {$version}."
            : "Take a database backup when chosen, then download and verify {$name} {$version}.";
        $changes[] = "Place {$name} in the runtime modules folder and enable it.";
        $changes[] = 'Run the module migrations and refresh caches.';

        $irreversible = $entry['irreversible'] ?? false;
        if (self::isIrreversible($irreversible)) {
            $add($warnings, self::WARN_IRREVERSIBLE, 'This version has migrations that can not be rolled back. Back up first.', [
                'migrations' => is_array($irreversible) ? array_values($irreversible) : [],
                'listed' => is_array($irreversible) && $irreversible !== [],
            ]);
        }

        if ($entry['custom_code']) {
            $add($warnings, self::WARN_CUSTOM_CODE, 'This module has its own admin code; the admin is rebuilt, which takes several minutes.');
            $changes[] = 'Rebuild the admin application.';
        } else {
            $add($warnings, self::WARN_RESOURCE_ONLY, 'This module is resource-only; its menu comes from the backend and no admin rebuild is needed.');
        }
    }

    /**
     * Migration names found in enabled modules' migration folders but missing from the migration repository.
     *
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        $files = [];
        foreach ($this->migrationFolders() as $folder) {
            foreach (glob(rtrim($folder, '/') . '/*.php') ?: [] as $file) {
                $files[] = basename($file, '.php');
            }
        }

        $pending = array_values(array_diff(array_unique($files), $this->ran()));
        sort($pending);

        return $pending;
    }

    /**
     * Migration folders of the enabled modules.
     *
     * @return list<string>
     */
    private function migrationFolders(): array
    {
        if ($this->migrationPaths !== null) {
            return ($this->migrationPaths)();
        }

        $folders = [];
        foreach (ModuleFacade::allEnabled() as $module) {
            $folders[] = $module->getPath() . '/database/migrations';
        }

        return $folders;
    }

    /**
     * Migration names the repository records as run (does not use migrate:status).
     *
     * @return list<string>
     */
    private function ran(): array
    {
        if ($this->ranMigrations !== null) {
            return array_map('strval', ($this->ranMigrations)());
        }

        try {
            return array_map('strval', app('migration.repository')->getRan());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Installs run through the scheduler or a queue worker ({@see InstallRunDispatcher::schedulerRunsInstalls()}).
     * Through the scheduler (queue `sync`, or a `retry_after` not longer than the job timeout) the scheduler must
     * have run recently. A safe real queue needs nothing more.
     *
     * Fix 2026-11-25: a short `retry_after` no longer blocks with QUEUE_TIMEOUT (it blocked every live site on redis
     * with the 900 s default); it means "runs through the scheduler". QUEUE_SYNC and QUEUE_TIMEOUT are kept as
     * constants for older clients but no longer reported.
     *
     * @param Closure $add
     * @param array   $blocking
     */
    private function checkQueue(Closure $add, array &$blocking): void
    {
        if (! InstallRunDispatcher::schedulerRunsInstalls()) {
            return;
        }
        if (! app(SchedulerHeartbeat::class)->isFresh()) {
            $add($blocking, self::NO_RUNNER, 'Installs run through the scheduler on this site, and the scheduler (cron) has not run in the last few minutes, so nothing would run the install.');
        }
    }
}
