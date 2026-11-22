<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget\Catalog;

use Cms\Core\Support\Widget\WidgetNameTranslations;
use Cms\Core\Services\Widget\Hub\WidgetBundleBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Compares the hub widget rows in the local database with the exported catalogs (verify rule 5) and reports which
 * widgets carry edits that are not exported yet.
 *
 * Used by `cms:widget-catalog-verify` (rule 5) and by the library's `GET widget/catalog-status`. Only meaningful on
 * authoring environments ({@see WidgetAuthoringPolicy::authoringEnabled()}); elsewhere the status is empty.
 */
class WidgetCatalogStatusService
{
    private const WIDGET_COLUMNS = ['name', 'description', 'js', 'version', 'name_translations', 'description_translations'];
    private const THEME_COLUMNS = ['name', 'content', 'css', 'query', 'project_name', 'name_translations'];
    private const INPUT_COLUMNS = ['name', 'type', 'default_value', 'options'];

    public function __construct(
        private readonly WidgetAuthoringPolicy $policy,
        private readonly WidgetBundleBuilder $builder,
    ) {
    }

    /**
     * `unpublished_widget_keys`: hub widgets whose local content differs from the last version published to the hub
     * (the exported `hub_content_sha256`), including widgets never published.
     *
     * @return array{authoring: bool, unexported_widget_keys: list<string>, unpublished_widget_keys: list<string>}
     */
    public function status(): array
    {
        if (!$this->policy->authoringEnabled()) {
            return ['authoring' => false, 'unexported_widget_keys' => [], 'unpublished_widget_keys' => []];
        }

        $keys = [];
        $unpublished = [];
        foreach (WidgetCatalogSources::publishers() as $publisher) {
            if (!is_dir(WidgetCatalogSources::directory($publisher))) {
                continue;
            }
            $keys = array_merge($keys, array_keys($this->unexportedEdits($publisher, $this->catalog($publisher))));
            $unpublished = array_merge($unpublished, $this->unpublishedKeys($publisher));
        }

        return [
            'authoring' => true,
            'unexported_widget_keys' => self::sortedUnique($keys),
            'unpublished_widget_keys' => self::sortedUnique($unpublished),
        ];
    }

    /**
     * Hub widgets of a publisher whose local content hash differs from the last published one.
     *
     * @return list<string>
     */
    private function unpublishedKeys(string $publisher): array
    {
        $published = WidgetCatalogSources::hubMetadata($publisher);
        $keys = [];
        $dbKeys = DB::table('widgets')->where('origin', 'hub')->where('publisher', $publisher)
            ->whereNotNull('widget_key')->pluck('widget_key');
        foreach ($dbKeys as $key) {
            $hash = $this->builder->build((string) $key, 1, $publisher)->contentSha256;
            if (($published[$key]['hub_content_sha256'] ?? null) !== $hash) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /** @param list<string> $keys @return list<string> */
    private static function sortedUnique(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Rule 5: differences between the local hub rows of a publisher and its exported catalog. Throws when the
     * database is unavailable (callers report it).
     *
     * @param array<string, array{row: array<string, mixed>, themes: array<string, array<string, mixed>>, inputs: array<string, array<string, mixed>>}> $catalog
     *        widget_key => exported widget row with its themes and inputs keyed by theme_key / input_key
     * @return array<string, list<string>> widget_key => reasons, e.g. "unexported edit in name (run cms:export-widgets)"
     */
    public function unexportedEdits(string $publisher, array $catalog): array
    {
        $reasons = [];
        $dbWidgets = DB::table('widgets')->where('origin', 'hub')->where('publisher', $publisher)->get()->keyBy('widget_key');

        foreach ($dbWidgets as $key => $db) {
            if (!isset($catalog[$key])) {
                $reasons[$key][] = 'in local database but not exported (run cms:export-widgets)';
            }
        }

        foreach ($catalog as $key => $entry) {
            $db = $dbWidgets[$key] ?? null;
            if ($db === null) {
                continue; // reported by the verify render check
            }
            foreach (self::WIDGET_COLUMNS as $col) {
                if (array_key_exists($col, $entry['row']) && $this->norm($entry['row'][$col], $col) !== $this->norm($db->$col ?? null, $col)) {
                    $hint = $col === 'version'
                        ? 'the file version wins on sync; run cms:export-widgets to keep the database value'
                        : 'run cms:export-widgets';
                    $reasons[$key][] = "unexported edit in {$col} ({$hint})";
                }
            }
            foreach ([
                ['theme', 'widget_themes', 'theme_key', self::THEME_COLUMNS, $entry['themes']],
                ['input', 'widget_inputs', 'input_key', self::INPUT_COLUMNS, $entry['inputs']],
            ] as [$label, $table, $keyCol, $cols, $exported]) {
                foreach ($this->childDifferences($label, $table, $keyCol, $cols, $exported, (int) $db->id) as $reason) {
                    $reasons[$key][] = $reason;
                }
            }
        }

        return $reasons;
    }

    /** @return list<string> */
    private function childDifferences(string $label, string $table, string $keyCol, array $cols, array $exported, int $widgetId): array
    {
        $reasons = [];
        $rows = DB::table($table)->where('widget_id', $widgetId)->get()->keyBy($keyCol);
        foreach ($rows as $childKey => $db) {
            if (!isset($exported[$childKey])) {
                $reasons[] = "{$label} {$childKey} in local database but not exported (run cms:export-widgets)";
            }
        }
        foreach ($exported as $childKey => $row) {
            $db = $rows[$childKey] ?? null;
            if ($db === null) {
                continue;
            }
            foreach ($cols as $col) {
                if (array_key_exists($col, $row) && $this->norm($row[$col], $col) !== $this->norm($db->$col ?? null, $col)) {
                    $reasons[] = "unexported edit in {$label} {$childKey} {$col} (run cms:export-widgets)";
                }
            }
        }

        return $reasons;
    }

    /**
     * Catalog index for the status read: widget_key => row, themes and inputs, with the same fallback keys the sync
     * uses. Unlike the verify command it does not validate (duplicates keep the first row).
     *
     * @return array<string, array{row: array<string, mixed>, themes: array<string, array<string, mixed>>, inputs: array<string, array<string, mixed>>}>
     */
    private function catalog(string $publisher): array
    {
        [$widgets, $themes, $inputs] = WidgetCatalogSources::rows($publisher);
        $catalog = [];
        $byId = [];
        foreach ($widgets as $row) {
            $key = (string) ($row['widget_key'] ?? ('core.widget-' . ($row['id'] ?? '?')));
            if ($key === '' || isset($catalog[$key])) {
                continue;
            }
            $catalog[$key] = ['row' => $row, 'themes' => [], 'inputs' => []];
            $byId[$row['id'] ?? null] = $key;
        }
        foreach ([['themes', $themes, 'theme_key', 'theme-'], ['inputs', $inputs, 'input_key', 'input-']] as [$bucket, $rows, $keyCol, $fallback]) {
            foreach ($rows as $row) {
                $owner = $byId[$row['widget_id'] ?? null] ?? null;
                $childKey = (string) ($row[$keyCol] ?? ($fallback . ($row['id'] ?? '?')));
                if ($owner !== null && $childKey !== '' && !isset($catalog[$owner][$bucket][$childKey])) {
                    $catalog[$owner][$bucket][$childKey] = $row;
                }
            }
        }

        return $catalog;
    }

    private function norm(mixed $value, string $column = ''): ?string
    {
        // Per-language names compare by meaning (the database may hand back JSON in another spacing).
        if (str_ends_with($column, '_translations')) {
            return WidgetNameTranslations::encode($value);
        }
        if ($value === null) {
            return null;
        }

        return is_array($value) ? json_encode($value) : (string) $value;
    }
}
