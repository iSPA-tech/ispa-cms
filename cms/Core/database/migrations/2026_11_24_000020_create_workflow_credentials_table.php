<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow credential store: named, encrypted credentials per site.
 * Workflows hold `$bind:<name>` only; the values live here, encrypted with the app key (Laravel `encrypted:array`).
 *
 * `workflow_publish_receipts` makes outward posts idempotent: one row per `receipt_key`, so a retried step never
 * posts twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('workflow_credentials')) {
            Schema::create('workflow_credentials', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name', 64)->unique();
                $table->string('type', 32)->index();
                $table->string('description', 255)->nullable();
                $table->text('payload');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('last_tested_at')->nullable();
                $table->boolean('last_test_ok')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('workflow_publish_receipts')) {
            Schema::create('workflow_publish_receipts', function (Blueprint $table) {
                $table->id();
                $table->string('receipt_key', 191)->unique();
                $table->string('integration', 32);
                $table->string('credential_name', 64)->nullable();
                $table->string('external_id', 191)->nullable();
                $table->string('external_url', 500)->nullable();
                $table->unsignedBigInteger('instance_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_publish_receipts');
        Schema::dropIfExists('workflow_credentials');
    }
};
