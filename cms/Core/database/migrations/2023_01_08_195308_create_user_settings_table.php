<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\User;
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
        if (Schema::hasTable(CoreTableConstant::USERS_SETTINGS)) {
            return;
        }

        Schema::create(CoreTableConstant::USERS_SETTINGS, static function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('setting_group')->nullable();
            $table->string('setting_key')->nullable();
            $table->text('setting_value')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'setting_group', 'setting_key']);

            $table->index(['created_at'], '_' . CoreTableConstant::USERS_SETTINGS . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::USERS_SETTINGS);
    }
};
