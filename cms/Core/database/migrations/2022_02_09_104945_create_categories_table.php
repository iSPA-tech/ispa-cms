<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

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
        Schema::create(CoreTableConstant::CATEGORIES, static function (Blueprint $table) {
            $table->id();
            $table->char('uid', 10)->nullable()->unique();
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->longText('content')->nullable();
            $table->string('hashtag', 500)->nullable();
            $table->foreignId('parent_id')->nullable()->references('id')->on(CoreTableConstant::CATEGORIES)->nullOnDelete();
            $table->foreignIdFor(Language::class)->nullable()->constrained()->nullOnDelete();
            $table->tinyInteger('priority')->default(0)->index();
            $table->tinyInteger('type')->default(0)->index()->comment('0: news, 1: place, 2: gallery, 3: , 4: product, 5: file');
            $table->boolean('featured')->default(false)->index();
            $table->char('status', 50)->nullable()->index()->comment('enum key in enumerates table');
            $table->text('config')->nullable();
            $table->timestamps();
            $table->softDeletes();

            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES)->nullOnDelete();
            }

            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::CATEGORIES . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::CATEGORIES);
    }
};
