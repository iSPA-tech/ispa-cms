<?php

declare(strict_types=1);

namespace Cms\Core\Services\StarterPacks;

use Cms\Core\Models\Permission;
use Cms\Core\Services\ConfigPackage\ConfigExportRegistry;
use Cms\Core\Services\ConfigPackage\ConfigPackageExporter;
use Cms\Core\Services\ConfigPackage\ConfigTableRegistry;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\Sections\RolesSection;
use Cms\Core\Services\ConfigPackage\Sections\SectionAdapter;
use Cms\Core\Services\ConfigPackage\Sections\TablesSection;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Builds a starter pack folder from the current (reference) site.
 *
 * The configuration part is the configuration package exporter (`ConfigPackageExporter::build()`) run with a pack-specific
 * include list: only the settings patterns and tables that the chosen modules declare in `module.json`
 * (`config_export`), never the Core built-in groups. The configuration package sections still refuse secret, encrypted, non-public
 * and media settings, and the package `source`, the list of required secrets, the skip reasons and every id are dropped. Roles come from
 * the configuration package role export (only the named roles, never `Webmaster`), content from the registered importers of the
 * chosen modules (the reverse of `import()`; an importer that exports nothing is left out).
 *
 * The pack is staged in a hidden folder next to the target, loaded back with `StarterPackLoader` and scanned with
 * `PackContentScanner`; only a clean pack is moved into place, so a failed export never leaves a half pack behind.
 * An existing pack folder is never overwritten unless `$force` is true.
 */
class StarterPackExporter
{
    /**
     * @param string|null $modulesPath Folder holding one sub-folder per module; defaults to base_path('cms').
     */
    public function __construct(
        private readonly StarterContentRegistry $registry,
        private readonly PackContentScanner $scanner,
        private readonly ?string $modulesPath = null,
    ) {
    }

    /**
     * Export a pack.
     *
     * @param string       $key     Pack key (`[a-z0-9-]+`); must equal the last segment of `$dir`.
     * @param list<string> $modules Module names to include; `Core` is always added. Empty: Core plus every module that owns a registered importer.
     * @param list<string> $roles   Names of the roles to include (none by default).
     * @param string       $dir     Pack folder to create.
     * @param bool         $force   Replace an existing pack folder.
     *
     * @return array{dir: string, modules: list<string>, settings: int, tables: int, roles: list<string>, content: list<string>, skipped: list<string>, skipped_settings: array<string, int>}
     *
     * @throws StarterPackException When the pack cannot be exported; nothing is written to `$dir` then.
     */
    public function export(string $key, array $modules, array $roles, string $dir, bool $force = false): array
    {
        if (preg_match('/^[a-z0-9-]+$/', $key) !== 1) {
            throw StarterPackException::invalidField($key, 'key', 'must use only lowercase letters, digits and hyphens.');
        }
        $dir = rtrim($dir, '/\\');
        if (basename($dir) !== $key) {
            throw StarterPackException::invalidPack($key, 'the output folder name must equal the pack key.');
        }
        if (file_exists($dir) && !$force && (!is_dir($dir) || (glob($dir . '/*') ?: []) !== [] || (glob($dir . '/.*') ?: []) !== [])) {
            throw StarterPackException::invalidPack($key, 'a pack already exists at ' . $dir . '; use --force to replace it.');
        }

        $chosen = $this->chooseModules($modules);
        $declared = [];
        foreach ($chosen as $module) {
            $declared[$module] = $this->moduleJson($module);
        }

        $stage = dirname($dir) . '/.starter-pack-export-' . bin2hex(random_bytes(6));
        $packDir = $stage . '/' . $key;
        File::ensureDirectoryExists($packDir);

        try {
            $summary = $this->build($key, $chosen, $declared, $roles, $packDir, $dir);

            (new StarterPackLoader($stage))->load($packDir);
            $findings = array_map(
                static fn (array $f): string => sprintf('%s:%d:%s', $f['file'], $f['line'], $f['rule']),
                $this->scanner->scan($packDir),
            );
            if ($findings !== []) {
                throw StarterPackException::invalidPack($key, 'the export contains data that must not be in a pack: ' . implode(', ', $findings) . '.');
            }

            if (file_exists($dir)) {
                File::deleteDirectory($dir);
            }
            File::ensureDirectoryExists(dirname($dir));
            rename($packDir, $dir);
        } finally {
            File::deleteDirectory($stage);
        }

        $summary['dir'] = $dir;

        return $summary;
    }

    /**
     * Write every pack file into the staging folder.
     *
     * @param list<string>                         $modules
     * @param array<string, array<string, mixed>>  $declared module.json data per module
     * @param list<string>                         $roles
     *
     * @return array{dir: string, modules: list<string>, settings: int, tables: int, roles: list<string>, content: list<string>, skipped: list<string>, skipped_settings: array<string, int>}
     */
    private function build(string $key, array $modules, array $declared, array $roles, string $packDir, string $target): array
    {
        $previous = $this->previousManifest($target);
        $summary = ['dir' => $target, 'modules' => $modules, 'settings' => 0, 'tables' => 0, 'roles' => [], 'content' => [], 'skipped' => [], 'skipped_settings' => []];

        $configFile = null;
        [$patterns, $tables] = $this->includeList($declared);
        $sections = array_values(array_filter([
            $patterns !== [] ? 'settings' : null,
            $tables !== [] ? 'tables' : null,
        ]));
        if ($sections !== []) {
            $package = $this->configExporter($patterns, $tables)->build($sections, []);
            $skipped = (array) ($package['skipped']['settings'] ?? []);
            // Keep the file free of anything about the reference site's secrets: no source, no secret key names, no skip reasons.
            unset($package['source']);
            $package['required_secrets'] = [];
            $package['skipped'] = [];
            $summary['settings'] = count((array) ($package['sections']['settings'] ?? []));
            foreach ((array) ($package['sections']['tables'] ?? []) as $rows) {
                $summary['tables'] += count((array) $rows);
            }
            foreach ($skipped as $reason => $count) {
                $summary['skipped_settings'][(string) $reason] = (int) $count;
            }
            $configFile = 'config.package.json';
            $this->writeJson($packDir . '/' . $configFile, $package);
        }

        $rolesFile = null;
        if ($roles !== []) {
            $rolesFile = 'roles.json';
            $list = $this->exportRoles($roles);
            $summary['roles'] = array_column($list, 'name');
            $this->writeJson($packDir . '/' . $rolesFile, $list);
        }

        $content = [];
        foreach ($this->registry->ids() as $id) {
            $importer = $this->registry->get($id);
            if (!in_array($importer->module(), $modules, true)) {
                continue;
            }
            $data = $importer->export([]);
            if ($data === []) {
                $summary['skipped'][] = $id;
                continue;
            }
            $name = str_starts_with($id, strtolower($importer->module()) . '.') ? substr($id, strlen($importer->module()) + 1) : $id;
            $file = 'content/' . strtolower($importer->module()) . '/' . str_replace('.', '-', $name) . '.json';
            $this->writeJson($packDir . '/' . $file, $data);
            $content[] = ['module' => $importer->module(), 'importer' => $id, 'file' => $file];
            $summary['content'][] = $id;
        }

        $manifest = [
            'pack_format' => 1,
            'key' => $key,
            'version' => $previous['version'] ?? '1.0.0',
            'name' => $previous['name'] ?? ['vi' => $key, 'en' => $key],
            'description' => $previous['description'] ?? ['vi' => 'Xuất từ một site tham chiếu.', 'en' => 'Exported from a reference site.'],
            'modules' => array_fill_keys($modules, '*'),
            'config' => $configFile,
            'roles' => $rolesFile,
            'workflows' => null,
            'content' => $content,
            'post_apply' => ['cache-clear'],
        ];
        $this->writeJson($packDir . '/pack.json', $manifest);

        return $summary;
    }

    /**
     * Module list: the requested modules plus Core, or Core plus the owners of registered importers.
     *
     * @param list<string> $modules
     *
     * @return list<string>
     */
    private function chooseModules(array $modules): array
    {
        $names = [];
        foreach ($modules as $module) {
            foreach (explode(',', $module) as $name) {
                if (trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
        }
        if ($names === []) {
            foreach ($this->registry->ids() as $id) {
                $names[] = $this->registry->get($id)->module();
            }
        }
        $names[] = 'Core';
        $names = array_values(array_unique($names));
        sort($names);

        foreach ($names as $name) {
            if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1 || $this->moduleJson($name) === []) {
                throw StarterPackException::invalidPack($name, 'module "' . $name . '" has no cms/' . $name . '/module.json.');
            }
        }

        return $names;
    }

    /**
     * Settings patterns and tables the chosen modules declare in `config_export` (Core built-ins are not added).
     *
     * @param array<string, array<string, mixed>> $declared
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function includeList(array $declared): array
    {
        $patterns = [];
        $tables = [];
        $registry = app(ConfigTableRegistry::class);
        foreach ($declared as $data) {
            $config = is_array($data['config_export'] ?? null) ? $data['config_export'] : [];
            foreach ((array) ($config['settings'] ?? []) as $pattern) {
                if (ConfigExportRegistry::isValidPattern($pattern)) {
                    $patterns[] = $pattern;
                }
            }
            foreach ((array) ($config['tables'] ?? []) as $table) {
                if (is_string($table) && $registry->has($table)) {
                    $tables[] = $table;
                }
            }
        }
        $patterns = array_values(array_unique($patterns));
        $tables = array_values(array_unique($tables));
        sort($patterns);
        sort($tables);

        return [$patterns, $tables];
    }

    /**
     * The configuration package exporter restricted to the include list.
     *
     * @param list<string> $patterns
     * @param list<string> $tables
     */
    private function configExporter(array $patterns, array $tables): ConfigPackageExporter
    {
        return new class($patterns, $tables) extends ConfigPackageExporter {
            /**
             * @param list<string> $patterns
             * @param list<string> $tables
             */
            public function __construct(private readonly array $patterns, private readonly array $tableNames)
            {
            }

            /** @inheritDoc */
            protected function context(string $section): ExportContext
            {
                return new ExportContext($this->patterns);
            }

            /** @inheritDoc */
            protected function adapter(string $section, array $options): SectionAdapter
            {
                if ($section !== 'tables') {
                    return parent::adapter($section, $options);
                }

                $names = $this->tableNames;
                $registry = app(ConfigTableRegistry::class);
                $exports = new class(null, $registry, $names) extends ConfigExportRegistry {
                    /** @param list<string> $names */
                    public function __construct(?\Closure $modules, ?ConfigTableRegistry $tables, private readonly array $names)
                    {
                        parent::__construct($modules, $tables);
                    }

                    /** @inheritDoc */
                    public function tables(): array
                    {
                        return $this->names;
                    }
                };

                return new TablesSection($exports, $registry);
            }

            /** @inheritDoc */
            protected function source(): array
            {
                return [];
            }
        };
    }

    /**
     * Roles of the pack file: `[{ name, custom, permissions: ["group|name"] }]` for the named roles.
     *
     * @param list<string> $names
     *
     * @return list<array<string, mixed>>
     *
     * @throws StarterPackException When a named role does not exist or is protected.
     */
    private function exportRoles(array $names): array
    {
        $rows = [];
        foreach ((new RolesSection())->export(new ExportContext())->rows as $row) {
            $rows[mb_strtolower(trim((string) $row['name']))] = $row;
        }

        $map = [];
        foreach (Permission::query()->get() as $permission) {
            $map[(string) $permission->key] = $permission->group . '|' . $permission->name;
        }

        $list = [];
        foreach ($names as $name) {
            $row = $rows[mb_strtolower(trim($name))] ?? null;
            if ($row === null) {
                throw StarterPackException::invalidPack('roles', 'the role "' . $name . '" does not exist or cannot be part of a pack.');
            }
            $permissions = array_map(static fn (string $key): string => $map[$key] ?? preg_replace('/\./', '|', $key, 1), (array) $row['permissions']);
            $list[] = ['name' => $row['name'], 'custom' => $row['custom'], 'permissions' => array_values($permissions)];
        }

        return $list;
    }

    /**
     * Version, name and description of the pack that is being replaced, when it can be read.
     *
     * @return array<string, mixed>
     */
    private function previousManifest(string $dir): array
    {
        $file = $dir . '/pack.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            return [];
        }

        return array_intersect_key($data, array_flip(['version', 'name', 'description']));
    }

    /**
     * Write a JSON file, creating its folder.
     *
     * @throws StarterPackException When the data cannot be encoded.
     */
    private function writeJson(string $file, mixed $data): void
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw StarterPackException::invalidPack(basename($file), 'the exported data cannot be encoded as JSON.');
        }
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, $json . "\n");
    }

    /**
     * Decoded module.json of a module; an empty array when missing or invalid.
     *
     * @return array<string, mixed>
     */
    private function moduleJson(string $module): array
    {
        $file = rtrim($this->modulesPath ?? base_path('cms'), '/\\') . '/' . $module . '/module.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) && $data !== [] ? $data : [];
    }
}
