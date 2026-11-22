<?php

use Cms\Core\Constants\CoreTableConstant;
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
     * Harden the meta assignment layer:
     *  - meta_attribute_value_pivot gets a primary key, a uniqueness guarantee
     *    and a reverse-lookup index (it previously had no key at all);
     *  - meta_data / user_meta get exact-duplicate protection plus the
     *    attribute-first index that value filters need ("all cars with RWD");
     *  - product_attributes gets one-value-per-attribute-per-product;
     *  - meta_attributes gets a unit column (vehicle specs: kW, Nm, mm …) and
     *    a unique name.
     *
     * varchar morph columns use prefix lengths on MySQL to stay inside the
     * 3072-byte index limit; other drivers (sqlite tests) take plain indexes.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable(CoreTableConstant::META_DATA)) {
            return;
        }

        $isMysql = DB::getDriverName() === 'mysql';

        // --- meta_attribute_value_pivot: PK + unique + reverse lookup -----
        $pivot = CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT;
        if ($isMysql && ! Schema::hasColumn($pivot, 'id')) {
            DB::statement("ALTER TABLE `{$pivot}` ADD COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        }
        $this->addIndex($pivot, 'uq_meta_attr_value_pivot', true, $isMysql
            ? '`meta_attribute_id`, `value_type`(150), `value_id`'
            : ['meta_attribute_id', 'value_type', 'value_id']);
        $this->addIndex($pivot, 'ix_meta_attr_value_pivot_value', false, $isMysql
            ? '`value_type`(150), `value_id`'
            : ['value_type', 'value_id']);

        // --- meta_data: exact-duplicate guard + attribute-first filter index
        $metaData = CoreTableConstant::META_DATA;
        $this->addIndex($metaData, 'uq_meta_data_assignment', true, $isMysql
            ? '`target_type`(120), `target_id`, `meta_attribute_id`, `value_type`(120), `value_id`'
            : ['target_type', 'target_id', 'meta_attribute_id', 'value_type', 'value_id']);
        $this->addIndex($metaData, 'ix_meta_data_attribute_value', false, $isMysql
            ? '`meta_attribute_id`, `value_type`(150), `value_id`'
            : ['meta_attribute_id', 'value_type', 'value_id']);

        // --- user_meta: same guarantees --------------------------------
        $userMeta = CoreTableConstant::USERS_META;
        $this->addIndex($userMeta, 'uq_user_meta_assignment', true, $isMysql
            ? '`user_id`, `meta_attribute_id`, `value_type`(150), `value_id`'
            : ['user_id', 'meta_attribute_id', 'value_type', 'value_id']);

        // --- product_attributes hardening RELOCATED to the Product module
        //     (cms/Product/.../2026_07_18_000001_harden_product_attributes_assignment):
        //     product_attributes is a Product-owned table, and on legacy
        //     databases whose product_attributes predates the
        //     meta_attribute_id column the index must be SKIPPED, not fail
        //     (SQLSTATE 1072). This Core migration now hardens only
        //     Core-owned meta tables.

        // --- meta_attributes: unit + unique name ------------------------
        $attributes = CoreTableConstant::META_ATTRIBUTES;
        if (! Schema::hasColumn($attributes, 'unit')) {
            Schema::table($attributes, function (Blueprint $table): void {
                $table->string('unit', 50)->nullable()->after('input_type')
                    ->comment('Canonical measure unit for numeric attributes (kW, Nm, mm, ...)');
            });
        }

        $duplicateNames = DB::table($attributes)
            ->select('name')
            ->whereNotNull('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if (! $duplicateNames) {
            $this->addIndex($attributes, 'uq_meta_attributes_name', true, ['name']);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        foreach ([
            CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT => ['uq_meta_attr_value_pivot', 'ix_meta_attr_value_pivot_value'],
            CoreTableConstant::META_DATA => ['uq_meta_data_assignment', 'ix_meta_data_attribute_value'],
            CoreTableConstant::USERS_META => ['uq_user_meta_assignment'],
            CoreTableConstant::META_ATTRIBUTES => ['uq_meta_attributes_name'],
        ] as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $index) {
                if (Schema::hasIndex($table, $index)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
                }
            }
        }

        if (Schema::hasColumn(CoreTableConstant::META_ATTRIBUTES, 'unit')) {
            Schema::table(CoreTableConstant::META_ATTRIBUTES, function (Blueprint $table): void {
                $table->dropColumn('unit');
            });
        }
    }

    /**
     * Add an index, driver-aware: MySQL takes a raw column list (allows
     * prefix lengths on varchar morph columns), other drivers a Blueprint.
     *
     * @param array<int, string>|string $columns
     */
    private function addIndex(string $table, string $name, bool $unique, array|string $columns): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }

        if (is_string($columns)) {
            $type = $unique ? 'UNIQUE' : 'INDEX';
            DB::statement("ALTER TABLE `{$table}` ADD {$type} `{$name}` ({$columns})");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
            $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name);
        });
    }
};
