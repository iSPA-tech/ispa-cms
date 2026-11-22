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
        Schema::create(CoreTableConstant::FIELDS, static function (Blueprint $table) {
            $table->id();
            $table->string('form_id', 32)->nullable()->index();
            $table->string('name', 255)->nullable();
            $table->text('custom')->nullable()->comment('Custom information, exp: id, class name...');
            $table->text('styles')->nullable()->comment('CSS Style');
            $table->char('status', 50)->nullable();
            $table->dateTimeTz('expires_from')->nullable()->index();
            $table->dateTimeTz('expires_to')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES)->nullOnDelete();
            }
            $table->index(['created_at', 'deleted_at'], '_' . CoreTableConstant::FIELDS . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::FIELDS);
    }
};
