<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\User;
use Cms\Core\Models\MetaClass;
use Cms\Core\Models\MetaAttribute;
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
        //  Check if table exists
        if (Schema::hasTable(CoreTableConstant::USERS_META)) {
            return;
        }

        Schema::create(CoreTableConstant::USERS_META, static function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(MetaClass::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(MetaAttribute::class)->nullable()->constrained()->nullOnDelete();
            $table->string('value_type')->nullable();
            $table->integer('value_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::USERS_META);
    }
};
