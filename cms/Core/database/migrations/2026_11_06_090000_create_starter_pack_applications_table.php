<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the record of starter packs applied to a site.
     *
     * `applied_by` holds the user id without a foreign key so the table stays portable across projects.
     */
    public function up(): void
    {
        if (Schema::hasTable(CoreTableConstant::STARTER_PACK_APPLICATIONS)) {
            return;
        }

        Schema::create(CoreTableConstant::STARTER_PACK_APPLICATIONS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('pack_key', 100);
            $table->string('pack_version', 32);
            $table->enum('status', ['started', 'succeeded', 'failed', 'dry_run'])->default('started');
            $table->json('steps')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->string('background_task_uuid', 36)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['pack_key', 'status']);
        });
    }

    /**
     * Drop the starter pack applications table.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::STARTER_PACK_APPLICATIONS);
    }
};
