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
     * Record the explicit confirmation of a module published by someone other than iSPA: the publisher key
     * as the hub named it, who confirmed and when. Rows of iSPA modules keep all three null.
     */
    public function up(): void
    {
        if (! Schema::hasTable('core_module_installs') || Schema::hasColumn('core_module_installs', 'third_party_publisher')) {
            return;
        }

        Schema::table('core_module_installs', static function (Blueprint $table): void {
            $table->string('third_party_publisher', 64)->nullable();
            $table->unsignedBigInteger('third_party_confirmed_by')->nullable();
            // Nullable: MariaDB on the servers runs with explicit timestamp defaults off.
            $table->timestamp('third_party_confirmed_at')->nullable();
            $table->index(['third_party_publisher', 'status'], 'core_module_installs_third_party_idx');
        });
    }

    /**
     * Drop the columns again.
     */
    public function down(): void
    {
        if (! Schema::hasTable('core_module_installs') || ! Schema::hasColumn('core_module_installs', 'third_party_publisher')) {
            return;
        }

        Schema::table('core_module_installs', static function (Blueprint $table): void {
            $table->dropIndex('core_module_installs_third_party_idx');
            $table->dropColumn(['third_party_publisher', 'third_party_confirmed_by', 'third_party_confirmed_at']);
        });
    }
};
