<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\User;
use App\Constants\Tables;
use Cms\Core\Models\Language;
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
        Schema::create(Tables::CALENDARS, static function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->dateTimeTz('date_from')->nullable();
            $table->dateTimeTz('date_to')->nullable();
            $table->string('url', 1000)->nullable();
            $table->boolean('is_all_day')->default(false);
            $table->boolean('is_repeat')->default(false);
            $table->tinyInteger('repeat_type')->default(0);
            $table->foreignIdFor(Language::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->char('status', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();

            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES)->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(Tables::CALENDARS);
    }
};
