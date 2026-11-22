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
     * Record that the person who started an install or upgrade chose to skip the safety backup.
     */
    public function up(): void
    {
        if (! Schema::hasTable('core_module_installs') || Schema::hasColumn('core_module_installs', 'backup_skipped')) {
            return;
        }

        Schema::table('core_module_installs', static function (Blueprint $table): void {
            $table->boolean('backup_skipped')->default(false);
        });
    }

    /**
     * Drop the column again.
     */
    public function down(): void
    {
        if (! Schema::hasTable('core_module_installs') || ! Schema::hasColumn('core_module_installs', 'backup_skipped')) {
            return;
        }

        Schema::table('core_module_installs', static function (Blueprint $table): void {
            $table->dropColumn('backup_skipped');
        });
    }
};
