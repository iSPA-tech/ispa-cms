<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\MetaValueBigNum;
use Cms\Core\Models\MetaValueBoolean;
use Cms\Core\Models\MetaValueDate;
use Cms\Core\Models\MetaValueString;
use Cms\Core\Models\MetaValueText;
use Cms\Core\Models\MetaValueTinyNum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deduplicates the meta value pool and repairs assignment references.
 *
 * The legacy write path created a NEW value row for every save (no lookup),
 * which degraded the shared value pool into per-assignment storage (33% of
 * prod string values were duplicates). This service merges duplicate value
 * rows onto a canonical row (lowest id), remaps every referencing table
 * (meta_data, user_meta, meta_attribute_value_pivot, product_attributes),
 * removes exact-duplicate pivot/assignment rows, and is safe to re-run.
 *
 * IMPORTANT semantics preserved:
 *  - value_id for tinyInt/bigInt "number" input attributes stores the literal
 *    number (NOT a FK); remapping only touches rows whose value_type matches
 *    the value table being deduplicated, so literals are never rewritten.
 *  - Duplicate value rows are HARD deleted: a soft-deleted duplicate would
 *    still collide with the unique index that protects the pool.
 *
 * @package Cms\Core\Services
 * @author  iSPA Team
 * @since   8.1.0 (2026-07-17)
 */
class MetaNormalizationService
{
    /**
     * Tables that reference value rows morphically via (value_type, value_id).
     *
     * @var array<int, string>
     */
    private const VALUE_REFERENCING_TABLES = [
        CoreTableConstant::META_DATA,
        CoreTableConstant::USERS_META,
        CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT,
        'product_attributes',
    ];

    /**
     * Value tables and the expression used to detect duplicates.
     *
     * @return array<class-string, string> model => duplicate grouping column
     */
    private function valueModels(): array
    {
        return [
            MetaValueString::class => 'value',
            MetaValueText::class => 'value',
            MetaValueDate::class => 'value',
            MetaValueBoolean::class => 'value',
            MetaValueTinyNum::class => 'value',
            MetaValueBigNum::class => 'value',
        ];
    }

    /**
     * Run the full normalization pass.
     *
     * @param  bool $dryRun When true nothing is written; the report shows what would change.
     * @return array<string, mixed> Report keyed by step.
     */
    public function normalize(bool $dryRun = false): array
    {
        $report = [];

        foreach ($this->valueModels() as $model => $column) {
            $table = (new $model())->getTable();
            if (! Schema::hasTable($table)) {
                continue;
            }

            // LONGTEXT cannot be grouped reliably in SQL (max_sort_length
            // truncation) — group text values in PHP by hash instead.
            $report[$table] = $model === MetaValueText::class
                ? $this->dedupeTextValueTable($model, $table, $dryRun)
                : $this->dedupeValueTable($model, $table, $column, $dryRun);
        }

        foreach ($this->valueModels() as $model => $column) {
            $table = (new $model())->getTable();
            if (Schema::hasTable($table)) {
                $report[$table . ':null_orphans'] = $this->purgeNullOrphanValues($model, $table, $dryRun);
            }
        }

        $report[CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT . ':pivot'] = $this->dedupePivotRows($dryRun);
        $report[CoreTableConstant::META_DATA . ':assignments'] = $this->dedupeAssignmentRows(
            CoreTableConstant::META_DATA,
            ['target_type', 'target_id', 'meta_attribute_id', 'value_type', 'value_id'],
            $dryRun,
        );
        $report[CoreTableConstant::USERS_META . ':assignments'] = $this->dedupeAssignmentRows(
            CoreTableConstant::USERS_META,
            ['user_id', 'meta_attribute_id', 'value_type', 'value_id'],
            $dryRun,
        );

        return $report;
    }

    /**
     * Merge duplicate value rows of one table onto the lowest-id canonical row.
     *
     * @return array{groups: int, removed: int, remapped: int}
     */
    private function dedupeValueTable(string $model, string $table, string $column, bool $dryRun): array
    {
        $duplicateGroups = DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        $removed = 0;
        $remapped = 0;

        foreach ($duplicateGroups as $value) {
            $ids = DB::table($table)
                ->where($column, $value)
                ->orderBy('id')
                ->pluck('id');

            $canonicalId = (int) $ids->first();
            $duplicateIds = $ids->slice(1)->map(fn ($id) => (int) $id)->values()->all();
            if ($duplicateIds === []) {
                continue;
            }

            if (! $dryRun) {
                foreach (self::VALUE_REFERENCING_TABLES as $referencingTable) {
                    if (! Schema::hasTable($referencingTable)) {
                        continue;
                    }

                    $remapped += DB::table($referencingTable)
                        ->where('value_type', $model)
                        ->whereIn('value_id', $duplicateIds)
                        ->update(['value_id' => $canonicalId]);
                }

                // Hard delete: soft-deleted duplicates would still collide with
                // the unique index protecting the value pool.
                $removed += DB::table($table)->whereIn('id', $duplicateIds)->delete();
            } else {
                $removed += count($duplicateIds);
            }
        }

        return [
            'groups' => $duplicateGroups->count(),
            'removed' => $removed,
            'remapped' => $remapped,
        ];
    }

    /**
     * Merge duplicate LONGTEXT value rows, grouping in PHP by content hash.
     *
     * @return array{groups: int, removed: int, remapped: int}
     */
    private function dedupeTextValueTable(string $model, string $table, bool $dryRun): array
    {
        $byHash = [];
        DB::table($table)
            ->select(['id', 'value'])
            ->whereNotNull('value')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$byHash): void {
                foreach ($rows as $row) {
                    $byHash[sha1((string) $row->value)][] = (int) $row->id;
                }
            });

        $groups = 0;
        $removed = 0;
        $remapped = 0;

        foreach ($byHash as $ids) {
            if (count($ids) < 2) {
                continue;
            }

            $groups++;
            $canonicalId = $ids[0];
            $duplicateIds = array_slice($ids, 1);

            if ($dryRun) {
                $removed += count($duplicateIds);
                continue;
            }

            foreach (self::VALUE_REFERENCING_TABLES as $referencingTable) {
                if (! Schema::hasTable($referencingTable)) {
                    continue;
                }

                $remapped += DB::table($referencingTable)
                    ->where('value_type', $model)
                    ->whereIn('value_id', $duplicateIds)
                    ->update(['value_id' => $canonicalId]);
            }

            $removed += DB::table($table)->whereIn('id', $duplicateIds)->delete();
        }

        return [
            'groups' => $groups,
            'removed' => $removed,
            'remapped' => $remapped,
        ];
    }

    /**
     * Delete NULL-value pool rows that nothing references (legacy garbage).
     * NULL escapes the unique index (SQL NULLs are pairwise distinct), so it
     * must be cleaned rather than constrained.
     *
     * @return array{removed: int}
     */
    private function purgeNullOrphanValues(string $model, string $table, bool $dryRun): array
    {
        $ids = DB::table($table)->whereNull('value')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return ['removed' => 0];
        }

        $referenced = [];
        foreach (self::VALUE_REFERENCING_TABLES as $referencingTable) {
            if (! Schema::hasTable($referencingTable)) {
                continue;
            }

            $referenced = array_merge($referenced, DB::table($referencingTable)
                ->where('value_type', $model)
                ->whereIn('value_id', $ids)
                ->pluck('value_id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        $orphans = array_values(array_diff($ids, array_unique($referenced)));
        if ($orphans === []) {
            return ['removed' => 0];
        }

        return ['removed' => $dryRun ? count($orphans) : DB::table($table)->whereIn('id', $orphans)->delete()];
    }

    /**
     * Remove exact-duplicate attribute↔value pivot rows (table has no PK).
     *
     * @return array{removed: int}
     */
    private function dedupePivotRows(bool $dryRun): array
    {
        $table = CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT;
        if (! Schema::hasTable($table)) {
            return ['removed' => 0];
        }

        $duplicates = DB::table($table)
            ->selectRaw('meta_attribute_id, value_type, value_id, COUNT(*) as c')
            ->groupBy('meta_attribute_id', 'value_type', 'value_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $removed = 0;
        foreach ($duplicates as $row) {
            $extra = (int) $row->c - 1;
            $removed += $extra;
            if ($dryRun) {
                continue;
            }

            // No PK: delete all copies and reinsert a single row.
            DB::table($table)
                ->where('meta_attribute_id', $row->meta_attribute_id)
                ->where('value_type', $row->value_type)
                ->where('value_id', $row->value_id)
                ->delete();
            DB::table($table)->insert([
                'meta_attribute_id' => $row->meta_attribute_id,
                'value_type' => $row->value_type,
                'value_id' => $row->value_id,
            ]);
        }

        return ['removed' => $removed];
    }

    /**
     * Remove exact-duplicate assignment rows, keeping the lowest id.
     *
     * @param  array<int, string> $keyColumns
     * @return array{removed: int}
     */
    private function dedupeAssignmentRows(string $table, array $keyColumns, bool $dryRun): array
    {
        if (! Schema::hasTable($table)) {
            return ['removed' => 0];
        }

        $duplicates = DB::table($table)
            ->selectRaw(implode(', ', $keyColumns) . ', COUNT(*) as c, MIN(id) as keep_id')
            ->groupBy($keyColumns)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $removed = 0;
        foreach ($duplicates as $row) {
            $query = DB::table($table)->where('id', '!=', $row->keep_id);
            foreach ($keyColumns as $keyColumn) {
                $value = $row->{$keyColumn};
                $value === null ? $query->whereNull($keyColumn) : $query->where($keyColumn, $value);
            }

            $removed += $dryRun ? ((int) $row->c - 1) : $query->delete();
        }

        return ['removed' => $removed];
    }
}
