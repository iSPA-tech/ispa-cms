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
    public function up(): void
    {
        Schema::create(CoreTableConstant::WIDGET, static function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->text('js')->nullable();
            $table->timestamps();

            $table->index(['created_at'], '_' . CoreTableConstant::WIDGET . '_index');
        });

        Schema::create(CoreTableConstant::WIDGET_THEME, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('widget_id')->nullable()->constrained(CoreTableConstant::WIDGET)->cascadeOnDelete();
            $table->string('name')->nullable()->index();
            $table->text('content')->comment('HTML hoặc markup, shortcode');
            $table->text('css')->nullable();
            $table->json('query')->nullable();
            $table->json('project_name')->nullable()->comment('Tên dự án');
            $table->timestamps();

            $table->index(['created_at'], '_' . CoreTableConstant::WIDGET_THEME . '_index');
        });

        Schema::create(CoreTableConstant::WIDGET_INPUT, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('widget_id')->nullable()->constrained(CoreTableConstant::WIDGET)->cascadeOnDelete();
            $table->string('name')->nullable()->comment('Input name');
            $table->string('type')->nullable()->index(); // text, select, number, color
            $table->text('default_value')->nullable();
            $table->text('options')->nullable();
            $table->timestamps();

            $table->index(['created_at'], '_' . CoreTableConstant::WIDGET_INPUT . '_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::WIDGET_INPUT);
        Schema::dropIfExists(CoreTableConstant::WIDGET_THEME);
        Schema::dropIfExists(CoreTableConstant::WIDGET);
    }
};
