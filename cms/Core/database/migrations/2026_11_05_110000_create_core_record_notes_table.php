<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the internal record notes table.
     *
     * `target_type` holds the record type KEY from Cms\Core\Records\RecordRegistry (not a model class), so notes
     * survive model renames. `mentions` holds the uuids of the users validly mentioned. `user_id` is the author,
     * stored without a foreign key so the table stays portable across projects.
     */
    public function up(): void
    {
        if (Schema::hasTable('core_record_notes')) {
            return;
        }

        Schema::create('core_record_notes', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('target_type', 150);
            $table->unsignedBigInteger('target_id');
            $table->text('body');
            $table->json('mentions')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->dateTime('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['target_type', 'target_id', 'id']);
        });
    }

    /**
     * Drop the record notes table.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_record_notes');
    }
};
