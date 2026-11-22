<?php

use App\Models\User;
use App\Constants\Tables;
use Cms\Core\Models\Enumerate;
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
        Schema::create(CoreTableConstant::STORAGES, static function (Blueprint $table) {
            $table->id();
            $table->uuid()->nullable()->unique();
            $table->char('uid', 10)->nullable()->unique();
            $table->char('code', 20)->nullable()->unique();
            $table->string('name', 255)->nullable();
            $table->date('established_at')->nullable();
            $table->foreignId('established_by')->nullable()->references('id')->on(Tables::USERS)->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->references('id')->on(CoreTableConstant::STORAGES)->nullOnDelete();
            $table->foreignIdFor(Enumerate::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('place_id')->nullable()->index();
            $table->char('status', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();

            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES)->nullOnDelete();
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
        Schema::dropIfExists(CoreTableConstant::STORAGES);
    }
};
