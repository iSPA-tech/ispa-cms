<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Database\Seeders\WidgetSeeder;
use Cms\Core\Models\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function widgetCatalogMigration(): object
{
    return require base_path('cms/Core/database/migrations/2026_11_21_100000_add_catalog_columns_to_widgets_table.php');
}

const WIDGET_CATALOG_COLUMNS = [
    'origin', 'publisher', 'cloned_from_widget_key', 'cloned_at', 'blocked_at', 'blocked_by', 'blocked_reason',
];

it('backfills origin hub for keys in the shipped catalog and site for the rest', function () {
    [$widgets] = WidgetSeeder::sourceRows();
    $catalogKey = $widgets[0]['widget_key'] ?? 'core.widget-' . $widgets[0]['id'];

    DB::table('widgets')->insert([
        ['widget_key' => $catalogKey, 'name' => 'Catalog widget'],
        ['widget_key' => 'adhoc.x', 'name' => 'Ad-hoc widget'],
    ]);

    $migration = widgetCatalogMigration();
    $migration->down();
    $migration->up();

    expect(Widget::where('widget_key', $catalogKey)->value('origin'))->toBe('hub')
        ->and(Widget::where('widget_key', $catalogKey)->value('publisher'))->toBe('iSPA')
        ->and(Widget::where('widget_key', 'adhoc.x')->value('origin'))->toBe('site')
        ->and(Widget::where('widget_key', 'adhoc.x')->value('publisher'))->toBeNull();
});

it('defaults new rows to site origin and exposes catalog helpers', function () {
    $widget = Widget::create(['name' => 'Fresh']);
    $widget->refresh();

    expect($widget->origin)->toBe(Widget::ORIGIN_SITE)
        ->and($widget->isHub())->toBeFalse()
        ->and($widget->isBlocked())->toBeFalse();

    $widget->forceFill(['origin' => Widget::ORIGIN_HUB, 'blocked_at' => now()])->save();

    expect($widget->fresh()->isHub())->toBeTrue()
        ->and($widget->fresh()->isBlocked())->toBeTrue();
});

it('migration is reversible', function () {
    foreach (WIDGET_CATALOG_COLUMNS as $column) {
        expect(Schema::hasColumn('widgets', $column))->toBeTrue();
    }

    widgetCatalogMigration()->down();

    foreach (WIDGET_CATALOG_COLUMNS as $column) {
        expect(Schema::hasColumn('widgets', $column))->toBeFalse();
    }
});
