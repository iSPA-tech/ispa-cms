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
        Schema::table(Tables::TRACKS, static function (Blueprint $table) {
            $table->index([
                'created_at', 'session_id', 'ip_address',
            ]);
        });

        Schema::table(Tables::COMMENTS, static function (Blueprint $table) {
            $table->index([
                'created_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(Tables::TRACKS, static function (Blueprint $table) {
            $table->dropIndex(['created_at', 'session_id', 'ip_address']);
        });

        Schema::table(Tables::COMMENTS, static function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
