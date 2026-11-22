<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the generic record of long-running admin work.
     *
     * `started_by` holds the user id without a foreign key so the table stays portable across projects.
     */
    public function up(): void
    {
        if (Schema::hasTable(CoreTableConstant::BACKGROUND_TASKS)) {
            return;
        }

        Schema::create(CoreTableConstant::BACKGROUND_TASKS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 100);
            $table->string('module', 64);
            $table->enum('status', ['queued', 'running', 'succeeded', 'failed'])->default('queued');
            $table->unsignedTinyInteger('progress')->nullable();
            $table->string('message', 500)->nullable();
            $table->json('result')->nullable();
            $table->unsignedBigInteger('started_by')->nullable()->index();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['started_by', 'created_at']);
        });
    }

    /**
     * Drop the background tasks table.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::BACKGROUND_TASKS);
    }
};
