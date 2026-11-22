<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Constants\Tables;
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
        Schema::create(Tables::SLUGS, static function (Blueprint $table) {
            $table->id();
            $table->string('full_uri', 1000)->nullable();
            $table->string('slug', 255)->nullable()->index();
            $table->char('category', 20)->nullable()->index()->comment('defined by target_type, set null for specific url, use for permalink');
            $table->nullableMorphs('target');
            $table->foreignIdFor(Language::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['slug', 'target_type'], 'slug_target_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(Tables::SLUGS);
    }
};
