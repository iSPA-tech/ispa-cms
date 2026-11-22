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
     * Create the record of runtime module installs (one row per install request).
     *
     * `status` is a short string (see Cms\Core\Models\ModuleInstall::STATUSES) rather than an enum so later install
     * steps can add states without a schema change. `requested_by` holds the user id without a foreign key so the
     * table stays portable across projects.
     */
    public function up(): void
    {
        if (Schema::hasTable('core_module_installs')) {
            return;
        }

        Schema::create('core_module_installs', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 64);
            $table->string('version', 32);
            $table->string('source', 16)->default('hub');
            $table->string('status', 20)->default('queued')->index();
            $table->string('step_message', 500)->nullable();
            $table->char('zip_sha256', 64)->nullable();
            $table->string('key_id', 16)->nullable();
            $table->uuid('backup_uuid')->nullable();
            $table->uuid('background_task_uuid')->nullable()->index();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->boolean('confirmed_irreversible')->default(false);
            $table->json('warnings')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamps();

            $table->index(['name', 'version']);
        });
    }

    /**
     * Drop the module installs table.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_module_installs');
    }
};
