<?php

/**
 * Unified widget catalog part 1, final review I5: local authoring adopts a site widget as a hub widget of a
 * publisher, so the next export writes it into that publisher's catalog.
 */

use Cms\Core\Models\Widget;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/ispa-widget-adopt-' . uniqid('', true);
    config()->set('ispa-cms.widget_catalog_sources', ['iSPA' => $this->root . '/sources/ispa']);
    $this->widget = Widget::query()->forceCreate([
        'widget_key' => 'site.promo', 'name' => 'Promo', 'origin' => Widget::ORIGIN_SITE,
        'cloned_from_widget_key' => 'core.widget-1', 'cloned_at' => now(),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('marks a site widget as a hub widget and the next export writes it', function () {
    config(['ispa-cms.widget.catalog_authoring' => true]);

    expect(Artisan::call('cms:widget-catalog-adopt', ['widget_key' => 'site.promo']))->toBe(0);

    $widget = $this->widget->fresh();
    expect($widget->origin)->toBe(Widget::ORIGIN_HUB)
        ->and($widget->publisher)->toBe('iSPA')
        ->and($widget->cloned_from_widget_key)->toBeNull();

    expect(Artisan::call('cms:export-widgets', ['--target' => $this->root . '/out', '--publisher' => ['iSPA']]))->toBe(0)
        ->and(array_column(require $this->root . '/out/iSPA/widgets.php', 'widget_key'))->toBe(['site.promo']);
});

it('refuses outside authoring, for an unknown key, a hub widget or an unknown publisher', function () {
    config(['ispa-cms.widget.catalog_authoring' => false]);
    expect(Artisan::call('cms:widget-catalog-adopt', ['widget_key' => 'site.promo']))->toBe(1)
        ->and($this->widget->fresh()->origin)->toBe(Widget::ORIGIN_SITE);

    config(['ispa-cms.widget.catalog_authoring' => true]);
    expect(Artisan::call('cms:widget-catalog-adopt', ['widget_key' => 'site.missing']))->toBe(1)
        ->and(Artisan::call('cms:widget-catalog-adopt', ['widget_key' => 'site.promo', '--publisher' => 'Nobody']))->toBe(1)
        ->and($this->widget->fresh()->origin)->toBe(Widget::ORIGIN_SITE);

    Artisan::call('cms:widget-catalog-adopt', ['widget_key' => 'site.promo']);
    expect(Artisan::call('cms:widget-catalog-adopt', ['widget_key' => 'site.promo', '--publisher' => 'iSPA']))->toBe(1);
});
