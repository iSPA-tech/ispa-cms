<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CDN cutover marker: set once EVERY tracked R2 object of an upload
 * (original + generated variants) reports status=synced — from then on the
 * Upload URL accessors serve the CDN host instead of /storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table): void {
            $table->timestamp('r2_synced_at')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table): void {
            $table->dropColumn('r2_synced_at');
        });
    }
};
