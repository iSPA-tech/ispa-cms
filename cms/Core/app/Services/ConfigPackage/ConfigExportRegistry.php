<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

use Closure;
use Nwidart\Modules\Facades\Module;

/**
 * Decides which settings keys and tables belong in a configuration package.
 *
 * Settings patterns are the Core built-in groups plus the `config_export.settings` of every ENABLED module (a
 * module claiming a `group.*` replaces the built-in entry of that group). Tables are the Core built-in tables plus
 * the enabled modules' `config_export.tables`, limited to tables registered in ConfigTableRegistry. Patterns are
 * `group.*` (any key below the group) or an exact key; malformed patterns are ignored here and reported by
 * `cms:manifest-lint`. This registry only selects keys: SettingsSection still refuses secret, env, non-public and
 * media rows whatever a pattern matches.
 */
class ConfigExportRegistry
{
    /** Setting groups seeded and owned by Core. */
    public const CORE_GROUPS = [
        'email', 'advanced', 'api', 'basic', 'contact', 'image', 'cache', 'tracking', 'social', 'chat', 'html',
        'upload', 'ads', 'product', 'order', 'notify', 'geoplaces',
    ];

    /** Tables owned by Core that are always candidates for export (when registered). */
    public const CORE_TABLES = ['email_templates'];

    private const SEGMENT = '[A-Za-z0-9_-]+';

    /** @var Closure(): array<string, mixed>|null */
    private ?Closure $resolver;

    /**
     * @param Closure(): array<string, mixed>|null $modules Returns enabled module name => `config_export` value
     *                                                      (null: read the enabled nwidart modules).
     * @param ConfigTableRegistry|null             $tables  Table registry (null: resolved from the container).
     */
    public function __construct(?Closure $modules = null, private readonly ?ConfigTableRegistry $tables = null)
    {
        $this->resolver = $modules;
    }

    /**
     * Tell whether a settings pattern is well formed: `group.*` (group of dot-separated segments) or an exact key.
     */
    public static function isValidPattern(mixed $pattern): bool
    {
        if (!is_string($pattern)) {
            return false;
        }

        $segment = self::SEGMENT;

        return preg_match("/^{$segment}(?:\\.{$segment})*(?:\\.\\*)?$/", $pattern) === 1;
    }

    /**
     * Settings key patterns for the package: Core built-ins plus enabled modules' declarations.
     *
     * @return list<string>
     */
    public function settingsPatterns(): array
    {
        $declared = [];

        foreach ($this->enabledDeclarations() as $config) {
            foreach ($this->stringList($config['settings'] ?? []) as $pattern) {
                if (self::isValidPattern($pattern)) {
                    $declared[] = $pattern;
                }
            }
        }

        $patterns = [];

        foreach (self::CORE_GROUPS as $group) {
            if (!in_array($group . '.*', $declared, true)) {
                $patterns[] = $group . '.*';
            }
        }

        return array_values(array_unique(array_merge($patterns, $declared)));
    }

    /**
     * Tables for the package: Core built-ins plus enabled modules' declarations, only those registered.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $registry = $this->tables ?? app(ConfigTableRegistry::class);
        $tables = self::CORE_TABLES;

        foreach ($this->enabledDeclarations() as $config) {
            array_push($tables, ...$this->stringList($config['tables'] ?? []));
        }

        return array_values(array_filter(array_unique($tables), static fn (string $table): bool => $registry->has($table)));
    }

    /**
     * Tell whether a setting key is selected by at least one pattern.
     */
    public function matches(string $key): bool
    {
        return (new ExportContext($this->settingsPatterns()))->matches($key);
    }

    /**
     * `config_export` arrays of enabled modules (non-array declarations are ignored).
     *
     * @return list<array<string, mixed>>
     */
    private function enabledDeclarations(): array
    {
        $modules = $this->resolver !== null ? ($this->resolver)() : $this->nwidartDeclarations();
        $declarations = [];

        foreach ($modules as $config) {
            if (is_array($config)) {
                $declarations[] = $config;
            }
        }

        return $declarations;
    }

    /**
     * Read `config_export` of every enabled nwidart module.
     *
     * @return array<string, mixed>
     */
    private function nwidartDeclarations(): array
    {
        $result = [];

        foreach (Module::allEnabled() as $module) {
            $result[(string) $module->getName()] = $module->get('config_export', []);
        }

        return $result;
    }

    /**
     * Keep only string entries of a list value.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
