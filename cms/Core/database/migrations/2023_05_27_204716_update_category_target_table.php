<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Constants\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(Tables::CATEGORY_TARGET, static function (Blueprint $table) {
            $table->boolean('is_root')->after('target_id')->default(false);
            $table->boolean('is_leaf')->after('is_root')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(Tables::CATEGORY_TARGET, static function (Blueprint $table) {
            $table->dropColumn('is_root');
            $table->dropColumn('is_leaf');
        });
    }
};
