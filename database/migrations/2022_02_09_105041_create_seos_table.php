<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Constants\Tables;
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
        Schema::create(Tables::SEOS, static function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('target');
            $table->foreignId('language_id')
                ->nullable()->constrained(Tables::LANGUAGES)->onDelete('cascade');
            $table->string('title')->nullable();
            $table->string('description', 500)->nullable();
            $table->string('hashtag', 500)->nullable();

            $table->json('facebook')->nullable()->comment('Json data');
            $table->json('twitter')->nullable()->comment('Json data');
            $table->json('custom')->nullable()->comment('Json data');
            $table->text('config')->nullable();

            $table->timestamps();

            $table->unique(['target_type', 'target_id', 'language_id'], 'seo_target');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(Tables::SEOS);
    }
};
