<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(Tables::SCHEDULER, static function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('command')->nullable();
            $table->json('parameters')->nullable();
            $table->string('frequency')->nullable();
            $table->string('custom_cron')->nullable();
            $table->string('type')->nullable()->comment('Type of run: one-time, recurring');
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('next_run_at')->nullable();
            $table->string('status')->nullable()->comment('Status of the scheduler: active, inactive');
            $table->json('options')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status'], 'idx_type_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Tables::SCHEDULER);
    }
};
