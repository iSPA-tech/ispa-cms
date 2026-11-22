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
     * Create the per-site admin schema overlay table (one row per resource key).
     *
     * `hidden` and `order` hold lists of field names, `labels` holds {field: {locale: text}}. Nothing in the admin
     * writes this table yet; it is reserved for the overlay stage of the schema pipeline.
     */
    public function up(): void
    {
        if (Schema::hasTable('resource_overlays')) {
            return;
        }

        Schema::create('resource_overlays', static function (Blueprint $table): void {
            $table->id();
            $table->string('resource_key', 100)->unique();
            $table->json('hidden')->nullable();
            $table->json('order')->nullable();
            $table->json('labels')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /** Drop the overlay table. */
    public function down(): void
    {
        Schema::dropIfExists('resource_overlays');
    }
};
