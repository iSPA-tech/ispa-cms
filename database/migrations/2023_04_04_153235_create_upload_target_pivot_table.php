<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use Cms\Core\Models\Upload;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(CoreTableConstant::UPLOAD_TARGET_PIVOT, static function (Blueprint $table) {
            $table->foreignIdFor(Upload::class)->nullable()->constrained()->cascadeOnDelete();
            $table->nullableMorphs('target');
            $table->string('category')->nullable();
            $table->boolean('main')->default(false);
            $table->mediumInteger('order')->unsigned()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::UPLOAD_TARGET_PIVOT);
    }
};
