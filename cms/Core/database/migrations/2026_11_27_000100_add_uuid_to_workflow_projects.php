<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Workflow projects get a uuid (UUID-first admin API): the admin routes address a workflow by it, never by its row id.
 * Existing rows (soft-deleted ones too) are backfilled one by one so the same code runs on MariaDB and PostgreSQL.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('workflow_projects', 'uuid')) {
            return;
        }

        Schema::table('workflow_projects', static function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        DB::table('workflow_projects')
            ->whereNull('uuid')
            ->orderBy('id')
            ->pluck('id')
            ->each(static function ($id): void {
                DB::table('workflow_projects')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
            });

        Schema::table('workflow_projects', static function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('workflow_projects', 'uuid')) {
            return;
        }

        Schema::table('workflow_projects', static function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
