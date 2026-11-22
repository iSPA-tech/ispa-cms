<?php

use Cms\Core\Database\Seeders\WidgetSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog metadata for widget definitions: where a widget comes from (hub catalog or this site), who published it,
 * which hub widget a site copy was cloned from, and the emergency block state.
 *
 * Existing rows whose key ships in the Core widget catalog become hub widgets published by iSPA; every other row
 * stays a site widget.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('widgets', static function (Blueprint $table): void {
            $table->string('origin', 8)->default('site')->index('widgets_origin_index');
            $table->string('publisher', 64)->nullable();
            $table->string('cloned_from_widget_key')->nullable();
            $table->timestamp('cloned_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->unsignedBigInteger('blocked_by')->nullable();
            $table->string('blocked_reason', 500)->nullable();
        });

        // Same key derivation as WidgetSeeder::run().
        [$widgets] = WidgetSeeder::sourceRows();
        $hubKeys = array_map(
            static fn (array $row): string => $row['widget_key'] ?? 'core.widget-' . $row['id'],
            $widgets,
        );

        foreach (array_chunk(array_unique($hubKeys), 500) as $chunk) {
            DB::table('widgets')->whereIn('widget_key', $chunk)->update(['origin' => 'hub', 'publisher' => 'iSPA']);
        }
    }

    public function down(): void
    {
        Schema::table('widgets', static function (Blueprint $table): void {
            $table->dropIndex('widgets_origin_index');
        });
        Schema::table('widgets', static function (Blueprint $table): void {
            $table->dropColumn([
                'origin', 'publisher', 'cloned_from_widget_key', 'cloned_at', 'blocked_at', 'blocked_by',
                'blocked_reason',
            ]);
        });
    }
};
