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

return new class extends Migration {
    /**
     * Create the backup destination, backup and backup copy tables (idempotent).
     */
    public function up(): void
    {
        if (!Schema::hasTable(CoreTableConstant::BACKUP_DESTINATIONS)) {
            Schema::create(CoreTableConstant::BACKUP_DESTINATIONS, static function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name', 100)->unique();
                $table->string('driver', 20);
                $table->json('config');
                $table->text('credentials')->nullable();
                $table->unsignedTinyInteger('retention_days')->default(3);
                $table->unsignedTinyInteger('lock_days')->default(0);
                $table->string('schedule', 20)->nullable();
                $table->boolean('use_for_safety')->default(false);
                $table->boolean('enabled')->default(true);
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->string('last_error_code', 64)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable(CoreTableConstant::BACKUPS)) {
            Schema::create(CoreTableConstant::BACKUPS, static function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('reason', 20);
                $table->string('status', 20);
                $table->string('db_driver', 20);
                $table->string('db_name', 128);
                $table->unsignedInteger('tables_expected')->nullable();
                $table->unsignedInteger('tables_dumped')->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->char('sha256', 64)->nullable();
                $table->string('format', 20);
                $table->uuid('background_task_uuid')->nullable();
                $table->unsignedBigInteger('started_by')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('restore_tested_at')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->string('message', 500)->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at'], 'core_backups_status_created_index');
            });
        }

        if (!Schema::hasTable(CoreTableConstant::BACKUP_COPIES)) {
            Schema::create(CoreTableConstant::BACKUP_COPIES, static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('backup_id')->nullable()->constrained(CoreTableConstant::BACKUPS)->cascadeOnDelete();
                $table->foreignId('destination_id')->constrained(CoreTableConstant::BACKUP_DESTINATIONS)->restrictOnDelete();
                $table->string('kind', 10)->default('backup');
                $table->string('path', 1000);
                $table->string('status', 20);
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->timestamp('uploaded_at')->nullable();
                $table->timestamp('prune_attempted_at')->nullable();
                $table->timestamp('pruned_at')->nullable();
                $table->string('error_code', 64)->nullable();
                $table->timestamps();

                $table->index(['destination_id', 'status', 'uploaded_at'], 'core_backup_copies_dest_status_uploaded_index');
            });
        }
    }

    /**
     * Drop the tables in reverse dependency order (idempotent).
     */
    public function down(): void
    {
        foreach ([CoreTableConstant::BACKUP_COPIES, CoreTableConstant::BACKUPS, CoreTableConstant::BACKUP_DESTINATIONS] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
};
