<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the record of config package runs (export, dry run, apply).
     *
     * The payload is gzip + base64 text and is pruned after a retention window; the metadata row stays.
     */
    public function up(): void
    {
        if (Schema::hasTable(CoreTableConstant::CONFIG_PACKAGE_RUNS)) {
            return;
        }

        Schema::create(CoreTableConstant::CONFIG_PACKAGE_RUNS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('kind', ['export', 'dry_run', 'apply']);
            $table->enum('status', ['queued', 'running', 'succeeded', 'failed'])->default('queued');
            $table->json('sections');
            $table->string('package_checksum', 80)->nullable();
            $table->longText('payload')->nullable();
            $table->json('summary')->nullable();
            $table->json('plan')->nullable();
            $table->unsignedBigInteger('parent_run_id')->nullable()->index();
            $table->uuid('background_task_uuid')->nullable()->index();
            $table->string('backup_ref')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop the config package runs table.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::CONFIG_PACKAGE_RUNS);
    }
};
