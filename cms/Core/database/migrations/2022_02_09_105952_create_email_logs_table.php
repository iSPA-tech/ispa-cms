<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\User;
use Cms\Core\Models\Email;
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
        Schema::create(CoreTableConstant::EMAIL_LOGS, static function (Blueprint $table) {
            $table->id();
            $table->uuid()->nullable()->unique();
            $table->string('type', 20)->nullable()->index()->comment('info, error, debug');
            $table->char('subject', 255)->nullable();
            $table->char('to', 255)->nullable();
            $table->mediumText('cc')->nullable();
            $table->mediumText('bcc')->nullable();
            $table->char('blade', 100)->nullable();
            $table->longText('content')->nullable();
            $table->integer('view')->nullable();
            $table->foreignIdFor(Email::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::EMAIL_LOGS . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::EMAIL_LOGS);
    }
};
