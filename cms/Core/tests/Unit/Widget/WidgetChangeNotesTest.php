<?php

declare(strict_types=1);

use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use Cms\Core\Support\Widget\WidgetChangeNotes;
use Cms\Core\Tests\TestCase;
use Illuminate\Support\Facades\File;

uses(TestCase::class);

afterEach(function () {
    if (isset($this->dir)) {
        File::deleteDirectory($this->dir);
    }
});

it('normalizes valid items and refuses every malformed shape', function () {
    expect(WidgetChangeNotes::items([['en' => ' Added ', 'type' => 'added', 'vi' => 'Thêm']]))
        ->toBe([['type' => 'added', 'vi' => 'Thêm', 'en' => 'Added']]);

    $long = str_repeat('a', WidgetChangeNotes::TEXT_MAX + 1);
    foreach ([
        [],
        'text',
        ['a' => ['type' => 'added', 'vi' => 'x', 'en' => 'y']],
        [['type' => 'removed', 'vi' => 'x', 'en' => 'y']],
        [['type' => 'added', 'vi' => 'x']],
        [['type' => 'added', 'vi' => '  ', 'en' => 'y']],
        [['type' => 'added', 'vi' => 'x', 'en' => $long]],
        [['type' => 'added', 'vi' => 'x', 'en' => 'y', 'fr' => 'z']],
        array_fill(0, WidgetChangeNotes::MAX_ITEMS + 1, ['type' => 'added', 'vi' => 'x', 'en' => 'y']),
    ] as $bad) {
        expect(fn () => WidgetChangeNotes::items($bad))->toThrow(InvalidArgumentException::class);
    }
    expect(WidgetChangeNotes::items([['type' => 'fixed', 'vi' => str_repeat('ă', 300), 'en' => 'y']]))->toHaveCount(1);
});

it('loads the changelog of a publisher and names the broken entry', function () {
    $this->dir = sys_get_temp_dir() . '/ispa-widget-changelog-' . uniqid();
    File::ensureDirectoryExists($this->dir);
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $this->dir]);
    expect(WidgetChangeNotes::changelog('TestPub'))->toBe([]);

    File::put($this->dir . '/' . WidgetChangeNotes::FILE, '<?php return ' . var_export(['testpub.hero' => [3 => [['type' => 'changed', 'vi' => 'Đổi', 'en' => 'Changed']]]], true) . ';');
    expect(WidgetChangeNotes::changelog('TestPub'))->toBe(['testpub.hero' => [3 => [['type' => 'changed', 'vi' => 'Đổi', 'en' => 'Changed']]]]);

    File::put($this->dir . '/' . WidgetChangeNotes::FILE, '<?php return ' . var_export(['testpub.hero' => ['v3' => [['type' => 'changed', 'vi' => 'Đổi', 'en' => 'Changed']]]], true) . ';');
    expect(fn () => WidgetChangeNotes::changelog('TestPub'))->toThrow(InvalidArgumentException::class, 'testpub.hero');
});

it('the shipped iSPA changelog is valid', function () {
    $log = WidgetChangeNotes::changelog(WidgetCatalogSources::ISPA);
    foreach (['core.widget-1', 'core.widget-4', 'core.widget-5', 'core.widget-6'] as $key) {
        expect($log[$key][20261101][0]['type'] ?? null)->toBe('added');
    }
});

it('sidecars round-trip and must name the same widget and version', function () {
    $items = [['type' => 'added', 'vi' => 'Thêm "Ảnh"', 'en' => 'Added "Image"']];
    $json = WidgetChangeNotes::encodeSidecar('core.widget-1', 20261101, $items);
    expect(WidgetChangeNotes::decodeSidecar($json, 'core.widget-1', 20261101))->toBe($items);
    expect(fn () => WidgetChangeNotes::decodeSidecar($json, 'core.widget-1', 20261102))->toThrow(InvalidArgumentException::class);
    expect(fn () => WidgetChangeNotes::decodeSidecar($json, 'core.widget-4', 20261101))->toThrow(InvalidArgumentException::class);
    expect(fn () => WidgetChangeNotes::decodeSidecar('{', 'core.widget-1', 20261101))->toThrow(InvalidArgumentException::class);

    expect(WidgetChangeNotes::lenientItems([['type' => 'x', 'vi' => 'a', 'en' => 'b'], $items[0], 'junk']))->toBe($items);
});
