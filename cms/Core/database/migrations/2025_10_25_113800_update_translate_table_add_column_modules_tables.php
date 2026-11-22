<?php

use Illuminate\Support\Facades\Schema;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

return new class extends Migration {
    public function up(): void
    {
        Schema::table(CoreTableConstant::TRANSLATES, static function (Blueprint $table) {
            $table->text('modules')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table(CoreTableConstant::TRANSLATES, static function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
