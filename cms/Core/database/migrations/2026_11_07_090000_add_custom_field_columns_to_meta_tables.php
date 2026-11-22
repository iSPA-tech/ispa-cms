<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Add the columns custom fields need to the meta layer.
 *
 * meta_attributes gains a stable uuid, bilingual labels and help, validation
 * rules, the is_custom marker, list/search flags and an archive timestamp.
 * meta_classes gains a resource_key that ties one class to one admin
 * resource. Every new column is nullable or defaults to false, so existing
 * rows keep their meaning. Every step is guarded: running up() twice is a
 * no-op.
 */
return new class extends Migration {
    /** Unique index on meta_attributes.uuid. */
    private const UQ_ATTRIBUTE_UUID = 'uq_meta_attributes_uuid';

    /** Index on meta_attributes.is_custom. */
    private const IX_ATTRIBUTE_IS_CUSTOM = 'ix_meta_attributes_is_custom';

    /** Unique index on meta_classes.resource_key. */
    private const UQ_CLASS_RESOURCE_KEY = 'uq_meta_classes_resource_key';

    /** Rows per uuid backfill batch. */
    private const BACKFILL_CHUNK = 500;

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        $attributes = CoreTableConstant::META_ATTRIBUTES;
        $classes = CoreTableConstant::META_CLASSES;

        if (Schema::hasTable($attributes)) {
            $this->addColumn($attributes, 'uuid', fn (Blueprint $t) => $t->char('uuid', 36)->nullable());
            $this->addColumn($attributes, 'labels', fn (Blueprint $t) => $t->json('labels')->nullable()
                ->comment('Display labels per locale: {vi, en}'));
            $this->addColumn($attributes, 'help', fn (Blueprint $t) => $t->json('help')->nullable()
                ->comment('Help text per locale: {vi, en}'));
            $this->addColumn($attributes, 'rules', fn (Blueprint $t) => $t->json('rules')->nullable()
                ->comment('Validation rules for custom field values'));
            $this->addColumn($attributes, 'is_custom', fn (Blueprint $t) => $t->boolean('is_custom')->default(false));
            $this->addColumn($attributes, 'show_in_list', fn (Blueprint $t) => $t->boolean('show_in_list')->default(false));
            $this->addColumn($attributes, 'is_searchable', fn (Blueprint $t) => $t->boolean('is_searchable')->default(false));
            $this->addColumn($attributes, 'archived_at', fn (Blueprint $t) => $t->timestamp('archived_at')->nullable());

            $this->backfillUuids($attributes);

            $this->addIndex($attributes, self::UQ_ATTRIBUTE_UUID, true, ['uuid']);
            $this->addIndex($attributes, self::IX_ATTRIBUTE_IS_CUSTOM, false, ['is_custom']);
        }

        if (Schema::hasTable($classes)) {
            $this->addColumn($classes, 'resource_key', fn (Blueprint $t) => $t->string('resource_key', 100)->nullable()
                ->comment('Admin resource this class holds custom fields for'));
            $this->addIndex($classes, self::UQ_CLASS_RESOURCE_KEY, true, ['resource_key']);
        }
    }

    /**
     * Reverse the migrations: drop only the columns and indexes added above.
     *
     * @return void
     */
    public function down(): void
    {
        $attributes = CoreTableConstant::META_ATTRIBUTES;
        $classes = CoreTableConstant::META_CLASSES;

        // Indexes go first: SQLite refuses to drop an indexed column.
        $this->dropIndex($attributes, self::UQ_ATTRIBUTE_UUID, true);
        $this->dropIndex($attributes, self::IX_ATTRIBUTE_IS_CUSTOM, false);
        $this->dropIndex($classes, self::UQ_CLASS_RESOURCE_KEY, true);

        foreach (['uuid', 'labels', 'help', 'rules', 'is_custom', 'show_in_list', 'is_searchable', 'archived_at'] as $column) {
            $this->dropColumn($attributes, $column);
        }

        $this->dropColumn($classes, 'resource_key');
    }

    /**
     * Give every attribute row without a uuid one, soft-deleted rows included.
     *
     * Writes only the uuid column through the query builder, so updated_at
     * and every other column stay exactly as they were.
     *
     * @param string $table
     * @return void
     */
    private function backfillUuids(string $table): void
    {
        DB::table($table)
            ->select('id')
            ->whereNull('uuid')
            ->chunkById(self::BACKFILL_CHUNK, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->whereNull('uuid')
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });
    }

    /**
     * Add one column when it does not exist yet.
     *
     * @param string   $table
     * @param string   $column
     * @param callable $definition Receives the Blueprint and defines the column.
     * @return void
     */
    private function addColumn(string $table, string $column, callable $definition): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($definition): void {
            $definition($blueprint);
        });
    }

    /**
     * Drop one column when the table and column exist.
     *
     * @param string $table
     * @param string $column
     * @return void
     */
    private function dropColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }

    /**
     * Add an index when it does not exist yet.
     *
     * @param string             $table
     * @param string             $name
     * @param bool               $unique
     * @param array<int, string> $columns
     * @return void
     */
    private function addIndex(string $table, string $name, bool $unique, array $columns): void
    {
        if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $unique): void {
            $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name);
        });
    }

    /**
     * Drop an index when it exists.
     *
     * @param string $table
     * @param string $name
     * @param bool   $unique
     * @return void
     */
    private function dropIndex(string $table, string $name, bool $unique): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $unique): void {
            $unique ? $blueprint->dropUnique($name) : $blueprint->dropIndex($name);
        });
    }
};
