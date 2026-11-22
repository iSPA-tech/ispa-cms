<?php

use App\Models\User;
use App\Constants\Tables;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Constants\CoreTableConstant;
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
        Schema::create(Tables::CONTACTS, static function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('organization', 100)->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('source', 50)->nullable();
            $table->string('type', 20)->nullable();
            $table->dateTimeTz('birthday')->nullable();
            $table->tinyinteger('gender')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_subscribe')->default(false);
            $table->char('status', 50)->nullable();

            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES);
            }

            $table->timestamps();
            $table->softDeletes();

            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText(['name', 'email', 'mobile', 'address', 'type'], sprintf(Tables::FULLTEXT_SEARCH, Tables::CONTACTS));
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
        Schema::dropIfExists(Tables::CONTACTS);
    }
};
