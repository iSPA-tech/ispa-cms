<?php

use App\Constants\Tables;
use Cms\Core\Models\Language;
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
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create(Tables::PROVINCES, static function (Blueprint $table) {
            $table->id();
            $table->uuid()->nullable()->unique();
            $table->char('uid', 10)->nullable()->unique();
            $table->string('name', 255)->nullable()->index();
            $table->string('global_name', 255)->nullable()->index();
            $table->string('slug', 255)->nullable()->index();
            $table->tinyInteger('type')->default(0);
            $table->foreignId('parent_id')->nullable()->references('id')->on(Tables::PROVINCES)->nullOnDelete();
            $table->foreignIdFor(Language::class)->nullable()->constrained();
            $table->string('maps_id', 255)->nullable();
            $table->char('longitude', 20)->nullable();
            $table->char('latitude', 20)->nullable();
            $table->tinyInteger('priority')->default(0);
            $table->text('config')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();

            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES)->nullOnDelete();
            }

            // Only apply fulltext in MySQL
            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText(['name', 'global_name', 'config'], sprintf(Tables::FULLTEXT_SEARCH, Tables::PROVINCES));
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
        Schema::dropIfExists(Tables::PROVINCES);
    }
};
