<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\User;
use Cms\Core\Models\Category;
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
        if (Schema::hasTable(CoreTableConstant::META_CLASSES)) {
            return;
        }

        Schema::create(CoreTableConstant::META_CLASSES, static function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable()->index();
            $table->string('target', 100)->nullable()->comment('Target of Model')->index();
            $table->string('type')->default('class')->index()->comment('class, trait, interface');
            $table->foreignId('parent_id')->nullable()->references('id')->on(CoreTableConstant::META_CLASSES)->cascadeOnDelete();
            $table->foreignIdFor(Category::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::META_CLASSES . '_index');
        });

        Schema::create(CoreTableConstant::META_ATTRIBUTES, static function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable()->index();
            $table->string('label', 100)->nullable();
            $table->string('type', 100)->default('string')->index()->comment('Data type: boolean, string, text, date, tiny_num, big_num');
            $table->string('description', 500)->nullable();
            $table->string('input_type', 100)->nullable()->comment('Type to validate input');
            $table->boolean('is_filter')->default(false);
            $table->boolean('is_enabled')->default(false)->index();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_multiple')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::META_ATTRIBUTES . '_index');
        });

        Schema::create(CoreTableConstant::META_VALUES_BOOLEAN, static function (Blueprint $table) {
            $table->id();
            $table->boolean('value')->default(false);
            $table->string('label')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create(CoreTableConstant::META_VALUES_STRING, static function (Blueprint $table) {
            $table->id();
            $table->string('value', 500)->nullable()->index();
            $table->string('label')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::META_VALUES_STRING . '_index');
        });

        Schema::create(CoreTableConstant::META_VALUES_TEXT, static function (Blueprint $table) {
            $table->id();
            $table->longText('value')->nullable();
            $table->string('label')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create(CoreTableConstant::META_VALUES_DATE, static function (Blueprint $table) {
            $table->id();
            $table->timestampTz('value')->nullable();
            $table->string('label')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create(CoreTableConstant::META_VALUES_TINY_NUM, static function (Blueprint $table) {
            $table->id();
            $table->mediumInteger('value')->default(0);
            $table->string('label')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create(CoreTableConstant::META_VALUES_BIG_NUM, static function (Blueprint $table) {
            $table->id();
            $table->bigInteger('value')->default(0);
            $table->string('label')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::META_VALUES_BOOLEAN);
        Schema::dropIfExists(CoreTableConstant::META_VALUES_STRING);
        Schema::dropIfExists(CoreTableConstant::META_VALUES_TEXT);
        Schema::dropIfExists(CoreTableConstant::META_VALUES_DATE);
        Schema::dropIfExists(CoreTableConstant::META_VALUES_TINY_NUM);
        Schema::dropIfExists(CoreTableConstant::META_VALUES_BIG_NUM);

        Schema::dropIfExists(CoreTableConstant::META_ATTRIBUTES);
        Schema::dropIfExists(CoreTableConstant::META_CLASSES);
    }
};
