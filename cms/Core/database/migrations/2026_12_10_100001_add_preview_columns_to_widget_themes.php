<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Still preview image of a widget design (path on the public disk) and whether it no longer matches the design
 * (a site copy saved after its image was taken). Hub downloads set the image from the bundle and clear the flag.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('widget_themes', static function (Blueprint $table): void {
            $table->string('preview_image')->nullable();
            $table->boolean('preview_stale')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('widget_themes', static function (Blueprint $table): void {
            $table->dropColumn(['preview_image', 'preview_stale']);
        });
    }
};
