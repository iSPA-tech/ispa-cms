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
     * One-tap review 2026-11-22 (I2): a site that already has credentials asks before going to the hub; the admin's
     * "replace" answer is kept on the attempt so the callback does not ask a second time.
     */
    public function up(): void
    {
        if (! Schema::hasTable('core_hub_connect_attempts') || Schema::hasColumn('core_hub_connect_attempts', 'replace_confirmed')) {
            return;
        }

        Schema::table('core_hub_connect_attempts', static function (Blueprint $table): void {
            $table->boolean('replace_confirmed')->default(false)->after('user_id');
        });
    }

    /**
     * Drop the column again.
     */
    public function down(): void
    {
        if (Schema::hasColumn('core_hub_connect_attempts', 'replace_confirmed')) {
            Schema::table('core_hub_connect_attempts', static fn (Blueprint $table) => $table->dropColumn('replace_confirmed'));
        }
    }
};
