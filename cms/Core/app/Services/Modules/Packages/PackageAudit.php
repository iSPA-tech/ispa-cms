<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\Modules\ModuleEdition;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The one package rule set: every refusal check a module must pass before it may be installed at runtime.
 *
 * Used by the site's PackageBuilder on a module source tree ({@see self::source()}) and by the hub on a package it
 * extracted with SafeExtractor ({@see self::extracted()}), so both sides refuse the same packages for the same reasons.
 * The audit only reads files (manifest lint runs `cms:manifest-lint` over a temp folder of symlinks); it never executes
 * module code. Checks, in order (the first failure is thrown as a {@see PackageBuildException}):
 *  - valid module name; Core and Game* names are forbidden (FORBIDDEN_MODULE);
 *  - module.json is a JSON object whose name matches the folder (LINT); `distribution: deploy` (DEPLOY_ONLY);
 *    an `open` module requiring a non-open one (EDITION); `cms:manifest-lint` errors (LINT); a release version (VERSION);
 *  - the file tree: no symlinks (SYMLINK), no `vite.config.*` (VITE_CONFIG), no `frontend-build/` in a source tree
 *    (FRONTEND_BUILD), at most `max_files` files (LIMITS);
 *  - release notes (CHANGELOG, a warning unless `core.module_packages.require_changelog` is on);
 *  - Content/Account migrations that drop or rename a column or table (DESTRUCTIVE_MIGRATION);
 *  - composer.json: only psr-4 autoload under `Cms\<Module>\` (COMPOSER_AUTOLOAD), every required package locked
 *    (COMPOSER_REQUIRE);
 *  - admin imports: relative imports stay inside the module, packages come from the admin shell (ADMIN_IMPORT,
 *    SHARED_PACKAGES, LIMITS);
 *  - declared `frontend.entries` are packaged files (FRONTEND_BUILD);
 *  - no file above `max_file_bytes` (LIMITS); for an extracted package also at most `max_unpacked_bytes` in all.
 *
 * Source tree and extracted package differ only where the builder itself changes the tree:
 *  - source: `tests`, `node_modules`, `.git*`, `.DS_Store` and `._*` are skipped (the builder never packages them), so
 * are the module's non-runtime notes (`docs/`, `guidelines/`, `reports/`, `the module notes`, `the module notes` at the module
 *    root) and SQL dumps under `database/seeders/sql/` that no seeder loads (its PHP translation sidecars stay), so
 *    are the files module.json `package.exclude` names ({@see self::packageProblems()}), and a `frontend-build/`
 *    folder is refused (the builder writes it);
 *  - extracted: nothing is skipped (every file that would be installed is audited) and `frontend-build/` is accepted
 *    when module.json declares `frontend.entries` (the builder wrote it); the unpacked total is checked too (the
 *    builder checks it on the files it actually zips, built output included).
 * Required modules (manifest lint, edition rule) are looked up in the dependency folder: the modules folder for a
 * source tree, the site's own `cms/` (or a given folder) for an extracted package.
 */
final class PackageAudit
{
    /** Packages always allowed in admin code, even when the shared list omits them (`@/...` is allowed too). */
    public const ADMIN_ALWAYS_ALLOWED_PACKAGES = ['vue', 'vue-i18n', 'vue-router', 'ant-design-vue', 'icons'];

    /**
     * Path aliases of the admin shell (base-admin vite.config.js) that module admin code may import by exact name.
     * `@shared-data-chart-css` resolves to resources/css/data-chart.css in the workspace and the release layout.
     */
    public const ADMIN_SHELL_PATH_ALIASES = ['@shared-data-chart-css'];

    /**
     * Path alias prefixes of the admin shell that module admin code may import files under. `@core-admin/` resolves to
     * cms/Core/admin in the workspace, the release and the site's runtime admin workspace, so it is the only way a
     * runtime module reaches Core admin files (a relative path into a sibling module does not resolve once the module
     * is placed under runtime/modules/.versions/).
     */
    public const ADMIN_SHELL_PATH_PREFIXES = ['@core-admin/'];

    /**
     * Packages every admin shell has provided since runtime installs exist (the builder's list).
     * They are allowed through the shared list but never recorded in `admin_packages`, so the manifests of modules
     * that import only these keep their bytes and stay installable on sites whose reader predates the field.
     */
    public const ADMIN_BASELINE_PACKAGES = [
        'vue', 'vue-i18n', 'vue-router', 'ant-design-vue', '@ant-design/icons-vue', '@tanstack/vue-query', 'dayjs', 'icons',
    ];

    /** Shared list locations tried in order when the setting is empty: workspace checkout, then release layout. */
    public const SHARED_PACKAGES_DEFAULTS = ['../base-admin/src/config/shared-packages.json', 'admin/src/config/shared-packages.json'];

    /**
     * Modules every site depends on whose packages may never drop or rename a column or table: they are
     * upgraded online while other modules keep reading their data.
     */
    public const SCHEMA_GUARDED_MODULES = ['Content', 'Account'];

    /** Default package limits; `core.module_packages.*` overrides them. */
    public const DEFAULT_LIMITS = ['max_zip_bytes' => 52428800, 'max_unpacked_bytes' => 209715200, 'max_files' => 5000, 'max_file_bytes' => 20971520];

    private const ADMIN_EXTENSIONS = ['js', 'mjs', 'ts', 'vue'];

    /** Most bytes of CHANGELOG.md the audit reads. */
    private const CHANGELOG_READ_BYTES = 1048576;

    /** @var list<string> warnings of the last source() or extracted() run, kept when a later check refuses */
    private array $warnings = [];

    /**
     * Audit a module source tree, as PackageBuilder does before building it.
     *
     * @param string      $module      module folder name, also the package name
     * @param string      $modulesPath root folder that contains the module folders (also where required modules are)
     * @param string|null $lockPath    composer.lock to check requirements against (default base_path('composer.lock'))
     *
     * @return array{root: string, dir: string, manifest: array<string, mixed>, version: string, paths: array<int, string>, composer: array<string, mixed>, limits: array<string, mixed>, admin_packages: array<string, int>, frontend: array{entries: list<string>, styles: string}|null, warnings: list<string>}
     *
     * @throws PackageBuildException when the module is refused
     */
    public function source(string $module, string $modulesPath, ?string $lockPath = null): array
    {
        $root = rtrim($modulesPath, '/\\');

        return $this->run($module, $root, $root, $lockPath, false);
    }

    /**
     * Audit a package extracted by SafeExtractor (`<staging>/<Name>`), as the hub does before reviewing it.
     *
     * Every extracted file is audited (nothing is skipped), a `frontend-build/` folder is accepted only when
     * module.json declares `frontend.entries`, and the unpacked total is checked against `max_unpacked_bytes`.
     *
     * @param string      $moduleDir      extracted module folder; its base name is the package name
     * @param string|null $lockPath       composer.lock to check requirements against (default base_path('composer.lock'))
     * @param string|null $dependencyPath folder holding the modules the package may require (default base_path('cms'))
     *
     * @return array{root: string, dir: string, manifest: array<string, mixed>, version: string, paths: array<int, string>, composer: array<string, mixed>, limits: array<string, mixed>, admin_packages: array<string, int>, frontend: array{entries: list<string>, styles: string}|null, warnings: list<string>}
     *
     * @throws PackageBuildException when the package is refused
     */
    public function extracted(string $moduleDir, ?string $lockPath = null, ?string $dependencyPath = null): array
    {
        $moduleDir = rtrim($moduleDir, '/\\');
        return $this->run(basename($moduleDir), dirname($moduleDir), rtrim($dependencyPath ?? base_path('cms'), '/\\'), $lockPath, true);
    }

    /**
     * Warnings of the last source() or extracted() run, one plain sentence each; empty when there were none.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Find the real folder name of a module (case-insensitive) under a modules root, or null.
     */
    public static function moduleFolder(string $name, string $root): ?string
    {
        foreach (is_dir($root) ? (scandir($root) ?: []) : [] as $entry) {
            if ($entry[0] !== '.' && strcasecmp($entry, $name) === 0 && is_file($root . '/' . $entry . '/module.json')) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The checks shared by source() and extracted().
     *
     * @param string $root      folder holding the audited module folder
     * @param string $depRoot   folder holding the modules it may require
     * @param bool   $extracted whether the tree is an extracted package instead of a source tree
     *
     * @return array{root: string, dir: string, manifest: array<string, mixed>, version: string, paths: array<int, string>, composer: array<string, mixed>, limits: array<string, mixed>, admin_packages: array<string, int>, frontend: array{entries: list<string>, styles: string}|null, warnings: list<string>}
     *
     * @throws PackageBuildException when the module is refused
     */
    private function run(string $module, string $root, string $depRoot, ?string $lockPath, bool $extracted): array
    {
        $this->warnings = [];
        if (preg_match(ReleaseManifest::NAME_PATTERN, $module) !== 1) {
            throw new PackageBuildException(PackageBuildException::NOT_FOUND, 'Not a valid module name: ' . $module);
        }
        if (RuntimeModules::isForbiddenName($module)) {
            throw new PackageBuildException(PackageBuildException::FORBIDDEN_MODULE, 'Module ' . $module . ' cannot be packaged: Core and Game* modules are not installable at runtime.');
        }
        $dir = $root . '/' . $module;
        if (!is_dir($dir) || !is_file($dir . '/module.json')) {
            throw new PackageBuildException(PackageBuildException::NOT_FOUND, 'Module folder or module.json not found: ' . $dir);
        }
        if (is_link($dir)) {
            throw new PackageBuildException(PackageBuildException::SYMLINK, 'The module folder itself is a symlink: ' . $dir);
        }

        $manifest = json_decode((string) file_get_contents($dir . '/module.json'), true);
        if (!is_array($manifest) || ($manifest !== [] && array_is_list($manifest))) {
            throw new PackageBuildException(PackageBuildException::LINT, 'module.json is not a valid JSON object.');
        }
        if (isset($manifest['name']) && $manifest['name'] !== $module) {
            throw new PackageBuildException(PackageBuildException::LINT, 'module.json name does not match the folder name ' . $module . '.');
        }
        if (($manifest['distribution'] ?? null) === 'deploy') {
            $reason = is_string($manifest['distribution_reason'] ?? null) ? trim(mb_substr($manifest['distribution_reason'], 0, 300)) : '';
            throw new PackageBuildException(PackageBuildException::DEPLOY_ONLY, 'Module ' . $module . ' is deploy-only and ships with its website: ' . ($reason !== '' ? $reason : 'no reason given') . '.');
        }
        $editionProblems = ModuleEdition::dependencyViolations($module, $manifest, ModuleEdition::requireNames($manifest['requires'] ?? []), $depRoot);
        if ($editionProblems !== []) {
            throw new PackageBuildException(PackageBuildException::EDITION, implode(' ', $editionProblems));
        }
        $this->lint($module, $root, $depRoot, $manifest);

        $version = $manifest['version'] ?? null;
        if (!is_string($version) || preg_match(ReleaseManifest::VERSION_PATTERN, $version) !== 1) {
            throw new PackageBuildException(PackageBuildException::VERSION, 'module.json needs a "version" like 2026.03.0 (found ' . (is_string($version) ? $version : 'none') . ').');
        }

        $declaresFrontend = is_array($manifest['frontend'] ?? null) && is_array($manifest['frontend']['entries'] ?? null) && $manifest['frontend']['entries'] !== [];
        $paths = $this->collect($dir, '', $extracted, $extracted && $declaresFrontend);
        if (!$extracted) {
            $paths = $this->withoutPackageExcludes($module, $manifest, $paths);
        }
        $limits = array_merge(self::DEFAULT_LIMITS, (array) config('core.module_packages', []));
        if (count($paths) > (int) $limits['max_files']) {
            throw new PackageBuildException(PackageBuildException::LIMITS, 'Too many files: ' . count($paths) . ' (limit ' . $limits['max_files'] . ').');
        }

        $this->checkChangelog($module, $dir, $version);
        $this->checkDestructiveMigrations($module, $dir, $paths);
        $composer = $this->checkComposer($module, $dir, $paths, $lockPath ?? base_path('composer.lock'));
        $adminPackages = $this->checkAdminImports($dir, $paths);
        $frontend = $this->frontendDeclaration($manifest, $paths);
        $unpacked = $this->checkFileSizes($dir, $paths, $limits);
        if ($extracted && $unpacked > (int) $limits['max_unpacked_bytes']) {
            throw new PackageBuildException(PackageBuildException::LIMITS, 'Unpacked size exceeds ' . $limits['max_unpacked_bytes'] . ' bytes.');
        }

        return ['root' => $root, 'dir' => $dir, 'manifest' => $manifest, 'version' => $version, 'paths' => $paths, 'composer' => $composer, 'limits' => $limits, 'admin_packages' => $adminPackages, 'frontend' => $frontend, 'warnings' => $this->warnings];
    }

    /**
     * Problems of a module.json `package` value, one plain sentence each; empty when it is valid (2026-11-26).
     *
     * `package` is an object whose only field `exclude` lists module-relative file paths left out of packages built
     * from source (a site-specific data migration, for example). A path is relative with `/` separators: no empty,
     * `.` or `..` segment, no leading `/`, no drive letter, no backslash, and never `module.json`. cms:manifest-lint
     * reports these problems; the audit additionally refuses a path that is not a packaged file.
     *
     * @return list<string>
     */
    public static function packageProblems(mixed $package): array
    {
        if (!is_array($package) || ($package !== [] && array_is_list($package))) {
            return ["'package' must be an object"];
        }
        $problems = [];
        foreach (array_keys($package) as $field) {
            if ($field !== 'exclude') {
                $problems[] = "package has unknown field '{$field}'";
            }
        }
        $exclude = $package['exclude'] ?? [];
        if (!is_array($exclude) || !array_is_list($exclude)) {
            return [...$problems, "'package.exclude' must be a list of file paths"];
        }
        foreach ($exclude as $path) {
            if (!is_string($path) || $path === '' || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1
                || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
                $problems[] = "'package.exclude' entries must be relative file paths inside the module (found " . (is_string($path) ? "'{$path}'" : 'a non-string value') . ')';
            } elseif (strtolower($path) === 'module.json') {
                $problems[] = "'package.exclude' may not name module.json";
            }
        }

        return $problems;
    }

    /**
     * Drop the files module.json `package.exclude` names from the audited source files.
     *
     * @param array<string, mixed> $manifest
     * @param array<int, string>   $paths    audited files
     *
     * @return array<int, string>
     *
     * @throws PackageBuildException LINT when the value is malformed or names a file the package would not hold
     */
    private function withoutPackageExcludes(string $module, array $manifest, array $paths): array
    {
        if (!array_key_exists('package', $manifest)) {
            return $paths;
        }
        $problems = self::packageProblems($manifest['package']);
        if ($problems !== []) {
            throw new PackageBuildException(PackageBuildException::LINT, 'Manifest lint failed: ' . implode('; ', $problems));
        }
        $exclude = array_values(array_unique($manifest['package']['exclude'] ?? []));
        foreach ($exclude as $path) {
            if (!in_array($path, $paths, true)) {
                throw new PackageBuildException(PackageBuildException::LINT, 'package.exclude of ' . $module . ' names ' . $path . ', which is not a packaged file of the module.');
            }
        }

        return array_values(array_diff($paths, $exclude));
    }

    /**
     * Refuse any audited file larger than `max_file_bytes` and return the total size of the audited files.
     *
     * @param array<int, string>   $paths  audited files
     * @param array<string, mixed> $limits package limits
     *
     * @throws PackageBuildException LIMITS naming the first oversized file
     */
    private function checkFileSizes(string $dir, array $paths, array $limits): int
    {
        $total = 0;
        foreach ($paths as $relative) {
            $size = (int) filesize($dir . '/' . $relative);
            if ($size > (int) $limits['max_file_bytes']) {
                throw new PackageBuildException(PackageBuildException::LIMITS, 'File too large: ' . $relative . ' (' . $size . ' bytes).');
            }
            $total += $size;
        }

        return $total;
    }

    /**
     * Release-notes check: a warning (or a refusal when `core.module_packages.require_changelog` is on) when
     * CHANGELOG.md is missing or has no `## <version>` section for the version being packaged.
     *
     * @throws PackageBuildException CHANGELOG when the switch is on and the section is missing
     */
    private function checkChangelog(string $module, string $dir, string $version): void
    {
        $file = $dir . '/CHANGELOG.md';
        $problem = null;
        if (!is_file($file) || is_link($file)) {
            $problem = 'Module ' . $module . ' has no CHANGELOG.md, so the release notes of ' . $version . ' will be empty.';
        } else {
            $text = (string) file_get_contents($file, false, null, 0, self::CHANGELOG_READ_BYTES);
            if (PackageBuilder::changelogSection($text, $version) === null) {
                $problem = 'CHANGELOG.md of ' . $module . ' has no "## ' . $version . '" section, so the release notes of ' . $version . ' will be empty.';
            }
        }
        if ($problem === null) {
            return;
        }
        if ((bool) config('core.module_packages.require_changelog', false)) {
            throw new PackageBuildException(PackageBuildException::CHANGELOG, $problem);
        }
        $this->warnings[] = $problem;
    }

    /**
     * Refuse a schema-guarded module ({@see self::SCHEMA_GUARDED_MODULES}) whose migrations drop or rename a column or
     * table in `up()` ({@see DestructiveMigrationScanner}). Owner-approved exceptions are listed by migration name in
     * `core.module_packages.destructive_migrations_allowed` as `<Module>/<migration name>` (empty by default).
     *
     * @param array<int, string> $paths audited files
     *
     * @throws PackageBuildException DESTRUCTIVE_MIGRATION naming the first offending migrations
     */
    private function checkDestructiveMigrations(string $module, string $dir, array $paths): void
    {
        if (! in_array($module, self::SCHEMA_GUARDED_MODULES, true)) {
            return;
        }
        $allowed = array_map('strval', (array) config('core.module_packages.destructive_migrations_allowed', []));

        $refused = [];
        foreach ($paths as $relative) {
            if (preg_match('#^database/migrations/([^/]+)\.php$#', $relative, $m) !== 1 || in_array($module . '/' . $m[1], $allowed, true)) {
                continue;
            }
            $found = DestructiveMigrationScanner::scan($dir . '/' . $relative);
            if ($found !== []) {
                $refused[] = $m[1] . ' (' . implode(', ', $found) . ')';
            }
        }

        if ($refused !== []) {
            throw new PackageBuildException(
                PackageBuildException::DESTRUCTIVE_MIGRATION,
                'Module ' . $module . ' is upgraded online on every site, so its migrations may not drop or rename columns or tables: '
                . implode('; ', array_slice($refused, 0, 5)) . '. Add a new column or table instead, or ask the owner to allow the migration.'
            );
        }
    }

    /**
     * The linted `frontend` declaration, or null when the module declares no entries.
     *
     * Every entry must be an audited (packaged) file. `styles` defaults to "none" when the module does not state it.
     *
     * @param array<string, mixed> $manifest
     * @param array<int, string>   $paths    audited files
     *
     * @return array{entries: list<string>, styles: string}|null
     *
     * @throws PackageBuildException FRONTEND_BUILD when a declared entry is not a packaged file
     */
    private function frontendDeclaration(array $manifest, array $paths): ?array
    {
        $frontend = is_array($manifest['frontend'] ?? null) ? $manifest['frontend'] : [];
        $entries = is_array($frontend['entries'] ?? null) ? array_values($frontend['entries']) : [];
        if ($entries === []) {
            return null;
        }
        foreach ($entries as $entry) {
            if (!is_string($entry) || !in_array($entry, $paths, true)) {
                throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'frontend.entries names ' . (is_string($entry) ? $entry : 'a non-string value') . ', which is not a packaged file of the module.');
            }
        }
        $styles = is_string($frontend['styles'] ?? null) ? $frontend['styles'] : 'none';

        return ['entries' => array_values(array_unique($entries)), 'styles' => $styles];
    }

    /**
     * Run cms:manifest-lint over a temp folder holding the module and the modules it requires (via symlinks).
     *
     * @param string               $root    folder holding the audited module
     * @param string               $depRoot folder holding the required modules
     * @param array<string, mixed> $manifest
     *
     * @throws PackageBuildException LINT listing the lint errors of the module
     */
    private function lint(string $module, string $root, string $depRoot, array $manifest): void
    {
        $tmp = sys_get_temp_dir() . '/pkgbuild-lint-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        try {
            symlink($root . '/' . $module, $tmp . '/' . $module);
            $required = $manifest['requires'] ?? [];
            foreach (is_array($required) ? $required : [] as $key => $value) {
                $name = is_int($key) ? $value : $key;
                $real = is_string($name) ? self::moduleFolder($name, $depRoot) : null;
                if ($real !== null && $real !== $module && !file_exists($tmp . '/' . $real)) {
                    symlink($depRoot . '/' . $real, $tmp . '/' . $real);
                }
            }
            $buffer = new BufferedOutput();
            Artisan::call('cms:manifest-lint', ['--path' => $tmp], $buffer);
            $errors = [];
            $inBlock = false;
            foreach (preg_split('/\R/', $buffer->fetch()) ?: [] as $line) {
                if ($line === $module) {
                    $inBlock = true;
                    continue;
                }
                if ($inBlock && !str_starts_with($line, '  ')) {
                    break;
                }
                if ($inBlock && preg_match('/^  error\s+(.*)$/', $line, $m) === 1) {
                    $errors[] = $m[1];
                }
            }
            if ($errors !== []) {
                throw new PackageBuildException(PackageBuildException::LINT, 'Manifest lint failed: ' . implode('; ', $errors));
            }
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $link) {
                @unlink($link);
            }
            @rmdir($tmp);
        }
    }

    /**
     * List audited files (relative, sorted), refusing symlinks, vite configs and, unless allowed, a top-level
     * frontend-build folder. A source tree skips the names the builder never packages; an extracted package skips none.
     *
     * @param bool $extracted    whether nothing is skipped
     * @param bool $allowBuilt   whether a top-level frontend-build folder is accepted
     *
     * @return array<int, string>
     *
     * @throws PackageBuildException FRONTEND_BUILD, SYMLINK, VITE_CONFIG or IO
     */
    private function collect(string $dir, string $prefix, bool $extracted, bool $allowBuilt): array
    {
        $names = array_values(array_diff(scandir($dir . ($prefix === '' ? '' : '/' . $prefix)) ?: [], ['.', '..']));
        sort($names, SORT_STRING);
        $out = [];
        foreach ($names as $name) {
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;
            if (!$extracted && self::excludedPathFromBuild($relative)) {
                continue;
            }
            $full = $dir . '/' . $relative;
            if ($relative === FrontendBuilder::BUILD_DIR && !$allowBuilt) {
                throw new PackageBuildException(PackageBuildException::FRONTEND_BUILD, 'Module contains ' . $relative . '/: the package builder writes that folder from frontend.entries; remove it from the source.');
            }
            if (is_link($full)) {
                throw new PackageBuildException(PackageBuildException::SYMLINK, 'Symlinks are not allowed in a package: ' . $relative);
            }
            if (is_dir($full)) {
                array_push($out, ...$this->collect($dir, $relative, $extracted, $allowBuilt));
                continue;
            }
            if (preg_match('/^vite\.config\.[cm]?[jt]s$/', $name) === 1) {
                throw new PackageBuildException(PackageBuildException::VITE_CONFIG, 'Module contains ' . $relative . ': a module with its own Vite build needs a deploy, not a runtime install.');
            }
            if (!is_file($full)) {
                throw new PackageBuildException(PackageBuildException::IO, 'Not a regular file: ' . $relative);
            }
            $out[] = $relative;
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Whether a file or folder name is excluded from packages built from source (tests, node_modules, .git*,
     * .DS_Store, ._*); PackageStaticScan skips the same names when it scans a source tree.
     */
    public static function excludedFromBuild(string $name): bool
    {
        return $name === 'tests' || $name === 'node_modules' || $name === '.DS_Store'
            || str_starts_with($name, '.git') || str_starts_with($name, '._');
    }

    /** Module-root entries that are notes for people and agents, never read at runtime. */
    public const NON_RUNTIME_ROOT_ENTRIES = ['docs', 'guidelines', 'reports', 'AGENTS.md', 'CLAUDE.md'];

    /**
     * Whether a path relative to the module folder is excluded from packages built from source: any segment named by
     * excludedFromBuild(), a NON_RUNTIME_ROOT_ENTRIES entry at the module root, or a `.sql` dump directly under
     * `database/seeders/sql/` (seeders read the PHP sidecars there, never the dumps). PackageStaticScan skips the same.
     */
    public static function excludedPathFromBuild(string $relative): bool
    {
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if (self::excludedFromBuild($segment)) {
                return true;
            }
        }
        if (in_array($segments[0], self::NON_RUNTIME_ROOT_ENTRIES, true)) {
            return true;
        }

        return count($segments) === 4 && $segments[0] === 'database' && $segments[1] === 'seeders' && $segments[2] === 'sql'
            && str_ends_with(strtolower($segments[3]), '.sql');
    }

    /**
     * Validate composer.json (requirements and autoload) and return its decoded content.
     *
     * Composer rule: a "require" entry other than "php" and "ext-*" is satisfied when the package name appears in
     * composer.lock "packages" or "packages-dev"; version constraints are not compared.
     *
     * @param array<int, string> $paths
     *
     * @return array<string, mixed>
     *
     * @throws PackageBuildException COMPOSER_AUTOLOAD or COMPOSER_REQUIRE
     */
    private function checkComposer(string $module, string $dir, array $paths, string $lockPath): array
    {
        if (!in_array('composer.json', $paths, true)) {
            return [];
        }
        $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        if (!is_array($composer)) {
            throw new PackageBuildException(PackageBuildException::COMPOSER_AUTOLOAD, 'composer.json is not valid JSON.');
        }

        $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        foreach (['files', 'classmap', 'psr-0', 'exclude-from-classmap'] as $kind) {
            if (!empty($autoload[$kind])) {
                throw new PackageBuildException(PackageBuildException::COMPOSER_AUTOLOAD, 'composer.json autoload "' . $kind . '" is not allowed; only psr-4 under Cms\\' . $module . '\\.');
            }
        }
        $prefix = 'Cms\\' . $module . '\\';
        foreach (array_keys(is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : []) as $namespace) {
            if (!str_starts_with((string) $namespace, $prefix)) {
                throw new PackageBuildException(PackageBuildException::COMPOSER_AUTOLOAD, 'composer.json psr-4 namespace ' . $namespace . ' is outside ' . $prefix . '.');
            }
        }

        $unsatisfied = [];
        $locked = null;
        foreach (array_keys(is_array($composer['require'] ?? null) ? $composer['require'] : []) as $package) {
            $package = strtolower((string) $package);
            if ($package === 'php' || str_starts_with($package, 'ext-')) {
                continue;
            }
            $locked ??= $this->lockedPackages($lockPath);
            if (!isset($locked[$package])) {
                $unsatisfied[] = $package;
            }
        }
        if ($unsatisfied !== []) {
            throw new PackageBuildException(PackageBuildException::COMPOSER_REQUIRE, 'composer.json requires packages missing from composer.lock: ' . implode(', ', $unsatisfied) . '.');
        }

        return $composer;
    }

    /**
     * Lower-cased package names present in composer.lock.
     *
     * @return array<string, true>
     *
     * @throws PackageBuildException COMPOSER_REQUIRE when the lock file is missing or invalid
     */
    private function lockedPackages(string $lockPath): array
    {
        $lock = is_file($lockPath) ? json_decode((string) file_get_contents($lockPath), true) : null;
        if (!is_array($lock)) {
            throw new PackageBuildException(PackageBuildException::COMPOSER_REQUIRE, 'composer.lock not found or invalid: ' . $lockPath);
        }
        $names = [];
        foreach (['packages', 'packages-dev'] as $group) {
            foreach (is_array($lock[$group] ?? null) ? $lock[$group] : [] as $package) {
                if (is_array($package) && is_string($package['name'] ?? null)) {
                    $names[strtolower($package['name'])] = true;
                }
            }
        }

        return $names;
    }

    /**
     * Refuse admin code that imports anything the admin shell cannot resolve, and return the shared packages it uses.
     *
     * Allowed specifiers: relative (./ ../) that stay inside the module folder, "@/...", ADMIN_SHELL_PATH_ALIASES,
     * ADMIN_SHELL_PATH_PREFIXES, ADMIN_ALWAYS_ALLOWED_PACKAGES and the packages of the shared admin package list (exact
     * name or name/subpath). Block comments and full-line // comments are ignored; a dynamic import() with a non-literal
     * specifier is refused as unverifiable. The list is read only when the module has admin code, and a missing or
     * malformed list refuses the package (no silent fallback).
     *
     * @param array<int, string> $paths
     *
     * @return array<string, int> used shared packages outside ADMIN_BASELINE_PACKAGES => major version, sorted by name
     *
     * @throws PackageBuildException ADMIN_IMPORT, SHARED_PACKAGES or LIMITS
     */
    private function checkAdminImports(string $dir, array $paths): array
    {
        $shared = null;
        $used = [];
        foreach ($paths as $relative) {
            if (!str_starts_with($relative, 'admin/') || !in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::ADMIN_EXTENSIONS, true)) {
                continue;
            }
            $shared ??= $this->sharedAdminPackages();
            $code = (string) file_get_contents($dir . '/' . $relative);
            $code = (string) preg_replace('~/\*.*?\*/~s', ' ', $code);
            $code = (string) preg_replace('~^\s*//.*$~m', '', $code);

            $specifiers = [];
            $patterns = [
                '/\bimport\s+(?:[^\'"();`]*?\s+from\s+)?[\'"]([^\'"]+)[\'"]/',
                '/\bexport\s+(?:\*(?:\s+as\s+\w+)?|\{[^}]*\})\s+from\s+[\'"]([^\'"]+)[\'"]/',
                '/\bimport\s*\(\s*[\'"]([^\'"]+)[\'"]/',
                '/\bimport\s*\(\s*`([^`$]*)/',
                '/\brequire\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            ];
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $code, $m) > 0) {
                    array_push($specifiers, ...$m[1]);
                }
            }
            if (preg_match('/\bimport\s*\(\s*(?![\'"`\s])/', $code) === 1) {
                throw new PackageBuildException(PackageBuildException::ADMIN_IMPORT, 'Admin file ' . $relative . ' uses a dynamic import() with a non-literal specifier that cannot be verified.');
            }
            foreach ($specifiers as $specifier) {
                if ($specifier === '.' || $specifier === '..' || str_starts_with($specifier, './') || str_starts_with($specifier, '../')) {
                    if (!$this->staysInModule($relative, $specifier)) {
                        throw new PackageBuildException(PackageBuildException::ADMIN_IMPORT, 'Admin file ' . $relative . ' imports "' . $specifier . '", which leaves the module folder and does not resolve once the module is installed at runtime.');
                    }
                    continue;
                }
                if (str_starts_with($specifier, '@/') || in_array($specifier, self::ADMIN_SHELL_PATH_ALIASES, true)) {
                    continue;
                }
                foreach (self::ADMIN_SHELL_PATH_PREFIXES as $prefix) {
                    if (str_starts_with($specifier, $prefix) && strlen($specifier) > strlen($prefix)) {
                        continue 2;
                    }
                }
                $package = $this->packageName($specifier);
                if (in_array($package, self::ADMIN_ALWAYS_ALLOWED_PACKAGES, true) && !isset($shared[$package])) {
                    continue;
                }
                if (!isset($shared[$package])) {
                    throw new PackageBuildException(PackageBuildException::ADMIN_IMPORT, 'Admin file ' . $relative . ' imports "' . $specifier . '", which the admin shell cannot resolve.');
                }
                if (!in_array($package, self::ADMIN_BASELINE_PACKAGES, true)) {
                    $used[$package] = $shared[$package];
                }
            }
        }
        ksort($used, SORT_STRING);
        if (count($used) > ReleaseManifest::MAX_ADMIN_PACKAGES) {
            throw new PackageBuildException(PackageBuildException::LIMITS, 'Admin code uses ' . count($used) . ' shared packages; a manifest may list at most ' . ReleaseManifest::MAX_ADMIN_PACKAGES . '.');
        }

        return $used;
    }

    /**
     * Whether a relative import specifier, resolved from the importing file, stays inside the module folder.
     *
     * @param string $relative  importing file, relative to the module folder (for example "admin/pages/a.vue")
     * @param string $specifier relative specifier ("./x", "../y", "." or "..")
     */
    private function staysInModule(string $relative, string $specifier): bool
    {
        $segments = explode('/', dirname($relative));
        foreach (explode('/', $specifier) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($segments === [] || $segments === ['.']) {
                    return false;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $part;
        }

        return true;
    }

    /**
     * The package an import specifier names: "@scope/name" for scoped specifiers, else its first path segment.
     */
    private function packageName(string $specifier): string
    {
        $segments = explode('/', $specifier);

        return str_starts_with($specifier, '@') && count($segments) > 1 ? $segments[0] . '/' . $segments[1] : $segments[0];
    }

    /**
     * Read the admin shell's shared package list: package name => major version.
     *
     * Source: `core.module_packages.shared_admin_packages_file`, else the first existing SHARED_PACKAGES_DEFAULTS
     * path under base_path(). The file must be `{"format": 1, "packages": {"<npm name>": {"major": <int>, ...}}}`.
     *
     * @return array<string, int>
     *
     * @throws PackageBuildException SHARED_PACKAGES when the list is missing or malformed
     */
    private function sharedAdminPackages(): array
    {
        $configured = config('core.module_packages.shared_admin_packages_file');
        $path = is_string($configured) && $configured !== '' ? $configured : null;
        if ($path === null) {
            foreach (self::SHARED_PACKAGES_DEFAULTS as $candidate) {
                if (is_file(base_path($candidate))) {
                    $path = base_path($candidate);
                    break;
                }
            }
        }
        if ($path === null || !is_file($path)) {
            throw new PackageBuildException(PackageBuildException::SHARED_PACKAGES, 'The admin shell shared package list was not found (' . ($path ?? implode(', ', self::SHARED_PACKAGES_DEFAULTS)) . '); set core.module_packages.shared_admin_packages_file.');
        }
        $list = json_decode((string) file_get_contents($path), true);
        if (!is_array($list) || ($list['format'] ?? null) !== 1 || !is_array($list['packages'] ?? null) || $list['packages'] === [] || array_is_list($list['packages'])) {
            throw new PackageBuildException(PackageBuildException::SHARED_PACKAGES, 'The admin shell shared package list ' . $path . ' is not a format 1 object of packages.');
        }
        $out = [];
        foreach ($list['packages'] as $name => $entry) {
            $name = (string) $name;
            $major = is_array($entry) ? ($entry['major'] ?? null) : null;
            if (preg_match(ReleaseManifest::NPM_NAME_PATTERN, $name) !== 1 || !is_int($major) || $major < 0) {
                throw new PackageBuildException(PackageBuildException::SHARED_PACKAGES, 'The admin shell shared package list ' . $path . ' has an invalid entry: ' . mb_substr($name, 0, 80) . '.');
            }
            $out[$name] = $major;
        }

        return $out;
    }
}
