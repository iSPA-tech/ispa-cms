<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\Catalog\WidgetAuthoringPolicy;

function authoringWidget(string $origin): Widget
{
    $widget = new Widget();
    $widget->origin = $origin;

    return $widget;
}

it('keeps a site widget always editable in place', function () {
    config(['ispa-cms.widget.catalog_authoring' => false]);

    expect((new WidgetAuthoringPolicy())->canEditInPlace(authoringWidget(Widget::ORIGIN_SITE)))->toBeTrue();
});

it('makes a hub widget editable only with the flag on', function () {
    $policy = new WidgetAuthoringPolicy();
    $hub = authoringWidget(Widget::ORIGIN_HUB);

    config(['ispa-cms.widget.catalog_authoring' => false]);
    expect($policy->canEditInPlace($hub))->toBeFalse();

    config(['ispa-cms.widget.catalog_authoring' => true]);
    expect($policy->canEditInPlace($hub))->toBeTrue();
});

it('ignores the authoring flag in production', function () {
    $original = app()['env'];
    config(['ispa-cms.widget.catalog_authoring' => true]);
    app()['env'] = 'production';

    try {
        expect((new WidgetAuthoringPolicy())->authoringEnabled())->toBeFalse();
    } finally {
        app()['env'] = $original;
    }
});
