<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record whether an install row is a first install or an upgrade, and the version an upgrade replaced.
     */
    public function up(): void
    {
        if (! Schema::hasTable('core_module_installs')) {
            return;
        }

        if (! Schema::hasColumn('core_module_installs', 'kind')) {
            Schema::table('core_module_installs', static function (Blueprint $table): void {
                $table->string('kind', 16)->default('install');
            });
        }
        if (! Schema::hasColumn('core_module_installs', 'previous_version')) {
            Schema::table('core_module_installs', static function (Blueprint $table): void {
                $table->string('previous_version', 32)->nullable();
            });
        }
    }

    /**
     * Drop the upgrade columns again.
     */
    public function down(): void
    {
        if (! Schema::hasTable('core_module_installs')) {
            return;
        }

        foreach (['previous_version', 'kind'] as $column) {
            if (Schema::hasColumn('core_module_installs', $column)) {
                Schema::table('core_module_installs', static function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
