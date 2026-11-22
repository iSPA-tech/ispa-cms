<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog version of a widget definition; the authoring environment bumps it, the exported catalog carries it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('widgets', static function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('widgets', static function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
