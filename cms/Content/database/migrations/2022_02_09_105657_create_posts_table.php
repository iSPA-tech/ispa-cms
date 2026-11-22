<?php

use App\Models\User;
use Cms\Content\Constants\ContentTableConstant;
use Cms\Core\Models\Category;
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
        Schema::create(ContentTableConstant::POSTS, static function (Blueprint $table) {
            $table->id();
            $table->uuid()->nullable()->unique();
            $table->string('name', 255)->nullable()->index();
            $table->string('description', 255)->nullable();
            $table->string('hashtag', 500)->nullable()->index();
            $table->longText('content')->nullable();
            $table->char('type', 20)->nullable()->comment('type: page/article...');
            $table->string('video_url', 500)->nullable();
            $table->foreignIdFor(Category::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->references('id')->on(ContentTableConstant::POSTS)->nullOnDelete();
            $table->foreignIdFor(Language::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->json('config')->nullable();
            $table->char('status', 50)->nullable();
            $table->dateTimeTz('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES)->nullOnDelete();
            }

            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText(['name', 'description', 'hashtag', 'content'], sprintf(CoreTableConstant::FULLTEXT_SEARCH, ContentTableConstant::POSTS));
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(ContentTableConstant::POSTS);
    }
};
