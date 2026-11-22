<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\View\Components\WidgetView;
use Cms\Core\Database\Seeders\WidgetSeeder;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Catalog\WidgetCatalogSources;
use Illuminate\Support\Facades\File;

/**
 * Write a throwaway catalog (widgets, themes, inputs) into a temp directory; never into tracked files.
 *
 * @param array<int, array<string, mixed>> $widgets
 */
function writeTestCatalog(string $directory, array $widgets): void
{
    File::ensureDirectoryExists($directory);
    $themes = [];
    $inputs = [];
    foreach ($widgets as $widget) {
        $themes[] = [
            'id' => $widget['id'] * 10, 'widget_id' => $widget['id'], 'theme_key' => 'default', 'name' => 'Default',
            'content' => '<p>{title}</p>', 'css' => null, 'query' => null, 'project_name' => '["Default"]',
        ];
        $inputs[] = [
            'id' => $widget['id'] * 10, 'widget_id' => $widget['id'], 'input_key' => 'title', 'name' => 'title',
            'type' => 'text', 'default_value' => null, 'options' => null,
        ];
    }
    File::put($directory . '/widgets.php', '<?php return ' . var_export($widgets, true) . ';');
    File::put($directory . '/widget_themes.php', '<?php return ' . var_export($themes, true) . ';');
    File::put($directory . '/widget_inputs.php', '<?php return ' . var_export($inputs, true) . ';');
}

beforeEach(function () {
    $this->catalogRoot = sys_get_temp_dir() . '/ispa-widget-catalog-' . uniqid();
    config()->set('ispa-cms.folder_name', 'Default');
});

afterEach(function () {
    File::deleteDirectory($this->catalogRoot);
});

it('sync marks synced rows hub with their publisher', function () {
    $this->seed(WidgetSeeder::class);

    [$widgets] = WidgetCatalogSources::rows('iSPA');
    $keys = array_map(static fn (array $row): string => $row['widget_key'] ?? 'core.widget-' . $row['id'], $widgets);
    $key = Widget::whereIn('widget_key', $keys)->value('widget_key');
    expect($key)->not->toBeNull();

    expect(Widget::where('widget_key', $key)->value('origin'))->toBe(Widget::ORIGIN_HUB)
        ->and(Widget::where('widget_key', $key)->value('publisher'))->toBe('iSPA')
        ->and(Widget::where('origin', '!=', Widget::ORIGIN_HUB)->count())->toBe(0);

    // A second publisher stamps its own name.
    writeTestCatalog($this->catalogRoot . '/example', [['id' => 1, 'widget_key' => 'example-events.hero', 'name' => 'Example Hero']]);
    config()->set('ispa-cms.widget_catalog_sources', ['ExampleEvents' => $this->catalogRoot . '/example']);
    $this->seed(WidgetSeeder::class);

    expect(Widget::where('widget_key', 'example-events.hero')->value('publisher'))->toBe('ExampleEvents')
        ->and(Widget::where('widget_key', 'example-events.hero')->value('origin'))->toBe(Widget::ORIGIN_HUB);
});

it('sync leaves site rows untouched', function () {
    Widget::forceCreate(['widget_key' => 'site.x-1', 'name' => 'Mine', 'description' => 'own']);

    $this->seed(WidgetSeeder::class);

    $row = Widget::where('widget_key', 'site.x-1')->first();
    expect($row->name)->toBe('Mine')
        ->and($row->description)->toBe('own')
        ->and($row->origin)->toBe(Widget::ORIGIN_SITE)
        ->and($row->publisher)->toBeNull();
});

it('clone survives removal of its hub source', function () {
    $storage = $this->catalogRoot . '/storage';
    File::ensureDirectoryExists($storage . '/app/generated_widgets/default');
    $this->app->useStoragePath($storage);

    $directory = $this->catalogRoot . '/test';
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $directory]);
    writeTestCatalog($directory, [
        ['id' => 1, 'widget_key' => 'testpub.keep', 'name' => 'Keep'],
        ['id' => 2, 'widget_key' => 'testpub.hero', 'name' => 'Hero'],
    ]);
    $this->seed(WidgetSeeder::class);
    expect(Widget::where('widget_key', 'testpub.hero')->value('origin'))->toBe(Widget::ORIGIN_HUB);

    $clone = Widget::forceCreate([
        'widget_key' => 'site.testpub.hero-1', 'name' => 'Hero (copy)',
        'origin' => Widget::ORIGIN_SITE, 'cloned_from_widget_key' => 'testpub.hero', 'cloned_at' => now(),
    ]);
    $theme = WidgetTheme::forceCreate(['widget_id' => $clone->id, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<p>{title}</p>']);
    WidgetInput::forceCreate(['widget_id' => $clone->id, 'input_key' => 'title', 'name' => 'title', 'type' => 'text']);
    File::put(
        storage_path("app/generated_widgets/default/widget_{$clone->id}_theme_{$theme->id}.blade.php"),
        '<section data-clone>{title}</section>',
    );
    $before = $clone->fresh()->only(['name', 'origin', 'publisher', 'cloned_from_widget_key']);

    // The hub source drops the widget the clone came from.
    writeTestCatalog($directory, [['id' => 1, 'widget_key' => 'testpub.keep', 'name' => 'Keep']]);
    $this->seed(WidgetSeeder::class);

    expect($clone->fresh()->only(['name', 'origin', 'publisher', 'cloned_from_widget_key']))->toBe($before)
        ->and(WidgetTheme::where('widget_id', $clone->id)->count())->toBe(1);

    $html = (string) (new WidgetView(
        value: ['title' => 'Still here'],
        inputs: [['name' => 'title', 'type' => 'text']],
        widgetKey: 'site.testpub.hero-1',
        themeKey: 'default',
    ))->render()->render();

    expect($html)->toContain('Still here');
});

it('skips a publisher whose catalog directory is missing', function () {
    config()->set('ispa-cms.widget_catalog_sources', ['Ghost' => $this->catalogRoot . '/missing']);

    expect(WidgetCatalogSources::publishers())->toBe(['Ghost'])
        ->and(WidgetCatalogSources::rows('Ghost'))->toBe([[], [], []]);

    $this->seed(WidgetSeeder::class);

    expect(Widget::count())->toBe(0);
});

it('deploy seeding leaves an existing hub widget untouched', function () {
    $directory = $this->catalogRoot . '/test';
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $directory]);
    writeTestCatalog($directory, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero', 'version' => '1.0.0']]);
    $this->seed(WidgetSeeder::class);
    $widget = Widget::where('widget_key', 'testpub.hero')->firstOrFail();

    // The catalog moves on; only the hub ("Cập nhật") may bring that to the site.
    writeTestCatalog($directory, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero v2', 'version' => '2.0.0']]);
    File::put($directory . '/widget_themes.php', '<?php return ' . var_export([[
        'id' => 10, 'widget_id' => 1, 'theme_key' => 'default', 'name' => 'Default', 'content' => '<p>changed</p>',
        'css' => null, 'query' => null, 'project_name' => '["Default"]',
    ]], true) . ';');
    File::put($directory . '/widget_inputs.php', '<?php return ' . var_export([[
        'id' => 10, 'widget_id' => 1, 'input_key' => 'title', 'name' => 'Headline', 'type' => 'textarea',
        'default_value' => null, 'options' => null,
    ]], true) . ';');
    $this->seed(WidgetSeeder::class);

    expect($widget->fresh()->only(['name', 'version']))->toBe(['name' => 'Hero', 'version' => '1.0.0'])
        ->and(WidgetTheme::where('widget_id', $widget->id)->pluck('content')->all())->toBe(['<p>{title}</p>'])
        ->and(WidgetInput::where('widget_id', $widget->id)->get(['name', 'type'])->toArray())
        ->toBe([['name' => 'title', 'type' => 'text']]);
});

it('deploy seeding creates a missing catalog widget with its designs and inputs', function () {
    $directory = $this->catalogRoot . '/test';
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $directory]);
    writeTestCatalog($directory, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']]);
    $this->seed(WidgetSeeder::class);

    writeTestCatalog($directory, [
        ['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero renamed'],
        ['id' => 2, 'widget_key' => 'testpub.cta', 'name' => 'CTA'],
    ]);
    $this->seed(WidgetSeeder::class);

    $cta = Widget::where('widget_key', 'testpub.cta')->firstOrFail();
    expect($cta->origin)->toBe(Widget::ORIGIN_HUB)
        ->and($cta->publisher)->toBe('TestPub')
        ->and(WidgetTheme::where('widget_id', $cta->id)->count())->toBe(1)
        ->and(WidgetInput::where('widget_id', $cta->id)->count())->toBe(1)
        ->and(Widget::where('widget_key', 'testpub.hero')->value('name'))->toBe('Hero');
});

it('deploy seeding does not recreate a hub key that became a site copy', function () {
    $directory = $this->catalogRoot . '/test';
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $directory]);
    writeTestCatalog($directory, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']]);
    Widget::forceCreate([
        'widget_key' => 'site.testpub.hero-1', 'name' => 'Hero (copy)',
        'origin' => Widget::ORIGIN_SITE, 'cloned_from_widget_key' => 'testpub.hero', 'cloned_at' => now(),
    ]);

    $this->seed(WidgetSeeder::class);

    expect(Widget::where('widget_key', 'testpub.hero')->exists())->toBeFalse()
        ->and(Widget::count())->toBe(1);
});

it('cms:sync-widgets still reconciles existing hub widgets', function () {
    $directory = $this->catalogRoot . '/test';
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $directory]);
    writeTestCatalog($directory, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']]);
    $this->seed(WidgetSeeder::class);

    writeTestCatalog($directory, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero v2']]);
    $this->artisan('cms:sync-widgets')->assertSuccessful();

    expect(Widget::where('widget_key', 'testpub.hero')->value('name'))->toBe('Hero v2');
});

it('per-language names go catalog -> seed -> sync -> export and back unchanged', function () {
    $directory = $this->catalogRoot . '/test';
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $directory]);
    writeTestCatalog($directory, [[
        'id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero',
        'name_translations' => '{"en": "Hero", "vi": "Ảnh bìa"}', 'description_translations' => null,
    ]]);
    $themes = require $directory . '/widget_themes.php';
    $themes[0]['name_translations'] = '{"vi": "Mặc định"}';
    File::put($directory . '/widget_themes.php', '<?php return ' . var_export($themes, true) . ';');

    // Deploy seeding inserts a missing widget with its names.
    $this->seed(WidgetSeeder::class);
    $widget = Widget::where('widget_key', 'testpub.hero')->firstOrFail();
    expect($widget->name_translations)->toBe(['en' => 'Hero', 'vi' => 'Ảnh bìa'])
        ->and($widget->localizedName('vi'))->toBe('Ảnh bìa')
        ->and(WidgetTheme::where('widget_id', $widget->id)->sole()->name_translations)->toBe(['vi' => 'Mặc định']);

    // cms:sync-widgets reconciles changed names; deploy seeding does not.
    $themes[0]['name_translations'] = '{"en": "Default", "vi": "Mặc định"}';
    File::put($directory . '/widget_themes.php', '<?php return ' . var_export($themes, true) . ';');
    $this->seed(WidgetSeeder::class);
    expect(WidgetTheme::where('widget_id', $widget->id)->sole()->name_translations)->toBe(['vi' => 'Mặc định']);
    $this->artisan('cms:sync-widgets')->assertSuccessful();
    expect(WidgetTheme::where('widget_id', $widget->id)->sole()->name_translations)->toBe(['en' => 'Default', 'vi' => 'Mặc định']);

    // An edit in the database is exported into the catalog files (to a temporary target, never the tracked files).
    $widget->forceFill(['description_translations' => ['vi' => 'Băng rôn lớn']])->save();
    $target = $this->catalogRoot . '/out';
    $this->artisan('cms:export-widgets', ['--target' => $target, '--publisher' => ['TestPub']])->assertSuccessful();
    $exported = require $target . '/TestPub/widgets.php';
    $exportedThemes = require $target . '/TestPub/widget_themes.php';
    expect(json_decode($exported[0]['name_translations'], true))->toBe(['en' => 'Hero', 'vi' => 'Ảnh bìa'])
        ->and(json_decode($exported[0]['description_translations'], true))->toBe(['vi' => 'Băng rôn lớn'])
        ->and(json_decode($exportedThemes[0]['name_translations'], true))->toBe(['en' => 'Default', 'vi' => 'Mặc định']);

    // A catalog without the columns leaves the names in the database alone.
    writeTestCatalog($directory, [['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero']]);
    $this->artisan('cms:sync-widgets')->assertSuccessful();
    expect($widget->fresh()->name_translations)->toBe(['en' => 'Hero', 'vi' => 'Ảnh bìa']);
});
