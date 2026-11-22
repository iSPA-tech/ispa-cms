<?php

namespace Cms\Core\Services\Widget;

use Cms\Core\Models\Widget;
use Cms\Core\Support\Widget\WidgetNameTranslations;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class LegacyWidgetDefinitionReconciler
{
    /** Per-language name columns: written in normal form, compared by meaning. */
    private const TRANSLATION_COLUMNS = ['name_translations', 'description_translations'];

    /**
     * Upsert one publisher's catalog rows by stable key. Every widget saved here becomes a hub widget stamped with
     * `$publisher`; rows the catalog does not name (site widgets, clones, operator rows) are never touched.
     */
    public function reconcile(array $widgets, array $themes, array $inputs, string $publisher): void
    {
        DB::transaction(function () use ($widgets, $themes, $inputs, $publisher): void {
            $widgetIds = [];
            $seen = [];
            foreach ($widgets as $row) {
                $key = $this->key($row, 'widget_key');
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException("Duplicate source widget key: {$key}");
                }
                $seen[$key] = true;
                $widgetIds[$row['id']] = $this->save(
                    'widgets', 'widget_key', $key, ['origin' => Widget::ORIGIN_HUB, 'publisher' => $publisher] + $row,
                    ['name', 'description', 'js', 'origin', 'publisher', 'version', 'name_translations', 'description_translations'], 'legacy.widget-', null,
                );
            }

            $seen = [];
            foreach ($themes as $row) {
                $widgetId = $widgetIds[$row['widget_id']] ?? null;
                if ($widgetId === null) {
                    throw new RuntimeException("Theme {$row['id']} refers to missing source widget {$row['widget_id']}.");
                }
                $key = $this->key($row, 'theme_key');
                $scope = $widgetId . ':' . $key;
                if (isset($seen[$scope])) {
                    throw new InvalidArgumentException("Duplicate source theme key: {$scope}");
                }
                $seen[$scope] = true;
                $this->save(
                    'widget_themes', 'theme_key', $key, $row,
                    ['name', 'content', 'css', 'query', 'project_name', 'name_translations'], 'legacy.theme-', $widgetId,
                );
            }

            $seen = [];
            foreach ($inputs as $row) {
                $widgetId = $widgetIds[$row['widget_id']] ?? null;
                if ($widgetId === null) {
                    throw new RuntimeException("Input {$row['id']} refers to missing source widget {$row['widget_id']}.");
                }
                $key = $this->key($row, 'input_key');
                $scope = $widgetId . ':' . $key;
                if (isset($seen[$scope])) {
                    throw new InvalidArgumentException("Duplicate source input key: {$scope}");
                }
                $seen[$scope] = true;
                $this->save(
                    'widget_inputs', 'input_key', $key, $row,
                    ['name', 'type', 'default_value', 'options'], 'legacy.input-', $widgetId,
                );
            }
        });
    }

    private function key(array $row, string $column): string
    {
        $key = $row[$column] ?? null;
        if (!is_string($key) || $key === '') {
            throw new InvalidArgumentException("Missing source {$column}.");
        }

        return $key;
    }

    private function save(
        string $table,
        string $keyColumn,
        string $key,
        array $source,
        array $ownedColumns,
        string $legacyPrefix,
        ?int $widgetId,
    ): int {
        $values = array_intersect_key($source, array_flip($ownedColumns));
        foreach (self::TRANSLATION_COLUMNS as $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = WidgetNameTranslations::encode($values[$column]);
            }
        }
        $scope = DB::table($table)->where($keyColumn, $key);
        if ($widgetId !== null) {
            $scope->where('widget_id', $widgetId);
        }
        $existing = $scope->first();

        if ($existing === null) {
            $legacy = DB::table($table)
                ->where('id', $source['id'])
                ->where($keyColumn, $legacyPrefix . $source['id'])
                ->where('name', $source['name']);
            if ($widgetId !== null) {
                $legacy->where('widget_id', $widgetId);
            }
            $existing = $legacy->first();
            if ($existing === null) {
                $byName = DB::table($table)
                    ->where($keyColumn, 'like', $legacyPrefix . '%')
                    ->where('name', $source['name']);
                if ($widgetId !== null) {
                    $byName->where('widget_id', $widgetId);
                }
                $matches = $byName->limit(2)->get();
                if ($matches->count() > 1) {
                    throw new RuntimeException("Ambiguous legacy {$table} name: {$source['name']}");
                }
                $existing = $matches->first();
            }
            if ($existing !== null) {
                DB::table($table)->where('id', $existing->id)->update([$keyColumn => $key]);
            }
        }

        if ($existing !== null) {
            $changes = [];
            foreach ($values as $column => $value) {
                // Per-language names compare by meaning: the database may hand back JSON in another spacing.
                if (in_array($column, self::TRANSLATION_COLUMNS, true)
                    && WidgetNameTranslations::normalize($existing->$column ?? null) === WidgetNameTranslations::normalize($value)) {
                    continue;
                }
                if ($existing->$column !== $value) {
                    $changes[$column] = $value;
                }
            }
            if ($changes !== []) {
                DB::table($table)->where('id', $existing->id)->update($changes + ['updated_at' => now()]);
            }

            return (int) $existing->id;
        }

        return (int) DB::table($table)->insertGetId($values + [
            $keyColumn => $key,
            ...($widgetId === null ? [] : ['widget_id' => $widgetId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
