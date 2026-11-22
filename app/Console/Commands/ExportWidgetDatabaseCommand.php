<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Console\Commands;

use Exception;
use RuntimeException;
use App\Constants\Tables;
use Cms\Core\Facades\CMSLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use Cms\Core\Services\Widget\Hub\WidgetPublishLedger;
use Cms\Core\Support\Widget\WidgetNameTranslations;

class ExportWidgetDatabaseCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $signature = 'cms:export-widgets {--target= : Output root override; each publisher is written to <target>/<publisher>} {--publisher=* : Only export these publishers (default all)}';

    protected $description = 'Export hub widget data (widget, widget_theme, widget_input) into PHP array files, one catalog per publisher';

    /**
     * Truthfully report SUCCESS/FAILURE so callers (WidgetDefinitionSyncService)
     * can distinguish a real export from a silently-swallowed error. Previously
     * this command caught every Throwable, logged it, and still returned an
     * implicit 0 exit code — a false success that let post-commit callers
     * believe the export ran when it had not (Gate 0 Task 2).
     *
     * @return int Command::SUCCESS or Command::FAILURE.
     */
    public function handle(): int
    {
        try {
            $requested = array_values(array_filter((array) $this->option('publisher')));
            $publishers = $requested === [] ? WidgetCatalogSources::publishers() : $requested;

            foreach ($publishers as $publisher) {
                $outputDir = $this->resolveOutputDir($publisher);
                $this->exportPublisher($publisher, $outputDir);
            }

            $this->info("✓ All widget data exported to PHP files.");

            return self::SUCCESS;
        } catch (RuntimeException|Exception $e) {
            $this->error("✗ Unexpected error: " . $e->getMessage());

            CMSLogger::error([
                'message' => 'Export Widget Database Error: ' . $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Resolve one publisher's export directory. An explicit --target always wins (as <target>/<publisher>); while
     * the test suite runs, exports are redirected below storage/framework/testing so tests never rewrite the tracked
     * catalogs. Otherwise the publisher's catalog directory is used.
     *
     * @return string Absolute output directory.
     */
    protected function resolveOutputDir(string $publisher): string
    {
        $target = $this->option('target');
        if (is_string($target) && $target !== '') {
            return rtrim($target, '/') . '/' . $publisher;
        }

        if (app()->runningUnitTests()) {
            return storage_path('framework/testing/exported/database/' . $publisher);
        }

        return WidgetCatalogSources::directory($publisher);
    }

    /**
     * Write one publisher's hub widgets with their themes and inputs. Site widgets are never exported, and the
     * per-site runtime columns (clone and block state) never leave the site.
     *
     * Rows keep their catalog file ids: a database row is matched to its catalog row by widget_key / theme_key /
     * input_key (missing file keys fall back to `core.widget-<id>`, `theme-<id>`, `input-<id>` like WidgetSeeder),
     * so the files do not depend on the ids one database happened to assign. A matched row is the catalog row with
     * the database values of the columns the catalog owns; a new row gets the next id above every catalog file id.
     * A publisher with no hub rows and no output directory writes nothing.
     */
    protected function exportPublisher(string $publisher, string $dir): void
    {
        $dbWidgets = DB::table(Tables::WIDGET)
            ->where('origin', Widget::ORIGIN_HUB)
            ->where('publisher', $publisher)
            ->orderBy('id')
            ->get()
            ->map(static function ($item) use ($publisher): array {
                $row = (array) $item;
                unset(
                    $row['cloned_from_widget_key'], $row['cloned_at'],
                    $row['blocked_at'], $row['blocked_by'], $row['blocked_reason'],
                );
                // Only the legacy iSPA catalog falls back to an id-derived key; any other publisher's key must be
                // real, or the catalog would ship a key that differs on every site.
                if (($row['widget_key'] ?? '') === '' && $publisher !== WidgetCatalogSources::ISPA) {
                    throw new RuntimeException("Widget {$row['id']} of publisher {$publisher} has no widget_key.");
                }
                $row['widget_key'] = ($row['widget_key'] ?? '') !== '' ? $row['widget_key'] : 'core.widget-' . $row['id'];

                return $row;
            })->all();

        if ($dbWidgets === [] && !is_dir($dir)) {
            $this->line("- {$publisher}: no hub widgets, nothing written");

            return;
        }
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        [$fileWidgets, $fileThemes, $fileInputs] = WidgetCatalogSources::rows($publisher, false);
        [$internalThemes, $internalInputs, $overriddenThemeIds] = $this->internalRows($publisher);
        $nextId = $this->nextCatalogIds();

        // Widgets: catalog id per database id.
        $fileWidgetsByKey = [];
        foreach ($fileWidgets as $row) {
            $fileWidgetsByKey[self::key($row, 'widget_key', 'core.widget-')] = $row;
        }
        $widgets = [];
        $widgetIdMap = [];
        foreach ($dbWidgets as $row) {
            $file = $fileWidgetsByKey[$row['widget_key']] ?? null;
            if ($file !== null) {
                $widgetIdMap[$row['id']] = $file['id'];
                $widgets[] = $this->overlay($file, $row, ['name', 'description', 'js', 'version', 'name_translations', 'description_translations']);
            } else {
                $widgetIdMap[$row['id']] = $row['id'] = $nextId[Tables::WIDGET]++;
                $widgets[] = $row;
            }
        }

        $widgets = $this->withHubVersions($publisher, $widgets);

        $themes = $this->childRows(Tables::WIDGET_THEME, 'theme_key', 'theme-', $widgetIdMap, $fileThemes, $internalThemes,
            $nextId[Tables::WIDGET_THEME], ['name', 'content', 'css', 'query', 'project_name', 'name_translations'], $overriddenThemeIds);
        $inputs = $this->childRows(Tables::WIDGET_INPUT, 'input_key', 'input-', $widgetIdMap, $fileInputs, $internalInputs,
            $nextId[Tables::WIDGET_INPUT], ['name', 'type', 'default_value', 'options'], []);

        $byId = static fn (array $a, array $b): int => $a['id'] <=> $b['id'];
        usort($widgets, $byId);
        usort($themes, $byId);
        usort($inputs, $byId);

        $this->writeRows(Tables::WIDGET, $dir, $widgets);
        $this->writeRows(Tables::WIDGET_THEME, $dir, $themes);
        $this->writeRows(Tables::WIDGET_INPUT, $dir, $inputs);
    }

    /**
     * Record the last hub version published from local (spec C5) on each widget row: a newer `published` entry of the
     * local publish ledger (confirmed with `cms:widget-bundle-mark-published`) replaces the row's `hub_version` /
     * `hub_content_sha256`; otherwise the catalog row keeps what it has. Signed bundles still pending are only named.
     * Widgets never published get no keys, so their rows stay unchanged.
     *
     * @param array<int, array<string, mixed>> $widgets
     * @return array<int, array<string, mixed>>
     */
    private function withHubVersions(string $publisher, array $widgets): array
    {
        $ledger = app(WidgetPublishLedger::class);
        $published = $ledger->published();
        $pending = $ledger->pending();
        foreach ($widgets as $index => $row) {
            $key = self::key($row, 'widget_key', 'core.widget-');
            $wait = $pending[$key] ?? null;
            if ($wait !== null && $wait['publisher'] === $publisher) {
                $this->warn("- {$publisher} {$key}: version {$wait['hub_version']} chờ xác nhận publish (run cms:widget-bundle-mark-published after the hub accepted it)");
            }
            $entry = $published[$key] ?? null;
            if ($entry === null || $entry['publisher'] !== $publisher) {
                continue;
            }
            if ((int) $entry['hub_version'] >= (int) ($row['hub_version'] ?? 0)) {
                $widgets[$index]['hub_version'] = (int) $entry['hub_version'];
                $widgets[$index]['hub_content_sha256'] = (string) $entry['hub_content_sha256'];
            }
        }

        return $widgets;
    }

    /**
     * Theme or input rows of the exported widgets, matched to catalog rows by (catalog widget id, key).
     *
     * Rows of the private iSPA `Internal/` files (client themes and inputs) never go into the public files, and
     * shared themes whose project list `Internal/widget_theme_projects.php` overrides keep the public project list.
     *
     * @param array<int|string, int> $widgetIdMap database widget id => catalog widget id
     * @param array<int, array<string, mixed>> $fileRows public catalog rows
     * @param array<int, array<string, mixed>> $internalRows private Internal rows
     * @param list<string> $ownedColumns columns the catalog owns (the reconciler syncs them file -> database)
     * @param list<int|string> $keepProjectIds catalog theme ids whose public project_name is kept
     * @return array<int, array<string, mixed>>
     */
    private function childRows(
        string $table,
        string $keyColumn,
        string $fallbackPrefix,
        array $widgetIdMap,
        array $fileRows,
        array $internalRows,
        int &$nextId,
        array $ownedColumns,
        array $keepProjectIds,
    ): array {
        $scope = static fn (array $row, $widgetId): string => $widgetId . ':' . self::key($row, $keyColumn, $fallbackPrefix);

        $fileByScope = [];
        foreach ($fileRows as $row) {
            $fileByScope[$scope($row, $row['widget_id'])] = $row;
        }
        $internalScopes = [];
        foreach ($internalRows as $row) {
            $internalScopes[$scope($row, $row['widget_id'])] = true;
        }

        $out = [];
        $dbRows = $widgetIdMap === [] ? [] : DB::table($table)->whereIn('widget_id', array_keys($widgetIdMap))->orderBy('id')->get();
        foreach ($dbRows as $item) {
            $row = (array) $item;
            $catalogWidgetId = $widgetIdMap[$row['widget_id']];
            $key = $scope($row, $catalogWidgetId);
            if (isset($internalScopes[$key])) {
                continue;
            }

            $file = $fileByScope[$key] ?? null;
            if ($file !== null) {
                $owned = in_array($file['id'], $keepProjectIds, true)
                    ? array_values(array_diff($ownedColumns, ['project_name']))
                    : $ownedColumns;
                $out[] = $this->overlay($file, $row, $owned);
                continue;
            }

            $row['id'] = $nextId++;
            $row['widget_id'] = $catalogWidgetId;
            $row[$keyColumn] = self::key((array) $item, $keyColumn, $fallbackPrefix);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The catalog row with the database value of every owned column that differs; `updated_at` follows the database
     * when something changed. A column the file row lacks counts as its reconciler default (version 1, else null).
     *
     * @param array<string, mixed> $file
     * @param array<string, mixed> $db
     * @param list<string> $ownedColumns
     * @return array<string, mixed>
     */
    private function overlay(array $file, array $db, array $ownedColumns): array
    {
        $normalize = static fn ($value): ?string => $value === null ? null : (string) $value;
        $changed = false;
        foreach ($ownedColumns as $column) {
            if (!array_key_exists($column, $db)) {
                continue;
            }
            $fileValue = array_key_exists($column, $file) ? $file[$column] : ($column === 'version' ? 1 : null);
            // Per-language names compare by meaning: databases hand JSON back in their own spacing.
            if (str_ends_with($column, '_translations')
                && WidgetNameTranslations::normalize($fileValue) === WidgetNameTranslations::normalize($db[$column])) {
                continue;
            }
            if ($normalize($fileValue) !== $normalize($db[$column])) {
                $file[$column] = $db[$column];
                $changed = true;
            }
        }
        if ($changed && array_key_exists('updated_at', $db)) {
            $file['updated_at'] = $db['updated_at'];
        }

        return $file;
    }

    /**
     * The iSPA catalog is public `exported/` plus the private `Internal/` folder next to it (other publishers have
     * none): its theme rows, input rows and the ids of shared themes whose project list it overrides.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: list<int|string>}
     */
    private function internalRows(string $publisher): array
    {
        if ($publisher !== WidgetCatalogSources::ISPA) {
            return [[], [], []];
        }
        $internal = dirname(WidgetCatalogSources::directory($publisher)) . '/Internal';

        return [
            array_values($this->fileRows($internal . '/widget_themes.php')),
            array_values($this->fileRows($internal . '/widget_inputs.php')),
            array_keys($this->fileRows($internal . '/widget_theme_projects.php')),
        ];
    }

    /**
     * Next free id per table above every id in every publisher's catalog files (Internal included), so a new row
     * never takes an id a catalog already uses.
     *
     * @return array<string, int>
     */
    private function nextCatalogIds(): array
    {
        $max = [Tables::WIDGET => 0, Tables::WIDGET_THEME => 0, Tables::WIDGET_INPUT => 0];
        foreach (WidgetCatalogSources::publishers() as $publisher) {
            foreach (array_combine(array_keys($max), WidgetCatalogSources::rows($publisher)) as $table => $rows) {
                foreach ($rows as $row) {
                    $max[$table] = max($max[$table], (int) ($row['id'] ?? 0));
                }
            }
        }

        return array_map(static fn (int $id): int => $id + 1, $max);
    }

    /** A row's stable key, or the WidgetSeeder fallback derived from its id. */
    private static function key(array $row, string $column, string $fallbackPrefix): string
    {
        $key = $row[$column] ?? null;

        return is_string($key) && $key !== '' ? $key : $fallbackPrefix . $row['id'];
    }

    /** @return array<int|string, mixed> */
    private function fileRows(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $rows = require $path;

        return is_array($rows) ? $rows : [];
    }

    /**
     * @throws RuntimeException When the directory is not writable or the
     *         written byte count does not match the encoded payload (a short
     *         or failed write must never be reported as a successful export).
     */
    protected function writeRows(string $table, string $dir, array $data): void
    {
        $export = "<?php\n\nreturn " . var_export(array_values($data), true) . ";\n";
        $path = "$dir/$table.php";

        \Cms\Core\Support\Testing\TranslationFileGuard::assertWritable($path);
        $written = file_put_contents($path, $export);

        if ($written === false || $written !== strlen($export)) {
            throw new RuntimeException(sprintf(
                'Export of "%s" wrote %s of %d expected bytes to "%s".',
                $table,
                $written === false ? 'false' : (string) $written,
                strlen($export),
                $path
            ));
        }

        $this->line("✓ Exported: $table");
    }
}
