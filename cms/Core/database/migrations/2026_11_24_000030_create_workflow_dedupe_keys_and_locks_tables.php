<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the workflow `dedupe` node (keys seen per project + scope, with expiry; key hashes only)
 * and the `lock` node (named lock per project, owned by a workflow instance, with expiry).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('workflow_dedupe_keys')) {
            Schema::create('workflow_dedupe_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('scope', 64)->default('default');
                $table->char('key_hash', 64);
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['project_id', 'scope', 'key_hash'], 'workflow_dedupe_keys_unique');
            });
        }

        if (!Schema::hasTable('workflow_locks')) {
            Schema::create('workflow_locks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->char('lock_key', 64);
                $table->unsignedBigInteger('owner_instance_id')->nullable()->index();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->unique(['project_id', 'lock_key'], 'workflow_locks_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_locks');
        Schema::dropIfExists('workflow_dedupe_keys');
    }
};
