<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Constants\UserType;
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
        Schema::create(CoreTableConstant::USERS, static function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('username', 100)->nullable()->unique();
            $table->string('name', 255)->index();
            $table->string('email', 255)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->tinyInteger('type')->default(UserType::USER)->index();
            $table->tinyInteger('level')->default(99)->index();

            $table->foreignId('parent_id')->nullable()->references('id')->on(CoreTableConstant::USERS)->nullOnDelete();
            $table->char('status', 50)->nullable()->index();
            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES);
            }
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_at', 'deleted_at'], '_' . CoreTableConstant::USERS . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::USERS);
    }
};
