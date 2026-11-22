<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use Cms\Core\Models\MetaClass;
use Cms\Core\Models\MetaAttribute;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        //  Check if table exists
        if (Schema::hasTable(CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT)) {
            return;
        }

        Schema::create(CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT, static function (Blueprint $table) {
            $table->foreignIdFor(MetaClass::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(MetaAttribute::class)->nullable()->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::META_CLASS_ATTRIBUTE_PIVOT);
    }
};
