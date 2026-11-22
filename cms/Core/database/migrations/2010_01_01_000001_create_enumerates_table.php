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
        Schema::create(CoreTableConstant::ENUMERATES, static function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->string('name_alias', 100)->nullable();
            $table->char('enum_key', 50)->nullable()->unique();
            $table->char('type', 30)->nullable()->index();
            $table->integer('type_id')->nullable()->index();
            $table->char('input_type', 20)->nullable()->index();
            $table->boolean('is_filter')->default(false);
            $table->boolean('status')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['type', 'enum_key'], 'unique_type_key');
            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::ENUMERATES . '_index');

            // enum_key is unique standalone (required by MySQL 9+ for FK references)
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::ENUMERATES);
    }
};
