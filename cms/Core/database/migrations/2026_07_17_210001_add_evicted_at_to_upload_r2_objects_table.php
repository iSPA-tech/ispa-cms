<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-eviction marker: set when the LOCAL copy of a verified object was
 * deliberately deleted (upload:r2-evict-local). R2 + the nginx fallback are
 * the serving tier from then on; the DB rows are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_r2_objects', function (Blueprint $table): void {
            $table->timestamp('evicted_at')->nullable()->after('verified_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('upload_r2_objects', function (Blueprint $table): void {
            $table->dropColumn('evicted_at');
        });
    }
};
