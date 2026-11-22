<?php

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\MetaNormalizationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

return new class extends Migration {
    /**
     * Protect the shared meta value pool with database-level uniqueness.
     *
     * The legacy write path created a new value row per save, so 33% of prod
     * string values were duplicates ("Hotline" existed 273 times). This
     * migration deduplicates the pool (remapping every reference) and then
     * adds unique indexes so the pool cannot degrade again. LONGTEXT values
     * get a value_hash column because LONGTEXT cannot carry a unique index.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable(CoreTableConstant::META_VALUES_STRING)) {
            return;
        }

        // Merge duplicates BEFORE adding unique indexes (idempotent).
        app(MetaNormalizationService::class)->normalize();

        // LONGTEXT values: hash column carries the uniqueness.
        if (! Schema::hasColumn(CoreTableConstant::META_VALUES_TEXT, 'value_hash')) {
            Schema::table(CoreTableConstant::META_VALUES_TEXT, function (Blueprint $table): void {
                $table->char('value_hash', 40)->nullable()->after('value');
            });
        }

        foreach (DB::table(CoreTableConstant::META_VALUES_TEXT)->whereNull('value_hash')->whereNotNull('value')->orderBy('id')->get(['id', 'value']) as $row) {
            DB::table(CoreTableConstant::META_VALUES_TEXT)
                ->where('id', $row->id)
                ->update(['value_hash' => sha1((string) $row->value)]);
        }

        $this->addUniqueIndex(CoreTableConstant::META_VALUES_TEXT, ['value_hash'], 'uq_meta_values_text_hash');
        $this->addUniqueIndex(CoreTableConstant::META_VALUES_STRING, ['value'], 'uq_meta_values_string_value');
        $this->addUniqueIndex(CoreTableConstant::META_VALUES_DATE, ['value'], 'uq_meta_values_date_value');
        $this->addUniqueIndex(CoreTableConstant::META_VALUES_BOOLEAN, ['value'], 'uq_meta_values_boolean_value');
        $this->addUniqueIndex(CoreTableConstant::META_VALUES_TINY_NUM, ['value'], 'uq_meta_values_tiny_num_value');
        $this->addUniqueIndex(CoreTableConstant::META_VALUES_BIG_NUM, ['value'], 'uq_meta_values_big_num_value');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        $this->dropIndexIfExists(CoreTableConstant::META_VALUES_STRING, 'uq_meta_values_string_value');
        $this->dropIndexIfExists(CoreTableConstant::META_VALUES_DATE, 'uq_meta_values_date_value');
        $this->dropIndexIfExists(CoreTableConstant::META_VALUES_BOOLEAN, 'uq_meta_values_boolean_value');
        $this->dropIndexIfExists(CoreTableConstant::META_VALUES_TINY_NUM, 'uq_meta_values_tiny_num_value');
        $this->dropIndexIfExists(CoreTableConstant::META_VALUES_BIG_NUM, 'uq_meta_values_big_num_value');
        $this->dropIndexIfExists(CoreTableConstant::META_VALUES_TEXT, 'uq_meta_values_text_hash');

        if (Schema::hasColumn(CoreTableConstant::META_VALUES_TEXT, 'value_hash')) {
            Schema::table(CoreTableConstant::META_VALUES_TEXT, function (Blueprint $table): void {
                $table->dropColumn('value_hash');
            });
        }
    }

    /**
     * @param array<int, string> $columns
     */
    private function addUniqueIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->unique($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropUnique($name);
            });
        }
    }
};
