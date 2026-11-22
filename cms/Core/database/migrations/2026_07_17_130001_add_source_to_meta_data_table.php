<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for meta assignments: which source last wrote the value
 * ('manual', a crawler source code, 'ai', 'name', …). Null = legacy rows
 * written before provenance existed — they lose against any ranked source
 * exactly once, then carry provenance from that point on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(CoreTableConstant::META_DATA, static function (Blueprint $table): void {
            $table->string('source', 50)->nullable()->index()->after('value_id');
        });
    }

    public function down(): void
    {
        Schema::table(CoreTableConstant::META_DATA, static function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
