<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use Cms\Core\Models\Menu;
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
        Schema::create(CoreTableConstant::MENU_ITEMS, static function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Menu::class)->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('title')->nullable();
            $table->string('url', 500)->nullable();
            $table->char('type', 30)->nullable()->index();
            $table->nullableMorphs('target');
            $table->foreignId('parent_id')->nullable()->references('id')->on(CoreTableConstant::MENU_ITEMS)->nullOnDelete();
            $table->smallInteger('priority')->default(0)->index();
            $table->text('custom')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::MENU_ITEMS . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::MENU_ITEMS);
    }
};
