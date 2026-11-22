<?php
use Cms\Core\Constants\CoreTableConstant;
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

use App\Models\User;
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
        Schema::create(CoreTableConstant::AUDIT_LOGS, static function (Blueprint $table) {
            $table->id();
            $table->char('action', 50)->nullable()->index();
            $table->longText('changed')->nullable();
            $table->nullableMorphs('target');
            $table->ipAddress()->nullable();
            $table->tinyText('agent')->nullable();
            $table->string('url', 1000)->nullable();
            $table->text('options')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::AUDIT_LOGS . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::AUDIT_LOGS);
    }
};
