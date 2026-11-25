<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Webhook trigger (lane N3): the public path token of a project's webhook URL and its secret (encrypted by the model
 * cast). Both are issued from the admin; a project without a token has no webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable(Tables::WORKFLOW_PROJECT)) {
            return;
        }

        Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) {
            if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'webhook_token')) {
                $table->string('webhook_token', 64)->nullable()->unique();
            }

            if (!Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'webhook_secret')) {
                $table->text('webhook_secret')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(Tables::WORKFLOW_PROJECT)) {
            return;
        }

        Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) {
            if (Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'webhook_token')) {
                $table->dropUnique(['webhook_token']);
                $table->dropColumn('webhook_token');
            }

            if (Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'webhook_secret')) {
                $table->dropColumn('webhook_secret');
            }
        });
    }
};
