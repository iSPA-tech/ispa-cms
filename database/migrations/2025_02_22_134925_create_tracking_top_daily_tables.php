<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Constants\Tables;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(Tables::TRACKING_TOP_DAILY, static function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();
            $table->morphs('target');
            $table->integer('total_views')->default(0);
            $table->timestamps();

            $table->index(['date', 'target_id', 'target_type'], 'idx_date_target');
        });

        Schema::create(Tables::TRACKING_TOP, static function (Blueprint $table) {
            $table->id();
            $table->string('range_name', 50)->nullable();
            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();
            $table->morphs('target');
            $table->integer('total_views')->default(0);
            $table->integer('rank')->default(0);
            $table->timestamps();

            $table->index(['range_name', 'target_type', 'target_id', 'total_views'], 'idx_range_name_type_views');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Tables::TRACKING_TOP_DAILY);
        Schema::dropIfExists(Tables::TRACKING_TOP);
    }
};
