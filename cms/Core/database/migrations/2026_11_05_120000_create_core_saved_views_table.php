<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the saved views table.
     *
     * One row is a named filter state of one admin list (`list_key`, see Cms\Core\SavedViews\SavedViewRegistry),
     * owned by `user_id` and optionally shared with everyone who may view that list. Cap policy: at most 20 views
     * per user per list, enforced by the saved views controller. `user_id` has no foreign key so the table stays
     * portable across projects.
     */
    public function up(): void
    {
        if (Schema::hasTable(CoreTableConstant::SAVED_VIEWS)) {
            return;
        }

        Schema::create(CoreTableConstant::SAVED_VIEWS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('list_key', 150);
            $table->string('name', 80);
            $table->json('state');
            $table->boolean('is_shared')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['list_key', 'is_shared']);
            $table->index(['user_id', 'list_key']);
        });
    }

    /**
     * Drop the saved views table.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::SAVED_VIEWS);
    }
};
