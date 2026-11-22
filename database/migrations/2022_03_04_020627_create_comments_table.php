<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\User;
use App\Models\Contact;
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
        Schema::create(Tables::COMMENTS, static function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable()->comment('name of commenter');
            $table->string('subject', 255)->nullable()->comment('subject of comment (if exists)');
            $table->text('content')->nullable();
            $table->nullableMorphs('target');
            $table->tinyInteger('vote')->default(0);
            $table->char('status', 50)->nullable();
            $table->foreignId('reply_to')->nullable()->references('id')->on(Tables::COMMENTS)->nullOnDelete();
            $table->foreignIdFor(Contact::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->dateTimeTz('last_view')->nullable();
            $table->timestamps();
            $table->softDeletes();

            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText(['name', 'subject', 'content'], sprintf(Tables::FULLTEXT_SEARCH, Tables::COMMENTS));
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
        Schema::dropIfExists(Tables::COMMENTS);
    }
};
