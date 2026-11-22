<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Constants\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Align the live SEO uniqueness contract with the canonical schema.
     *
     * Older databases may still have `seo_target` defined only on
     * `target_type,target_id`. The current CMS stores one SEO row per target and
     * language, so the unique key must include `language_id` to let article edit
     * requests persist multilingual SEO payloads safely.
     */
    public function up(): void
    {
        if (!Schema::hasTable(Tables::SEOS) || $this->seoTargetColumns() === ['target_type', 'target_id', 'language_id']) {
            return;
        }

        $this->replaceSeoTargetIndex(['target_type', 'target_id', 'language_id']);
    }

    /**
     * Roll back to the legacy target-only key when the data shape allows it.
     *
     * The rollback intentionally checks for multi-language duplicates first. A
     * target-only unique index cannot be recreated once multiple SEO languages
     * exist for the same target without losing data.
     */
    public function down(): void
    {
        if (!Schema::hasTable(Tables::SEOS) || $this->seoTargetColumns() === ['target_type', 'target_id']) {
            return;
        }

        $duplicateTargets = DB::table(Tables::SEOS)
            ->select('target_type', 'target_id')
            ->whereNotNull('target_type')
            ->whereNotNull('target_id')
            ->groupBy('target_type', 'target_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateTargets) {
            return;
        }

        $this->replaceSeoTargetIndex(['target_type', 'target_id']);
    }

    /**
     * Read the ordered column list for the `seo_target` index.
     *
     * @return array<int, string>
     */
    private function seoTargetColumns(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_info('seo_target')"))
                ->sortBy('seqno')
                ->pluck('name')
                ->values()
                ->all();
        }

        if ($driver === 'pgsql') {
            return collect(DB::select(
                <<<'SQL'
                SELECT a.attname AS column_name, array_position(i.indkey, a.attnum) AS seq_in_index
                FROM pg_class t
                JOIN pg_index i ON t.oid = i.indrelid
                JOIN pg_class ix ON ix.oid = i.indexrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(i.indkey)
                WHERE t.relname = ? AND ix.relname = ?
                SQL,
                [Tables::SEOS, 'seo_target']
            ))
                ->sortBy('seq_in_index')
                ->pluck('column_name')
                ->values()
                ->all();
        }

        return collect(DB::select('SHOW INDEX FROM `' . Tables::SEOS . '` WHERE Key_name = ?', ['seo_target']))
            ->sortBy('Seq_in_index')
            ->pluck('Column_name')
            ->values()
            ->all();
    }

    /**
     * Replace the `seo_target` unique index using database-specific SQL.
     *
     * SQLite does not support MySQL's `ALTER TABLE ... DROP INDEX` syntax, so
     * CI must use `DROP INDEX` / `CREATE UNIQUE INDEX` directly.
     *
     * @param array<int, string> $columns
     */
    private function replaceSeoTargetIndex(array $columns): void
    {
        $this->dropSeoTargetIndex();
        $this->createSeoTargetIndex($columns);
    }

    /**
     * Drop the existing `seo_target` index for the active database driver.
     */
    private function dropSeoTargetIndex(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => DB::statement('DROP INDEX IF EXISTS "seo_target"'),
            'pgsql' => DB::statement('DROP INDEX IF EXISTS "seo_target"'),
            default => $this->dropMySqlSeoTargetIndex(),
        };
    }

    private function dropMySqlSeoTargetIndex(): void
    {
        if (!Schema::hasIndex(Tables::SEOS, 'seo_target')) {
            return;
        }

        DB::statement('ALTER TABLE `' . Tables::SEOS . '` DROP INDEX `seo_target`');
    }

    /**
     * Create the replacement unique index for the active database driver.
     *
     * @param array<int, string> $columns
     */
    private function createSeoTargetIndex(array $columns): void
    {
        if (DB::connection()->getDriverName() === 'sqlite' || DB::connection()->getDriverName() === 'pgsql') {
            $quotedColumns = implode(', ', array_map(static fn (string $column): string => '"' . $column . '"', $columns));
            DB::statement('CREATE UNIQUE INDEX "seo_target" ON "' . Tables::SEOS . '" (' . $quotedColumns . ')');
            return;
        }

        $quotedColumns = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        DB::statement('ALTER TABLE `' . Tables::SEOS . '` ADD UNIQUE `seo_target` (' . $quotedColumns . ')');
    }
};
