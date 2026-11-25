<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance of a workflow installed from the hub library (roadmap phase C): `source` json
 * `{kind: "hub", key, version, checksum, installed_hash}`; null for a workflow made or imported on this site. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable(Tables::WORKFLOW_PROJECT) || Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'source')) {
            return;
        }

        Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) {
            $table->json('source')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(Tables::WORKFLOW_PROJECT) && Schema::hasColumn(Tables::WORKFLOW_PROJECT, 'source')) {
            Schema::table(Tables::WORKFLOW_PROJECT, function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
