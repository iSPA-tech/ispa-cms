<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use Cms\Core\Models\Field;
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
        Schema::create(CoreTableConstant::FIELD_ITEMS, static function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->foreignIdFor(Field::class)->nullable()->constrained()->nullOnDelete();
            $table->string('field_name', 50)->nullable();
            $table->enum('type', [
                'color', 'text', 'checkbox', 'select', 'radio', 'email', 'range', 'button', 'reset', 'submit',
                'number', 'file', 'date', 'password', 'tel', 'search', 'time', 'url', 'month', 'week', 'datetime',
                'textarea', 'image', 'document', 'editor', 'archived', 'boolean',
            ])->default('text')->comment('Input native type')->index();
            $table->string('description', 500)->nullable();
            $table->text('options')->nullable();
            $table->json('custom')->nullable()->comment('Custom information, exp: id, class name...');
            $table->tinyInteger('priority')->default(0)->index();
            $table->string('name_trans_key')->nullable()->comment('Language translate key');
            $table->string('phd_trans_key')->nullable()->comment('Language translate key');
            $table->boolean('status')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::FIELD_ITEMS . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::FIELD_ITEMS);
    }
};
