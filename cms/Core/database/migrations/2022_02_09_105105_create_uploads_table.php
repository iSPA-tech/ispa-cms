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
        Schema::create(CoreTableConstant::UPLOADS, static function (Blueprint $table) {
            $table->id();
            $table->char('disk', 20)->nullable()->comment('disk to store')->index();
            $table->char('type', 20)->nullable()->comment('file type')->index();
            $table->char('mime_type', 255)->nullable()->comment('mime type of file')->index();
            $table->char('path')->nullable()->index();
            $table->string('name', 255)->nullable();
            $table->string('name_alias')->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->integer('file_size')->default(0);
            $table->boolean('main')->default(false)->index();
            $table->longText('exif')->nullable();
            $table->char('target_folder', 50)->nullable();
            $table->string('target_type')->nullable()->index();
            $table->unsignedBigInteger('target_id')->nullable()->index();
            $table->char('category', 20)->nullable()->index();
            $table->string('dimensions', 255)->nullable();
            $table->text('custom')->nullable();
            $table->text('config')->nullable();
            $table->foreignIdFor(User::class)->nullable()->constrained();
            $table->char('status', 50)->nullable();
            if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
                $table->foreign('status')->references('enum_key')->on(CoreTableConstant::ENUMERATES);
            }
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'created_at'], '_' . CoreTableConstant::UPLOADS . '_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::UPLOADS);
    }
};
