<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-language names of widgets and designs: `{"vi": "...", "en": "..."}`. `name` stays the
 * canonical fallback. Additive only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('widgets', static function (Blueprint $table): void {
            $table->json('name_translations')->nullable();
            $table->json('description_translations')->nullable();
        });
        Schema::table('widget_themes', static function (Blueprint $table): void {
            $table->json('name_translations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widgets', static function (Blueprint $table): void {
            $table->dropColumn(['name_translations', 'description_translations']);
        });
        Schema::table('widget_themes', static function (Blueprint $table): void {
            $table->dropColumn('name_translations');
        });
    }
};
