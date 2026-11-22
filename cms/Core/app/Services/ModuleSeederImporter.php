<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Importer for PHP-array seeders keyed by a stable unique column.
 *
 * Default mode is create-only (skip existing rows).
 * When $preserveColumns is provided, existing rows are updated
 * with all seed columns EXCEPT the preserved ones and the unique key.
 *
 * @package Cms\Core\Services
 */
class ModuleSeederImporter
{
    private int $chunkSize;

    /**
     * Initialize the importer with a configurable chunk size for batch inserts.
     *
     * @param int $chunkSize Number of rows per insert batch (default: 500).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(int $chunkSize = 500)
    {
        $this->chunkSize = $chunkSize;
    }

    /**
     * Import rows into a table.
     *
     * @param string $table
     * @param array<int, array<string, mixed>> $rows
     * @param string $uniqueKey
     * @param string[] $preserveColumns Columns to preserve on existing rows (upsert mode).
     *                                    Empty array = create-only (default, skip existing).
     * @param string[] $dropInsertColumns Columns to drop from new rows before insert.
     *
     * @return array{table: string, inserted: int, updated: int, skipped: int, total: int}
     */
    public function import(
        string $table,
        array $rows,
        string $uniqueKey = 'id',
        array $preserveColumns = [],
        array $dropInsertColumns = []
    ): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $total = 0;

        $upsertMode = !empty($preserveColumns);

        foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
            $keys = collect($chunk)
                ->map(fn($row) => is_array($row) ? $this->normalizeKey(Arr::get($row, $uniqueKey), $uniqueKey) : null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $existing = empty($keys)
                ? []
                : DB::table($table)->whereIn($uniqueKey, $keys)->pluck($uniqueKey)->all();
            $existingMap = array_flip(array_map(static fn(mixed $value): string => (string) $value, $existing));

            $batchToInsert = [];
            $batchToUpdate = [];
            $dropInsertMap = array_flip($dropInsertColumns);

            foreach ($chunk as $row) {
                ++$total;

                if (!is_array($row)) {
                    ++$skipped;
                    continue;
                }

                $key = $this->normalizeKey(Arr::get($row, $uniqueKey), $uniqueKey);
                if ($key === null) {
                    ++$skipped;
                    continue;
                }

                if (isset($existingMap[(string) $key])) {
                    if ($upsertMode) {
                        $batchToUpdate[] = $row;
                    } else {
                        ++$skipped;
                    }
                    continue;
                }

                $existingMap[(string) $key] = true;
                $batchToInsert[] = empty($dropInsertMap) ? $row : array_diff_key($row, $dropInsertMap);
            }

            if (!empty($batchToInsert)) {
                try {
                    DB::table($table)->insert($batchToInsert);
                    $inserted += count($batchToInsert);
                } catch (UniqueConstraintViolationException) {
                    // Fallback: insert one-by-one, skipping duplicates
                    foreach ($batchToInsert as $singleRow) {
                        try {
                            DB::table($table)->insert($singleRow);
                            ++$inserted;
                        } catch (UniqueConstraintViolationException) {
                            ++$skipped;
                        }
                    }
                }
            }

            if (!empty($batchToUpdate)) {
                $updated += $this->updateExistingRows($table, $uniqueKey, $batchToUpdate, $preserveColumns);
            }
        }

        $summary = [
            'table' => $table,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => $total,
        ];

        $this->syncSequence($table, 'id');

        return $summary;
    }

    private function normalizeKey(mixed $value, string $keyColumn): int|string|null
    {
        if ($keyColumn === 'id') {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return $id === false ? null : $id;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $key = trim((string) $value);

        return $key === '' ? null : $key;
    }

    /**
     * Update existing rows, preserving specified columns.
     *
     * @param string $table
     * @param string $uniqueKey
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $preserveColumns
     *
     * @return int Number of rows updated
     */
    private function updateExistingRows(string $table, string $uniqueKey, array $rows, array $preserveColumns): int
    {
        $exclude = array_flip(array_unique(array_merge(['id', $uniqueKey], $preserveColumns)));
        $count = 0;

        foreach ($rows as $row) {
            $updateData = array_diff_key($row, $exclude);

            $key = $this->normalizeKey(Arr::get($row, $uniqueKey), $uniqueKey);
            if (empty($updateData) || $key === null) {
                continue;
            }

            DB::table($table)
                ->where($uniqueKey, $key)
                ->update($updateData);

            ++$count;
        }

        return $count;
    }

    private function syncSequence(string $table, string $primaryKey): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! DB::getSchemaBuilder()->hasColumn($table, $primaryKey)) {
            return;
        }

        $sequence = DB::selectOne(
            'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
            [$table, $primaryKey]
        );

        $sequenceName = $sequence->sequence_name ?? null;
        if (!is_string($sequenceName) || $sequenceName === '') {
            return;
        }

        $maxId = DB::table($table)->max($primaryKey);
        if (!is_numeric($maxId)) {
            return;
        }

        DB::statement(
            'SELECT setval(?::regclass, ?, true)',
            [$sequenceName, (int)$maxId]
        );
    }
}
