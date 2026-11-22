<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\User;
use App\Models\Contact;
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
        Schema::create(CoreTableConstant::EMAILS, static function (Blueprint $table) {
            $table->id();
            $table->uuid()->nullable()->unique();
            $table->string('subject', 255)->nullable();
            $table->string('name', 255)->nullable()->comment('Receiver name');
            $table->string('email', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 255)->nullable();
            $table->string('reply_to', 255)->nullable()->comment('set email address to reply');
            $table->string('cc', 500)->nullable();
            $table->string('bcc', 500)->nullable();
            $table->text('content')->nullable();
            $table->text('raw_data')->nullable();
            $table->foreignIdFor(Contact::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reply_id')->nullable()->references('id')->on(CoreTableConstant::EMAILS)->nullOnDelete();
            $table->dateTimeTz('last_read')->nullable()->index();
            $table->string('type', 20)->nullable()->index()->comment('inbound, outbound');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_at', 'deleted_at'], '_' . CoreTableConstant::EMAILS . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::EMAILS);
    }
};
