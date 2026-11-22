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
        Schema::create(Tables::TRACKING_DAILY, static function (Blueprint $table) {
            $table->id();
            $table->integer('views')->default(0);
            $table->integer('visitors')->default(0);
            $table->integer('place_scan')->default(0);
            $table->integer('item_scan')->default(0);
            $table->integer('scans')->default(0);
            $table->integer('new_register')->default(0);
            $table->integer('login')->default(0);
            $table->integer('logout')->default(0);
            $table->integer('subscribe')->default(0);
            $table->integer('unsubscribe')->default(0);
            $table->integer('new_order')->default(0);
            $table->integer('income')->default(0);
            $table->integer('comments')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Tables::TRACKING_DAILY);
    }
};
