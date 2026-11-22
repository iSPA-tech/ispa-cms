<?php

declare(strict_types=1);

namespace Cms\Core\Services\StarterPacks;

use Cms\Core\Models\Permission;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks one starter pack folder and reports every problem found (not only the first).
 *
 * Checks: loader errors; each module in `modules` has a `module.json` and its own `requires` lie inside the pack's
 * module list; the configuration package passes the validator; every role permission `group|name` exists
 * (module manifest `permissions`, a module permission seeder, or a `permissions` table row); every
 * `content.importer` is registered; the content scanner finds no secret or personal data (`file:line:rule`).
 * A module without a `version` is a warning.
 */
class StarterPackLinter
{
    /**
     * @param string|null $modulesPath Folder holding one sub-folder per module; defaults to base_path('cms').
     */
    public function __construct(
        private readonly StarterPackLoader $loader,
        private readonly PackageValidator $validator,
        private readonly PackContentScanner $scanner,
        private readonly StarterContentRegistry $registry,
        private readonly ?string $modulesPath = null,
    ) {
    }

    /**
     * Lint the pack stored in a folder.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function lint(string $packDir): array
    {
        $errors = [];
        $warnings = [];

        try {
            $manifest = $this->loader->load($packDir);
        } catch (StarterPackException $e) {
            $manifest = null;
            $errors[] = $e->getMessage();
        }

        if ($manifest !== null) {
            $this->checkModules($manifest, $errors, $warnings);
            $this->checkConfig($manifest, $errors);
            $this->checkRoles($manifest, $errors);
            foreach ($manifest->content as $entry) {
                if (!$this->registry->has($entry['importer'])) {
                    $errors[] = sprintf('content importer "%s" is not a registered importer id.', $entry['importer']);
                }
            }
        }

        if (is_dir($packDir)) {
            foreach ($this->scanner->scan($packDir) as $finding) {
                $errors[] = sprintf('%s:%d:%s', $finding['file'], $finding['line'], $finding['rule']);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Module existence, `requires` closure and version warnings.
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkModules(StarterPackManifest $manifest, array &$errors, array &$warnings): void
    {
        $inPack = array_map('strtolower', array_keys($manifest->modules));

        foreach (array_keys($manifest->modules) as $module) {
            $data = $this->moduleJson($module);
            if ($data === null) {
                $errors[] = sprintf('module "%s" has no cms/%s/module.json.', $module, $module);
                continue;
            }
            if (!isset($data['version']) || !is_string($data['version']) || $data['version'] === '') {
                $warnings[] = sprintf('module "%s" has no version in its module.json.', $module);
            }
            $requires = (array) ($data['requires'] ?? []);
            $names = array_is_list($requires) ? $requires : array_keys($requires);
            foreach ($names as $required) {
                if (is_string($required) && !in_array(strtolower($required), $inPack, true)) {
                    $errors[] = sprintf('module "%s" requires "%s", which is not in the pack module list.', $module, $required);
                }
            }
        }
    }

    /**
     * Run the configuration package validator on the configuration package.
     *
     * @param list<string> $errors
     */
    private function checkConfig(StarterPackManifest $manifest, array &$errors): void
    {
        $path = $manifest->configPath();
        if ($path === null) {
            return;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            $errors[] = sprintf('%s is not valid JSON.', (string) $manifest->config);
            return;
        }
        foreach ($this->validator->validate($data)->errors() as $message) {
            $errors[] = sprintf('%s: %s', (string) $manifest->config, $message);
        }
    }

    /**
     * Every role permission must exist somewhere.
     *
     * @param list<string> $errors
     */
    private function checkRoles(StarterPackManifest $manifest, array &$errors): void
    {
        $path = $manifest->rolesPath();
        if ($path === null) {
            return;
        }
        $roles = json_decode((string) file_get_contents($path), true);
        if (!is_array($roles)) {
            $errors[] = sprintf('%s is not valid JSON.', (string) $manifest->roles);
            return;
        }

        $known = $this->knownPermissions();
        foreach ($roles as $role) {
            foreach ((array) (is_array($role) ? ($role['permissions'] ?? []) : []) as $key) {
                if (!is_string($key) || !str_contains($key, '|')) {
                    continue;
                }
                [$group, $name] = explode('|', $key, 2);
                if (!isset($known[$group . '.' . $name]) && !$this->inSeeders($group, $name) && !$this->inDatabase($group, $name)) {
                    $errors[] = sprintf('role permission "%s" is not declared by any module.', $key);
                }
            }
        }
    }

    /**
     * Permission keys (`group.name`) declared in module manifests.
     *
     * @return array<string, true>
     */
    private function knownPermissions(): array
    {
        $known = [];
        foreach (glob($this->modulesRoot() . '/*/module.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            foreach ((array) (is_array($data) ? ($data['permissions'] ?? []) : []) as $permission) {
                if (is_array($permission) && is_string($permission['key'] ?? null)) {
                    $known[$permission['key']] = true;
                }
            }
        }

        return $known;
    }

    /**
     * Whether a module permission seeder quotes both the group and the name.
     */
    private function inSeeders(string $group, string $name): bool
    {
        foreach (glob($this->modulesRoot() . '/*/database/seeders/*Permission*.php') ?: [] as $file) {
            $text = (string) file_get_contents($file);
            if (preg_match('/[\'"]' . preg_quote($group, '/') . '[\'"]/', $text) === 1
                && preg_match('/[\'"]' . preg_quote($name, '/') . '[\'"]/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback for permissions whose seeder uses constants: a row in the permissions table.
     */
    private function inDatabase(string $group, string $name): bool
    {
        try {
            return Schema::hasTable('permissions')
                && Permission::query()->where('group', $group)->where('name', $name)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Decoded module.json of a module, or null when missing or invalid.
     *
     * @return array<string, mixed>|null
     */
    private function moduleJson(string $module): ?array
    {
        $file = $this->modulesRoot() . '/' . $module . '/module.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Modules folder without a trailing slash.
     */
    private function modulesRoot(): string
    {
        return rtrim($this->modulesPath ?? base_path('cms'), '/\\');
    }
}
