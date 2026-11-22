<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audience and device columns on the Sanctum token table.
 *
 * Nullable columns only, no default, no backfill: tokens issued before tokens carried an audience keep working with every new column
 * null. The missing columns are added in one ALTER so a large table is altered once; each index is added only when
 * missing. down() drops only what up() added.
 */
return new class extends Migration
{
    /** Table altered by this migration. */
    private const TABLE = 'personal_access_tokens';

    /**
     * Add `uuid` (unique), `audience` (indexed), `ip_address`, `user_agent` and `issued_via`.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $definitions = [
            'uuid' => static fn (Blueprint $table) => $table->char('uuid', 36)->nullable(),
            'audience' => static fn (Blueprint $table) => $table->string('audience', 32)->nullable(),
            'ip_address' => static fn (Blueprint $table) => $table->string('ip_address', 45)->nullable(),
            'user_agent' => static fn (Blueprint $table) => $table->string('user_agent', 255)->nullable(),
            'issued_via' => static fn (Blueprint $table) => $table->string('issued_via', 64)->nullable(),
        ];
        $missing = array_filter(
            $definitions,
            static fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column),
            ARRAY_FILTER_USE_KEY,
        );
        if ($missing !== []) {
            Schema::table(self::TABLE, static function (Blueprint $table) use ($missing): void {
                foreach ($missing as $define) {
                    $define($table);
                }
            });
        }

        if (! Schema::hasIndex(self::TABLE, ['uuid'], 'unique')) {
            Schema::table(self::TABLE, static fn (Blueprint $table) => $table->unique('uuid'));
        }
        if (! Schema::hasIndex(self::TABLE, ['audience'])) {
            Schema::table(self::TABLE, static fn (Blueprint $table) => $table->index('audience'));
        }
    }

    /**
     * Drop the indexes, then the five columns, each only when present.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, ['uuid'], 'unique')) {
            Schema::table(self::TABLE, static fn (Blueprint $table) => $table->dropUnique(['uuid']));
        }
        if (Schema::hasIndex(self::TABLE, ['audience'])) {
            Schema::table(self::TABLE, static fn (Blueprint $table) => $table->dropIndex(['audience']));
        }

        $present = array_values(array_filter(
            ['uuid', 'audience', 'ip_address', 'user_agent', 'issued_via'],
            static fn (string $column): bool => Schema::hasColumn(self::TABLE, $column),
        ));
        if ($present !== []) {
            Schema::table(self::TABLE, static fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
