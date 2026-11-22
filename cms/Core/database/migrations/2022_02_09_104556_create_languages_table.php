<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

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
        Schema::create(CoreTableConstant::LANGUAGES, static function (Blueprint $table) {
            $table->id();
            $table->char('name', 100)->nullable()->index();
            $table->char('currency', 10)->nullable();
            $table->char('path', 10)->nullable()->index()->index();
            $table->char('path_alias', 10)->nullable();
            $table->char('code', 10)->nullable();
            $table->string('flag', 500)->nullable();
            $table->char('time_zone', 50)->nullable();
            $table->boolean('default_web')->default(false)->index();
            $table->boolean('default_adm')->default(false)->index();
            $table->boolean('default_app')->default(false)->index();
            $table->boolean('for_web')->default(false)->index();
            $table->boolean('for_adm')->default(false)->index();
            $table->boolean('for_app')->default(false)->index();
            $table->boolean('is_locked')->default(false)->index();
            $table->char('status', 50)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_at', 'deleted_at'], '_' . CoreTableConstant::LANGUAGES . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::LANGUAGES);
    }
};
