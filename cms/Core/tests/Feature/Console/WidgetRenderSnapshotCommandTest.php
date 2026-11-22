<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Database\Seeders\WidgetSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Seed one hub widget with one theme and write its generated template (deploys publish these from the DB). */
function seedSnapshotWidget(object $test, string $template = '<p>{title}</p>'): array
{
    File::ensureDirectoryExists($test->dir);
    File::put($test->dir . '/widgets.php', '<?php return ' . var_export([
        ['id' => 1, 'widget_key' => 'testpub.hero', 'name' => 'Hero', 'version' => 1],
    ], true) . ';');
    File::put($test->dir . '/widget_themes.php', '<?php return ' . var_export([[
        'id' => 10, 'widget_id' => 1, 'theme_key' => 'default', 'name' => 'Default',
        'content' => $template, 'css' => null, 'query' => null, 'project_name' => '["Default"]',
    ]], true) . ';');
    File::put($test->dir . '/widget_inputs.php', '<?php return ' . var_export([[
        'id' => 10, 'widget_id' => 1, 'input_key' => 'title', 'name' => 'title',
        'type' => 'text', 'default_value' => null, 'options' => null,
    ]], true) . ';');
    $test->seed(WidgetSeeder::class);
    $widget = DB::table('widgets')->where('widget_key', 'testpub.hero')->first();
    $theme = DB::table('widget_themes')->where('widget_id', $widget->id)->first();
    File::put(
        $test->root . "/storage/app/generated_widgets/default/widget_{$widget->id}_theme_{$theme->id}.blade.php",
        $template,
    );

    return [(int) $widget->id, (int) $theme->id];
}

function insertSnapshotPost(string $name, array $content, string $status = 'status_published'): int
{
    DB::table('enumerates')->insertOrIgnore(['enum_key' => $status, 'name' => $status]);

    return (int) DB::table('posts')->insertGetId([
        'name' => $name, 'type' => 'article', 'status' => $status,
        'content' => json_encode($content), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function widgetBlock(int $widgetId, int $themeId, string $title): array
{
    return ['type' => 'widget', 'widget_id' => $widgetId, 'template_id' => $themeId, 'value' => ['title' => $title], 'inputs' => []];
}

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/ispa-widget-snapshot-' . uniqid();
    $this->dir = $this->root . '/pub';
    $this->out = $this->root . '/out';
    File::ensureDirectoryExists($this->root . '/storage/app/generated_widgets/default');
    $this->app->useStoragePath($this->root . '/storage');
    config()->set('ispa-cms.folder_name', 'Default');
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $this->dir]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('writes one file per published post with widget blocks (legacy status included) and stable hashes', function () {
    [$widgetId, $themeId] = seedSnapshotWidget($this);
    $first = insertSnapshotPost('First', [['type' => 'text', 'value' => '<p>x</p>'], widgetBlock($widgetId, $themeId, 'Alpha')]);
    $second = insertSnapshotPost('Second', [widgetBlock($widgetId, $themeId, 'Beta'), widgetBlock($widgetId, $themeId, 'Gamma')]);
    $legacy = insertSnapshotPost('Legacy status', [widgetBlock($widgetId, $themeId, 'Delta')], 'published');
    insertSnapshotPost('Text only', [['type' => 'text', 'value' => '<p>y</p>']]);
    insertSnapshotPost('Draft', [widgetBlock($widgetId, $themeId, 'Hidden')], 'status_draft');

    expect(Artisan::call('cms:widget-render-snapshot', ['--out' => $this->out]))->toBe(0);
    $index = json_decode(File::get($this->out . '/index.json'), true);

    expect(array_keys($index))->toBe([$first, $second, $legacy])
        ->and(File::files($this->out))->toHaveCount(4)
        ->and(File::get($this->out . "/{$first}.html"))->toContain('Alpha')
        ->and(File::get($this->out . "/{$second}.html"))->toContain('Beta')->toContain('Gamma')
        ->and($index[(string) $first])->toMatch('/^[0-9a-f]{64}$/');

    expect(Artisan::call('cms:widget-render-snapshot', ['--out' => $this->root . '/again']))->toBe(0)
        ->and(File::get($this->root . '/again/index.json'))->toBe(File::get($this->out . '/index.json'));
});

it('exits non-zero when a block does not render ok', function () {
    [$widgetId, $themeId] = seedSnapshotWidget($this);
    $post = insertSnapshotPost('Broken', [widgetBlock($widgetId, $themeId + 99, 'Alpha')]);

    $exit = Artisan::call('cms:widget-render-snapshot', ['--out' => $this->out]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("post {$post} block 0")
        ->and(File::exists($this->out . "/{$post}.html"))->toBeTrue();
});

it('exits non-zero when no published post has a widget block', function () {
    seedSnapshotWidget($this);
    insertSnapshotPost('Text only', [['type' => 'text', 'value' => '<p>y</p>']]);

    $exit = Artisan::call('cms:widget-render-snapshot', ['--out' => $this->out]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Rendered 0 widget blocks')->toContain('no widget blocks found');
});

it('exits non-zero when a block never reaches the widget renderer', function () {
    [$widgetId, $themeId] = seedSnapshotWidget($this);
    $post = insertSnapshotPost('Vacuous', [widgetBlock($widgetId, $themeId, 'Alpha')]);
    // A component that returns markup without calling the WidgetRenderer (e.g. an override that bypasses it).
    $this->app->bind(\App\View\Components\WidgetView::class, static fn ($app, array $params) => new class (...$params) extends \App\View\Components\WidgetView {
        public function render(): string
        {
            return '<p>static</p>';
        }
    });

    $exit = Artisan::call('cms:widget-render-snapshot', ['--out' => $this->out]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("post {$post} block 0: never reached the widget renderer");
});

it('counts empty block HTML in the summary without failing', function () {
    [$widgetId, $themeId] = seedSnapshotWidget($this, '');
    insertSnapshotPost('Empty', [widgetBlock($widgetId, $themeId, 'Alpha'), widgetBlock($widgetId, $themeId, 'Beta')]);

    $exit = Artisan::call('cms:widget-render-snapshot', ['--out' => $this->out]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Rendered 2 widget blocks (2 empty) in 1 posts');
});
