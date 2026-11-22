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
        Schema::create(Tables::BANNERS, static function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->enum('type', ['slider_main', 'slider_secondary', 'target', 'popup', '970_90', '728_90', '336_280', '300_600', '320_50'])->nullable();
            $table->string('url', 500)->nullable();
            $table->integer('click')->default(0);
            $table->integer('view')->default(0);
            $table->tinyInteger('limit_by')->default(0)->comment('0: no, 1: by date range, 2: by click, 3: by display');
            $table->dateTimeTz('limit_from')->nullable();
            $table->dateTimeTz('limit_to')->nullable();
            $table->integer('limit_click')->default(0);
            $table->integer('limit_view')->default(0);
            $table->json('custom')->nullable();
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
        Schema::dropIfExists(Tables::BANNERS);
    }
};
