<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema helper for tests that hand-roll a table for a model using
 * ResolvesAppendedRelations.
 *
 * That scope eager loads `latestSlug` and `image`, so a query against Category,
 * Post, Product, Brand, Place, Gallery or a RealEstate model now also reads
 * `slugs`, `uploads` and `upload_target_pivot` — even when the test never
 * mentions a slug or an image. Only the columns those eager loads touch are
 * created.
 *
 * @package Cms\Core\Tests\Support
 * @author  iSPA Team
 * @since   8.1.0 (2026-08-03)
 */
final class AppendedRelationSchema
{
    /**
     * Create the missing supporting tables on the current default connection.
     *
     * @return void
     */
    public static function ensure(): void
    {
        if (! Schema::hasTable('slugs')) {
            Schema::create('slugs', static function (Blueprint $table): void {
                $table->id();
                $table->string('full_uri')->nullable();
                $table->string('slug')->nullable();
                $table->string('category')->nullable();
                $table->nullableMorphs('target');
                $table->unsignedBigInteger('language_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('uploads')) {
            Schema::create('uploads', static function (Blueprint $table): void {
                $table->id();
                $table->string('disk')->nullable();
                $table->string('type')->nullable();
                $table->string('mime_type')->nullable();
                $table->string('path')->nullable();
                $table->string('name')->nullable();
                $table->integer('file_size')->default(0);
                $table->boolean('main')->default(false);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('upload_target_pivot')) {
            Schema::create('upload_target_pivot', static function (Blueprint $table): void {
                $table->unsignedBigInteger('upload_id')->nullable();
                $table->string('target_type')->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('category')->nullable();
                $table->boolean('main')->default(false);
                $table->unsignedMediumInteger('order')->default(0);
            });
        }
    }
}
