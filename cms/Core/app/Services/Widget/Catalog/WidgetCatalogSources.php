<?php

namespace Cms\Core\Services\Widget\Catalog;

/**
 * The widget catalogs the deploy sync reads, keyed by publisher.
 *
 * Each catalog directory holds `widgets.php`, `widget_themes.php` and `widget_inputs.php`. Publishers are the core
 * catalog (iSPA) and every project folder that ships `Database/Seeders/exported`; the publisher name is the project
 * folder name. A project without that directory yet is still a valid publisher (its first export creates it) but is
 * not listed. Tests may replace the map through `ispa-cms.widget_catalog_sources` (publisher => directory) or the
 * projects folder through `ispa-cms.widget_catalog_projects_path`.
 */
final class WidgetCatalogSources
{
    public const ISPA = 'iSPA';

    /** Catalog directory inside a project folder. */
    private const PROJECT_CATALOG = 'Database/Seeders/exported';

    /** @return array<string, string> publisher => catalog directory */
    private static function map(): array
    {
        $override = config('ispa-cms.widget_catalog_sources');
        if (is_array($override)) {
            return $override;
        }

        $map = [self::ISPA => database_path('seeders/exported')];
        $projects = glob(self::projectsPath() . '/*/' . self::PROJECT_CATALOG, GLOB_ONLYDIR) ?: [];
        sort($projects);
        foreach ($projects as $directory) {
            $map[basename(dirname($directory, 3))] = $directory;
        }

        return $map;
    }

    private static function projectsPath(): string
    {
        return rtrim((string) (config('ispa-cms.widget_catalog_projects_path') ?: base_path('projects')), '/');
    }

    /** @return list<string> */
    public static function publishers(): array
    {
        return array_keys(self::map());
    }

    public static function directory(string $publisher): string
    {
        $map = self::map();
        if (array_key_exists($publisher, $map)) {
            return $map[$publisher];
        }
        // A project folder without a catalog yet: its first export creates the directory.
        if (!is_array(config('ispa-cms.widget_catalog_sources'))
            && preg_match('/\A[A-Za-z0-9_-]+\z/', $publisher) === 1
            && is_dir(self::projectsPath() . '/' . $publisher)) {
            return self::projectsPath() . '/' . $publisher . '/' . self::PROJECT_CATALOG;
        }

        throw new \InvalidArgumentException("Unknown widget catalog publisher: {$publisher}");
    }

    /**
     * The widget, theme and input rows of one catalog, in id order.
     *
     * For iSPA, `database/seeders/Internal/` (next to the exported catalog) holds the themes and inputs made for one
     * client project and the full project list of shared themes that also name client projects; it exists only in
     * the private repo (the open-source export leaves it out), and merging it back gives existing sites the same rows
     * as before the split.
     *
     * Pass `$includeInternal = false` for the public files only (what the open-source export ships).
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     */
    public static function rows(string $publisher, bool $includeInternal = true): array
    {
        $directory = self::directory($publisher);
        if (!is_dir($directory)) {
            return [[], [], []];
        }

        $widgets = self::fileRows($directory . '/widgets.php');
        $themes = self::fileRows($directory . '/widget_themes.php');
        $inputs = self::fileRows($directory . '/widget_inputs.php');

        $internal = dirname($directory) . '/Internal';
        if ($includeInternal && $publisher === self::ISPA && is_dir($internal)) {
            $themes = array_merge($themes, self::fileRows($internal . '/widget_themes.php'));
            $inputs = array_merge($inputs, self::fileRows($internal . '/widget_inputs.php'));

            $projects = self::fileRows($internal . '/widget_theme_projects.php');
            foreach ($themes as $index => $theme) {
                if (array_key_exists($theme['id'], $projects)) {
                    $themes[$index]['project_name'] = $projects[$theme['id']];
                }
            }

            $byId = static fn (array $a, array $b): int => $a['id'] <=> $b['id'];
            usort($themes, $byId);
            usort($inputs, $byId);
        }

        return [$widgets, $themes, $inputs];
    }

    /**
     * The last hub version published from local per widget key (spec C5): the optional `hub_version` and
     * `hub_content_sha256` of the exported widget rows. Widgets never published are left out; an unknown publisher
     * has none.
     *
     * @return array<string, array{hub_version: int, hub_content_sha256: ?string}>
     */
    public static function hubMetadata(string $publisher): array
    {
        try {
            [$widgets] = self::rows($publisher, false);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $out = [];
        foreach ($widgets as $row) {
            if (!isset($row['hub_version'])) {
                continue;
            }
            $key = (string) ($row['widget_key'] ?? ('core.widget-' . ($row['id'] ?? '?')));
            $out[$key] = [
                'hub_version' => (int) $row['hub_version'],
                'hub_content_sha256' => isset($row['hub_content_sha256']) ? (string) $row['hub_content_sha256'] : null,
            ];
        }

        return $out;
    }

    /**
     * Rows of a catalog file, or none when the file is absent.
     *
     * @return array<int|string, mixed>
     */
    private static function fileRows(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $rows = require $path;

        return is_array($rows) ? $rows : [];
    }
}
