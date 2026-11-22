<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use Nwidart\Modules\Contracts\RepositoryInterface;

/**
 * Catalog of data-scope levels and dimensions declared in the module.json manifests of enabled modules.
 *
 * Manifest shape:
 *   "permissions": [{"key": "helpdesk.agent", "scopes": ["all", "own", "helpdesk_org"]}]
 *   "scope_dimensions": [{"name": "helpdesk_org", "model": "...", "hierarchy": "closure|path|flat", "label": "..."}]
 * Results are memoised per instance.
 */
class ManifestScopeCatalog
{
    /** @var array<string, array<int, string>>|null */
    protected ?array $levels = null;

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $dimensionMap = null;

    /**
     * @param RepositoryInterface $modules Module repository used to read manifests.
     */
    public function __construct(protected RepositoryInterface $modules)
    {
    }

    /**
     * Scope levels declared for a permission key by enabled modules; ['all'] when none are declared.
     *
     * @return array<int, string>
     */
    public function allowedLevels(string $permissionKey): array
    {
        if ($this->levels === null) {
            $this->levels = [];

            foreach ($this->modules->allEnabled() as $module) {
                $permissions = $module->get('permissions', []);

                foreach (is_array($permissions) ? $permissions : [] as $entry) {
                    if (!is_array($entry) || !isset($entry['key']) || !is_string($entry['key'])) {
                        continue;
                    }

                    $scopes = $entry['scopes'] ?? null;

                    if (!is_array($scopes)) {
                        continue;
                    }

                    $scopes = array_values(array_filter($scopes, 'is_string'));

                    if ($scopes !== []) {
                        $this->levels[$entry['key']] = $scopes;
                    }
                }
            }
        }

        return $this->levels[$permissionKey] ?? ['all'];
    }

    /**
     * Scope dimensions declared by enabled modules, keyed by dimension name, each with an added 'module' name.
     *
     * Reads the list form of module.json "scope_dimensions"; the old object form and entries without a
     * non-empty string "name" are ignored.
     *
     * @return array<string, array<string, mixed>>
     */
    public function dimensions(): array
    {
        if ($this->dimensionMap === null) {
            $this->dimensionMap = [];

            foreach ($this->modules->allEnabled() as $module) {
                $declared = $module->get('scope_dimensions', []);

                if (!is_array($declared) || !array_is_list($declared)) {
                    continue;
                }

                foreach ($declared as $entry) {
                    if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name']) || $entry['name'] === '') {
                        continue;
                    }

                    $entry['module'] = $module->getName();
                    $this->dimensionMap[$entry['name']] = $entry;
                }
            }
        }

        return $this->dimensionMap;
    }
}
