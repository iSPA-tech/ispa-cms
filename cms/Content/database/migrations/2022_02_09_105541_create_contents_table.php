<?php

use App\Models\User;
use Cms\Content\Constants\ContentTableConstant;
use Cms\Core\Models\Language;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create(ContentTableConstant::CONTENTS, static function (Blueprint $table) {
            $table->id();
            $table->uuid()->nullable()->unique();
            $table->string('name', 255)->nullable();
            $table->string('description', 500)->nullable();
            $table->longText('content')->nullable();
            $table->string('video_url', 500)->nullable();
            $table->nullableMorphs('target');
            $table->dateTimeTz('published_at')->nullable();
            $table->foreignIdFor(Language::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->char('status', 50)->nullable();
            $table->boolean('is_confirmed')->default(false);
            $table->timestamps();
            $table->softDeletes();

            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES)->nullOnDelete();
            }

            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText(['name', 'description', 'content'], sprintf(CoreTableConstant::FULLTEXT_SEARCH, ContentTableConstant::CONTENTS));
            }

            $table->unique(['target_id', 'target_type', 'language_id'], 'unique_target_language');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(ContentTableConstant::CONTENTS);
    }
};
