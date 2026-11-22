<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

use Cms\Core\Jobs\ExportConfigPackageJob;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Services\ConfigPackage\Sections\CustomFieldsSection;
use Cms\Core\Services\ConfigPackage\Sections\MenusSection;
use Cms\Core\Services\ConfigPackage\Sections\RolesSection;
use Cms\Core\Services\ConfigPackage\Sections\SectionAdapter;
use Cms\Core\Services\ConfigPackage\Sections\SettingsSection;
use Cms\Core\Services\ConfigPackage\Sections\TablesSection;
use Cms\Core\Services\ConfigPackage\Sections\TranslationsSection;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;
use InvalidArgumentException;
use Nwidart\Modules\Facades\Module;

/**
 * Builds a configuration package from chosen sections.
 *
 * export() validates the section names, records a queued run and dispatches ExportConfigPackageJob; build() does the
 * actual work (used by the job) and returns the package array. Rows of every section are sorted by their stable key
 * so exporting unchanged data twice gives the same checksum. Nothing here logs or returns row values.
 */
class ConfigPackageExporter
{
    /**
     * Queue an export of the chosen sections.
     *
     * @param list<string>         $sections Section names (see PackageFormat::SECTIONS).
     * @param array<string, mixed> $options  Supported: `translation_scope` (web, adm, app or all; default web).
     * @param int                  $userId   Id of the user who requested the export.
     *
     * @throws InvalidArgumentException When a section name is unknown or no section is chosen.
     */
    public function export(array $sections, array $options, int $userId): ConfigPackageRun
    {
        $sections = $this->normalizeSections($sections);

        $run = ConfigPackageRun::query()->create([
            'kind' => ConfigPackageRun::KIND_EXPORT,
            'status' => ConfigPackageRun::STATUS_QUEUED,
            'sections' => $sections,
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        ExportConfigPackageJob::dispatch((int) $run->id, $options);

        return $run->refresh();
    }

    /**
     * Validate section names and return them in canonical package order without duplicates.
     *
     * @param list<string> $sections
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException When a name is unknown or the list is empty.
     */
    public function normalizeSections(array $sections): array
    {
        $unknown = array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): string => is_string($name) ? $name : '', $sections),
            static fn (string $name): bool => !in_array($name, PackageFormat::SECTIONS, true),
        )));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown section(s): %s. Allowed sections: %s.',
                implode(', ', array_map(static fn (string $name): string => $name === '' ? '(invalid)' : $name, $unknown)),
                implode(', ', PackageFormat::SECTIONS),
            ));
        }

        $chosen = array_values(array_intersect(PackageFormat::SECTIONS, $sections));

        if ($chosen === []) {
            throw new InvalidArgumentException('Choose at least one section. Allowed sections: ' . implode(', ', PackageFormat::SECTIONS) . '.');
        }

        return $chosen;
    }

    /**
     * Build the package array for the (already normalized) sections.
     *
     * @param list<string>                      $sections  Section names.
     * @param array<string, mixed>              $options   Same options as export().
     * @param (callable(string, int): void)|null $onSection Called with the section name and its row count after each section.
     *
     * @return array<string, mixed>
     */
    public function build(array $sections, array $options, ?callable $onSection = null): array
    {
        $built = [];
        $skipped = [];
        $secrets = [];

        foreach ($this->normalizeSections($sections) as $name) {
            $result = $this->adapter($name, $options)->export($this->context($name));
            $rows = $this->sortRows($name, $result->rows);

            $built[$name] = $name === PackageFormat::TABLES_SECTION ? $this->groupTables($rows) : $rows;

            if ($result->skipped !== []) {
                $reasons = $result->skipped;
                ksort($reasons);
                $skipped[$name] = $reasons;
            }

            array_push($secrets, ...$result->requiredSecrets);

            if ($onSection !== null) {
                $onSection($name, count($rows));
            }
        }

        $secrets = array_values(array_unique($secrets));
        sort($secrets, SORT_STRING);

        return [
            'format' => PackageFormat::FORMAT,
            'format_version' => PackageFormat::VERSION,
            'created_at' => now()->toIso8601String(),
            'source' => $this->source(),
            'sections' => $built,
            'required_secrets' => $secrets,
            'skipped' => $skipped,
            'checksum' => PackageFormat::checksum($built),
        ];
    }

    /**
     * Resolve the adapter of a section. Overridable so tests can substitute adapters.
     *
     * @param array<string, mixed> $options
     */
    protected function adapter(string $section, array $options): SectionAdapter
    {
        return match ($section) {
            'settings' => app(SettingsSection::class),
            'translations' => new TranslationsSection($this->translationScope($options)),
            'roles' => app(RolesSection::class),
            'menus' => app(MenusSection::class),
            'workflows' => app(WorkflowsSection::class),
            'custom_fields' => app(CustomFieldsSection::class),
            default => app(TablesSection::class),
        };
    }

    /**
     * Export context of a section: settings use the registry patterns, other sections need none.
     */
    protected function context(string $section): ExportContext
    {
        return $section === 'settings'
            ? new ExportContext(app(ConfigExportRegistry::class)->settingsPatterns())
            : new ExportContext();
    }

    /**
     * Package `source` object: app name, host, Core version and enabled modules with versions.
     *
     * @return array<string, mixed>
     */
    protected function source(): array
    {
        $modules = [];

        foreach (Module::allEnabled() as $module) {
            $modules[(string) $module->getName()] = (string) $module->get('version', '0.0.0');
        }

        ksort($modules, SORT_STRING);

        return [
            'app_name' => (string) config('app.name', 'iSPA'),
            'host' => (string) (parse_url((string) config('app.url', ''), PHP_URL_HOST) ?: 'localhost'),
            'core_version' => $modules['Core'] ?? '0.0.0',
            'modules' => $modules,
        ];
    }

    /**
     * Translation scope option, restricted to the known scopes.
     *
     * @param array<string, mixed> $options
     */
    private function translationScope(array $options): string
    {
        $scope = $options['translation_scope'] ?? 'web';

        return in_array($scope, ['web', 'adm', 'app', 'all'], true) ? $scope : 'web';
    }

    /**
     * Sort rows by their stable key (stable and deterministic; sibling order inside a row is kept).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function sortRows(string $section, array $rows): array
    {
        $fields = match ($section) {
            'settings' => ['key', 'language'],
            'translations', 'roles' => ['name'],
            'menus' => ['group_key', 'language', 'name'],
            'workflows' => ['slug'],
            'custom_fields' => ['resource', 'kind', 'key'],
            default => ['table', 'key', 'language'],
        };

        $keyOf = static function (array $row) use ($fields): string {
            return PackageFormat::canonicalJson(array_map(static fn (string $field): mixed => $row[$field] ?? null, $fields));
        };

        usort($rows, static fn (array $a, array $b): int => strcmp($keyOf($a), $keyOf($b)));

        return array_values($rows);
    }

    /**
     * Group flat table rows into the package shape `table => rows`.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupTables(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) ($row['table'] ?? '')][] = $row;
        }

        ksort($grouped, SORT_STRING);

        return $grouped;
    }
}
