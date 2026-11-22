<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\ConfigPackage\ConfigExportRegistry;
use Cms\Core\Services\ConfigPackage\ConfigTableRegistry;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\RepositoryInterface;

/**
 * Lints every module.json under a modules directory against the "manifest v2" contract.
 *
 * Data-scope checks run in two passes: pass one lints each manifest alone and collects its scope dimensions, scope
 * references and requires; pass two validates permission scopes against own/required-module dimensions and compares
 * declared dimensions with the runtime DimensionRegistry. A module counts as enabled when the nwidart module
 * repository (RepositoryInterface, resolved from the container) finds it by manifest folder name and reports it
 * enabled; tests bind a fake repository to make fixture modules enabled. Registered dimensions of modules outside the
 * scanned path are ignored.
 */
class ManifestLintCommand extends Command
{
    protected $signature = 'cms:manifest-lint {--path= : Directory whose subdirectories contain module.json (default: base_path(\'cms\'))}';

    protected $description = 'Check every cms/*/module.json against manifest v2 (fields, semver, requires, migrations, permissions).';

    private const ALLOWED_FIELDS = [
        'name', 'alias', 'description', 'keywords', 'priority', 'providers', 'aliases', 'files', 'requires',
        'authors', 'active', 'order',
        'version', 'admin', 'migrations', 'permissions', 'scope_dimensions', 'config_export',
        'frontend', 'install', 'distribution', 'distribution_reason', 'edition', 'uninstall',
    ];

    private const SEMVER_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';

    /** @var array<string, string> permission key => first module that declared it */
    private array $permissionOwners = [];

    /** @var array<int, string> lower-cased names of scanned modules */
    private array $moduleNames = [];

    /** @var array<string, string> scope dimension name => first module that declared it */
    private array $dimensionOwners = [];

    /** @var array<string, array<int, string>> lower-cased module => declared dimension names */
    private array $dimensionsByModule = [];

    /** @var array<string, array<int, string>> lower-cased module => lower-cased required module names */
    private array $requiresByModule = [];

    /** @var array<string, array<int, array{key: string, scope: mixed}>> module => permission scope entries to resolve */
    private array $scopeRefs = [];

    /**
     * Lint all manifests and print a grouped report.
     *
     * @return int 1 when any error was found, otherwise 0.
     */
    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: base_path('cms'));
        $dirs = glob(rtrim($path, '/\\').'/*/module.json') ?: [];
        sort($dirs);

        $manifests = [];
        foreach ($dirs as $file) {
            $dir = dirname($file);
            $manifests[basename($dir)] = ['dir' => $dir, 'file' => $file];
            $this->moduleNames[] = strtolower(basename($dir));
        }

        $registry = $this->resolveRegistry();
        $results = [];

        foreach ($manifests as $module => $info) {
            $results[$module] = $this->lintModule($module, $info['dir'], $info['file'], $registry);
        }

        $dimensionRegistry = $this->resolveDimensionRegistry();
        foreach ($manifests as $module => $info) {
            array_push($results[$module][0], ...$this->lintScopeReferences($module));
            if ($dimensionRegistry !== null) {
                array_push($results[$module][0], ...$this->lintRuntimeDimensions($module, $dimensionRegistry));
            }
        }

        $errors = 0;
        $warnings = 0;

        foreach ($results as $module => [$moduleErrors, $moduleWarnings]) {
            $this->line($module);
            foreach ($moduleErrors as $message) {
                $this->line('  <fg=red>error</>   '.$message);
            }
            foreach ($moduleWarnings as $message) {
                $this->line('  <fg=yellow>warning</> '.$message);
            }
            if ($moduleErrors === [] && $moduleWarnings === []) {
                $this->line('  ok');
            }
            $errors += count($moduleErrors);
            $warnings += count($moduleWarnings);
        }

        if ($registry === null) {
            $this->line('Note: resource check skipped (Cms\Core\Admin\Resources\ResourceRegistry not available).');
        }

        if ($dimensionRegistry === null) {
            $this->line('Note: scope dimension runtime check skipped (Cms\Core\Services\DataScope\DimensionRegistry not available).');
        }

        $this->line(sprintf('%d modules, %d errors, %d warnings', count($manifests), $errors, $warnings));

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Lint one manifest.
     *
     * @return array{0: array<int, string>, 1: array<int, string>} Errors and warnings.
     */
    private function lintModule(string $module, string $dir, string $file, ?object $registry): array
    {
        $errors = [];
        $warnings = [];

        $manifest = json_decode((string) file_get_contents($file), true);
        if (! is_array($manifest) || array_is_list($manifest) && $manifest !== []) {
            return [['module.json is not valid JSON (or not an object): '.json_last_error_msg()], []];
        }

        foreach (array_keys($manifest) as $key) {
            if (! in_array($key, self::ALLOWED_FIELDS, true)) {
                $errors[] = "unknown top-level field '{$key}'";
            }
        }

        if (! array_key_exists('version', $manifest)) {
            $warnings[] = "missing 'version'";
        } elseif (! $this->isSemver($manifest['version'])) {
            $errors[] = "'version' is not valid semver";
        }

        if (array_key_exists('requires', $manifest)) {
            $this->lintRequires($manifest['requires'], $errors);
            $this->requiresByModule[strtolower($module)] = $this->requiredNames($manifest['requires']);
        }

        if (array_key_exists('scope_dimensions', $manifest)) {
            $this->lintScopeDimensions($module, $manifest['scope_dimensions'], $errors);
        }

        if ($this->declaresDataScope($manifest)) {
            array_push($errors, ...$this->lintDroppedDataScope($module, $dir));
        }

        if (array_key_exists('config_export', $manifest)) {
            $this->lintConfigExport($manifest['config_export'], $errors);
        }

        $this->lintPackaging($module, $manifest, $errors);

        $admin = $manifest['admin'] ?? null;
        if ($admin !== null) {
            if (! is_array($admin)) {
                $errors[] = "'admin' must be an object";
            } else {
                if (isset($admin['min_shell']) && ! $this->isSemver($admin['min_shell'])) {
                    $errors[] = "'admin.min_shell' is not valid semver";
                }
                $this->lintNavigation($admin['navigation'] ?? [], $registry, $errors);
            }
        }

        foreach ((array) ($manifest['migrations']['irreversible'] ?? []) as $name) {
            $base = is_string($name) ? preg_replace('/\.php$/', '', $name) : '';
            if ($base === '' || ! is_file($dir.'/database/migrations/'.$base.'.php')) {
                $errors[] = "migrations.irreversible names a missing file: ".(is_string($name) ? $name : json_encode($name));
            }
        }

        foreach ((array) ($manifest['permissions'] ?? []) as $permission) {
            $key = is_array($permission) ? ($permission['key'] ?? null) : null;
            if (! is_string($key) || $key === '') {
                $errors[] = "permission entry without a 'key'";
                continue;
            }
            if (isset($this->permissionOwners[$key])) {
                $errors[] = "duplicate permission key '{$key}' (also declared by {$this->permissionOwners[$key]})";
            } else {
                $this->permissionOwners[$key] = $module;
            }
            if (array_key_exists('scopes', $permission)) {
                if (! is_array($permission['scopes']) || ! array_is_list($permission['scopes'])
                    || array_filter($permission['scopes'], static fn ($scope) => ! is_string($scope)) !== []) {
                    $errors[] = "permission '{$key}' scopes must be a list of strings";
                } else {
                    foreach ($permission['scopes'] as $scope) {
                        $this->scopeRefs[$module][] = ['key' => $key, 'scope' => $scope];
                    }
                }
            }
        }

        return [$errors, $warnings];
    }

    /**
     * Validate the optional online-distribution keys: "frontend", "install" and "distribution" (+ "distribution_reason").
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<int, string>  $errors
     */
    private function lintPackaging(string $module, array $manifest, array &$errors): void
    {
        $distribution = $manifest['distribution'] ?? null;

        if (array_key_exists('distribution', $manifest) && ! in_array($distribution, ['online', 'deploy'], true)) {
            $errors[] = "'distribution' must be online or deploy";
        }

        if (array_key_exists('edition', $manifest) && ! in_array($manifest['edition'], \Cms\Core\Services\Modules\ModuleEdition::DECLARABLE, true)) {
            $errors[] = "'edition' must be one of ".implode(', ', \Cms\Core\Services\Modules\ModuleEdition::DECLARABLE);
        }

        $reason = $manifest['distribution_reason'] ?? null;
        if ($distribution === 'deploy' && (! is_string($reason) || trim($reason) === '')) {
            $errors[] = "distribution 'deploy' requires a non-empty 'distribution_reason'";
        }
        if (array_key_exists('distribution_reason', $manifest) && $distribution !== 'deploy') {
            $errors[] = "'distribution_reason' is only allowed with distribution 'deploy'";
        }

        if (array_key_exists('frontend', $manifest)) {
            $this->lintFrontend($manifest['frontend'], $distribution, $errors);
        }

        if (array_key_exists('install', $manifest)) {
            $this->lintInstall($module, $manifest['install'], $errors);
        }

        if (array_key_exists('uninstall', $manifest)) {
            $this->lintUninstall($manifest['uninstall'], $errors);
        }
    }

    /**
     * Validate "uninstall": {tables?: droppable table names, refuse_while_rows?: table names whose rows refuse
     * any online uninstall}; each a list of unique, safe table names.
     *
     * @param  array<int, string>  $errors
     */
    private function lintUninstall(mixed $uninstall, array &$errors): void
    {
        if (! is_array($uninstall) || ($uninstall !== [] && array_is_list($uninstall))) {
            $errors[] = "'uninstall' must be an object";

            return;
        }

        foreach (array_keys($uninstall) as $field) {
            if (! in_array($field, ['tables', 'refuse_while_rows'], true)) {
                $errors[] = "uninstall has unknown field '{$field}'";
            }
        }

        foreach (['tables', 'refuse_while_rows'] as $field) {
            if (! array_key_exists($field, $uninstall)) {
                continue;
            }
            $tables = $uninstall[$field];
            if (! is_array($tables) || ($tables !== [] && ! array_is_list($tables)) || count($tables) > ReleaseManifest::MAX_UNINSTALL_TABLES) {
                $errors[] = "uninstall.{$field} must be a list of at most ".ReleaseManifest::MAX_UNINSTALL_TABLES.' table names';

                continue;
            }
            $seen = [];
            foreach ($tables as $table) {
                if (! is_string($table) || preg_match(ReleaseManifest::TABLE_NAME_PATTERN, $table) !== 1 || isset($seen[$table])) {
                    $shown = is_string($table) ? $table : json_encode($table);
                    $errors[] = "uninstall.{$field} entry '{$shown}' must be a unique table name (letters, digits, underscore)";

                    continue;
                }
                $seen[$table] = true;
            }
        }
    }

    /**
     * Validate "frontend": {entries: relative paths under resources/, styles?: module|project-theme|none}.
     *
     * @param  array<int, string>  $errors
     */
    private function lintFrontend(mixed $frontend, mixed $distribution, array &$errors): void
    {
        if (! is_array($frontend) || ($frontend !== [] && array_is_list($frontend))) {
            $errors[] = "'frontend' must be an object";

            return;
        }

        foreach (array_keys($frontend) as $field) {
            if (! in_array($field, ['entries', 'styles'], true)) {
                $errors[] = "frontend has unknown field '{$field}'";
            }
        }

        if (! isset($frontend['entries']) || ! is_array($frontend['entries']) || ! array_is_list($frontend['entries'])) {
            $errors[] = 'frontend.entries must be a list';
        } else {
            foreach ($frontend['entries'] as $entry) {
                if (! $this->isValidFrontendEntry($entry)) {
                    $shown = is_string($entry) ? $entry : json_encode($entry);
                    $errors[] = "frontend.entries '{$shown}' must be a relative buildable path under resources/ without '..'";
                }
            }
        }

        if (array_key_exists('styles', $frontend)) {
            if (! in_array($frontend['styles'], ['module', 'project-theme', 'none'], true)) {
                $errors[] = 'frontend.styles must be module, project-theme or none';
            } elseif ($frontend['styles'] === 'project-theme' && $distribution !== 'deploy') {
                $errors[] = "frontend.styles 'project-theme' requires distribution 'deploy'";
            }
        }
    }

    /**
     * Whether a frontend entry is a relative, buildable file path under resources/ with no parent segments.
     */
    private function isValidFrontendEntry(mixed $entry): bool
    {
        if (! is_string($entry) || $entry === '' || str_contains($entry, '\\') || str_contains($entry, "\0")) {
            return false;
        }

        if (! str_starts_with($entry, 'resources/') || str_contains($entry, '*')) {
            return false;
        }

        foreach (explode('/', $entry) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return preg_match('/\.(css|js|jsx|ts|tsx|vue|scss|sass|less|styl)$/i', $entry) === 1;
    }

    /**
     * Validate "install": {artisan: list of "cms:<module-kebab>:<name>" commands without arguments}.
     *
     * @param  array<int, string>  $errors
     */
    private function lintInstall(string $module, mixed $install, array &$errors): void
    {
        if (! is_array($install) || ($install !== [] && array_is_list($install))) {
            $errors[] = "'install' must be an object";

            return;
        }

        foreach (array_keys($install) as $field) {
            if ($field !== 'artisan') {
                $errors[] = "install has unknown field '{$field}'";
            }
        }

        if (! array_key_exists('artisan', $install)) {
            return;
        }

        if (! is_array($install['artisan']) || ! array_is_list($install['artisan'])) {
            $errors[] = 'install.artisan must be a list';

            return;
        }

        $prefix = 'cms:'.Str::kebab($module).':';
        foreach ($install['artisan'] as $command) {
            $valid = is_string($command)
                && str_starts_with($command, $prefix)
                && preg_match('/^[a-z0-9][a-z0-9:_-]*$/', substr($command, strlen($prefix))) === 1;
            if (! $valid) {
                $shown = is_string($command) ? $command : json_encode($command);
                $errors[] = "install.artisan '{$shown}' must be a command named {$prefix}<name> without arguments";
            }
        }
    }

    /**
     * Validate "config_export": an object with optional "settings" (valid patterns) and "tables" (registered tables).
     *
     * @param  array<int, string>  $errors
     */
    private function lintConfigExport(mixed $config, array &$errors): void
    {
        if (! is_array($config) || ($config !== [] && array_is_list($config))) {
            $errors[] = "'config_export' must be an object";

            return;
        }

        foreach (array_keys($config) as $field) {
            if (! in_array($field, ['settings', 'tables'], true)) {
                $errors[] = "config_export has unknown field '{$field}'";
            }
        }

        foreach (['settings', 'tables'] as $field) {
            if (! array_key_exists($field, $config)) {
                continue;
            }

            $list = $config[$field];
            if (! is_array($list) || ! array_is_list($list) || array_filter($list, static fn ($entry) => ! is_string($entry)) !== []) {
                $errors[] = "config_export.{$field} must be a list of strings";

                continue;
            }

            foreach ($list as $entry) {
                if ($field === 'settings' && ! ConfigExportRegistry::isValidPattern($entry)) {
                    $errors[] = "config_export.settings pattern '{$entry}' is malformed (expected 'group.*' or an exact key)";
                } elseif ($field === 'tables' && ! $this->isConfigTableRegistered($entry)) {
                    $errors[] = "config_export.tables names unregistered table '{$entry}' (register it in ConfigTableRegistry)";
                }
            }
        }
    }

    /**
     * Whether a table is registered in the ConfigTableRegistry (false when the registry cannot be resolved).
     */
    protected function isConfigTableRegistered(string $table): bool
    {
        try {
            return app(ConfigTableRegistry::class)->has($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the manifest declares data scope: any permission "scopes" entry or a non-empty "scope_dimensions".
     *
     * @param  array<string, mixed>  $manifest
     */
    private function declaresDataScope(array $manifest): bool
    {
        if (! empty($manifest['scope_dimensions'])) {
            return true;
        }

        foreach ((array) ($manifest['permissions'] ?? []) as $permission) {
            if (is_array($permission) && ! empty($permission['scopes'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text-scan the module's app/ PHP files for calls that drop the "data_scope" global scope.
     *
     * Flags withoutGlobalScopes() with no or an empty argument, withoutGlobalScope('data_scope') and the
     * DataScope::SCOPE_ID form, and withoutGlobalScopes([...]) whose array mentions 'data_scope' or SCOPE_ID.
     * Comments are blanked first (keeping line numbers). Only app/ is scanned, never tests/.
     *
     * @return array<int, string> Errors naming the module, the file relative to the module and the 1-based line.
     */
    private function lintDroppedDataScope(string $module, string $dir): array
    {
        $appDir = $dir.'/app';
        if (! is_dir($appDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $errors = [];
        foreach ($files as $path) {
            $code = $this->blankComments((string) file_get_contents($path));
            $found = preg_match_all('/(?:->|::)\s*(withoutGlobalScopes?)\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE);
            for ($i = 0; $i < (int) $found; $i++) {
                $openAt = $matches[0][$i][1] + strlen($matches[0][$i][0]);
                $arguments = $this->callArguments($code, $openAt);
                if (! $this->dropsDataScope($matches[1][$i][0], $arguments)) {
                    continue;
                }
                $line = substr_count($code, "\n", 0, $matches[0][$i][1]) + 1;
                $relative = ltrim(substr($path, strlen($dir)), '/\\');
                $errors[] = "{$module}: {$relative}:{$line} must not drop the data scope ({$matches[1][$i][0]}); keep the 'data_scope' global scope applied";
            }
        }

        return $errors;
    }

    /**
     * Whether a withoutGlobalScope(s) call with the given argument text removes the data scope.
     */
    private function dropsDataScope(string $method, string $arguments): bool
    {
        $arguments = trim($arguments);
        $mentions = preg_match('/[\'"]data_scope[\'"]|(?:^|[^\w])(?:DataScope|self|static)::SCOPE_ID\b/', $arguments) === 1;

        if ($method === 'withoutGlobalScopes') {
            return $arguments === '' || preg_match('/^(?:\[\s*\]|array\s*\(\s*\))$/', $arguments) === 1 || $mentions;
        }

        return $mentions && preg_match('/^(?:[\'"]data_scope[\'"]|\\\\?(?:[\w\\\\]*\\\\)?DataScope::SCOPE_ID)$/', $arguments) === 1;
    }

    /**
     * Text between the parenthesis opened just before $offset and its matching close (nesting-aware).
     */
    private function callArguments(string $code, int $offset): string
    {
        $depth = 1;
        $length = strlen($code);
        for ($i = $offset; $i < $length; $i++) {
            if ($code[$i] === '(') {
                $depth++;
            } elseif ($code[$i] === ')' && --$depth === 0) {
                return substr($code, $offset, $i - $offset);
            }
        }

        return substr($code, $offset);
    }

    /**
     * Replace comments with spaces so scanned offsets and line numbers stay unchanged.
     */
    private function blankComments(string $code): string
    {
        return (string) preg_replace_callback(
            '~/\*.*?\*/|(?<![:\'"])//[^\n]*~s',
            static fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]),
            $code
        );
    }

    /**
     * Validate the "scope_dimensions" field and record the declared dimension names.
     *
     * @param  array<int, string>  $errors
     */
    private function lintScopeDimensions(string $module, mixed $dimensions, array &$errors): void
    {
        $this->dimensionsByModule[strtolower($module)] = [];

        if (! is_array($dimensions) || ! array_is_list($dimensions)) {
            $errors[] = "'scope_dimensions' must be a list";

            return;
        }

        foreach ($dimensions as $dimension) {
            $name = is_array($dimension) ? ($dimension['name'] ?? null) : null;
            if (! is_string($name) || $name === '') {
                $errors[] = "scope dimension entry without a string 'name'";
                continue;
            }

            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) !== 1) {
                $errors[] = "scope dimension name '{$name}' is invalid (expected /^[a-z][a-z0-9_]{1,63}$/)";
            }

            if (isset($this->dimensionOwners[$name])) {
                $errors[] = "duplicate scope dimension '{$name}' (also declared by {$this->dimensionOwners[$name]})";
            } else {
                $this->dimensionOwners[$name] = $module;
            }
            $this->dimensionsByModule[strtolower($module)][] = $name;

            if (! in_array($dimension['hierarchy'] ?? null, ['closure', 'path', 'flat'], true)) {
                $errors[] = "scope dimension '{$name}' hierarchy must be closure, path or flat";
            }
        }
    }

    /**
     * Pass two: check permission scopes against all/own, own-module and required-module dimensions.
     *
     * @return array<int, string> Errors.
     */
    private function lintScopeReferences(string $module): array
    {
        $lower = strtolower($module);
        $known = $this->dimensionsByModule[$lower] ?? [];
        foreach ($this->requiresByModule[$lower] ?? [] as $required) {
            array_push($known, ...($this->dimensionsByModule[$required] ?? []));
        }

        $errors = [];
        foreach ($this->scopeRefs[$module] ?? [] as $ref) {
            $scope = $ref['scope'];
            if (! in_array($scope, ['all', 'own'], true) && ! in_array($scope, $known, true)) {
                $errors[] = "permission '{$ref['key']}' scope '{$scope}' is not all, own or a known scope dimension";
            }
        }

        return $errors;
    }

    /**
     * Pass two: compare the module's declared dimensions with the runtime DimensionRegistry.
     *
     * Only enabled modules are checked; dimensions registered by modules outside the scanned path are ignored.
     *
     * @return array<int, string> Errors.
     */
    private function lintRuntimeDimensions(string $module, object $registry): array
    {
        if (! $this->isModuleEnabled($module)) {
            return [];
        }

        $declared = $this->dimensionsByModule[strtolower($module)] ?? [];
        $registered = $registry->all();
        $errors = [];

        foreach ($declared as $name) {
            if (! isset($registered[$name])) {
                $errors[] = "scope dimension '{$name}' is declared but not registered in DimensionRegistry";
            }
        }

        foreach ($registered as $name => $entry) {
            if (strcasecmp((string) $entry['module'], $module) === 0 && ! in_array($name, $declared, true)) {
                $errors[] = "dimension '{$name}' is registered by {$module} but not declared in scope_dimensions";
            }
        }

        return $errors;
    }

    /**
     * Whether the module repository reports the module as enabled.
     */
    protected function isModuleEnabled(string $name): bool
    {
        try {
            return app(RepositoryInterface::class)->find($name)?->isEnabled() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lower-cased module names from a "requires" value (object or list form).
     *
     * @return array<int, string>
     */
    private function requiredNames(mixed $requires): array
    {
        $names = [];
        foreach (is_array($requires) ? $requires : [] as $key => $value) {
            $name = is_int($key) ? $value : $key;
            if (is_string($name)) {
                $names[] = strtolower($name);
            }
        }

        return $names;
    }

    /**
     * Resolve the data-scope dimension registry, or null when the class does not exist.
     */
    private function resolveDimensionRegistry(): ?object
    {
        $class = 'Cms\\Core\\Services\\DataScope\\DimensionRegistry';
        if (! class_exists($class)) {
            return null;
        }

        try {
            return app($class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Validate the "requires" field (object form or list form).
     *
     * @param  array<int, string>  $errors
     */
    private function lintRequires(mixed $requires, array &$errors): void
    {
        if (! is_array($requires)) {
            $errors[] = "'requires' must be an object or a list";

            return;
        }

        foreach ($requires as $key => $value) {
            if (is_int($key)) {
                $name = $value;
            } else {
                $name = $key;
                if (! is_string($value)) {
                    $errors[] = "requires '{$key}' constraint must be a string";
                }
            }
            if (! is_string($name) || ! in_array(strtolower($name), $this->moduleNames, true)) {
                $errors[] = "requires unknown module '".(is_string($name) ? $name : json_encode($name))."'";
            }
        }
    }

    /**
     * Check navigation resources against the registry when it exists.
     *
     * @param  array<int, string>  $errors
     */
    private function lintNavigation(mixed $navigation, ?object $registry, array &$errors): void
    {
        if ($registry === null || ! is_array($navigation) || ! method_exists($registry, 'has')) {
            return;
        }

        foreach ($navigation as $item) {
            $resource = is_array($item) ? ($item['resource'] ?? null) : null;
            if (is_string($resource) && $resource !== '' && ! $registry->has($resource)) {
                $errors[] = "admin.navigation references unknown resource '{$resource}'";
            }
        }
    }

    /**
     * Resolve the resource registry, or null when the class does not exist yet.
     */
    private function resolveRegistry(): ?object
    {
        $class = 'Cms\\Core\\Admin\\Resources\\ResourceRegistry';
        if (! class_exists($class)) {
            return null;
        }

        try {
            return app($class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the value is a semver string.
     */
    private function isSemver(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SEMVER_PATTERN, $value) === 1;
    }
}
