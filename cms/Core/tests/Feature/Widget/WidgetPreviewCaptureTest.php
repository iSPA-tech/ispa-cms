<?php

declare(strict_types=1);

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use Cms\Core\Database\Seeders\WidgetSeeder;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\Hub\WidgetPreviewCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Throwaway catalog (publisher TestPub) with one widget and one design per project list given.
 *
 * @param list<string> $projectLists JSON project_name of each design
 */
function previewCatalog(object $test, array $projectLists, string $content = '<p>{title}</p>'): void
{
    $widgets = [['id' => 990001, 'widget_key' => 'testpub.preview', 'name' => 'Preview', 'description' => 'x', 'js' => null, 'version' => 1]];
    $themes = [];
    foreach (array_values($projectLists) as $i => $projects) {
        $themes[] = [
            'id' => 990010 + $i, 'widget_id' => 990001, 'theme_key' => 'design-' . $i, 'name' => 'Design ' . $i,
            'content' => $content, 'css' => null, 'query' => null, 'project_name' => '["Default"]',
        ];
    }
    $inputs = [[
        'id' => 990010, 'widget_id' => 990001, 'input_key' => 'title', 'name' => 'title',
        'type' => 'text', 'default_value' => 'Xin chào', 'options' => null,
    ]];
    File::ensureDirectoryExists($test->catalog);
    File::put($test->catalog . '/widgets.php', '<?php return ' . var_export($widgets, true) . ';');
    File::put($test->catalog . '/widget_themes.php', '<?php return ' . var_export($themes, true) . ';');
    File::put($test->catalog . '/widget_inputs.php', '<?php return ' . var_export($inputs, true) . ';');
    $test->seed(WidgetSeeder::class);
    // Outside local the seeder keeps Default themes only; the project lists under test are set afterwards.
    $themeRows = WidgetTheme::query()->whereHas('widget', fn ($q) => $q->where('widget_key', 'testpub.preview'))->orderBy('theme_key')->get();
    foreach (array_values($projectLists) as $i => $projects) {
        $themeRows[$i]->forceFill(['project_name' => json_decode($projects, true)])->save();
    }
}

/** A renderer that records the project each render ran under and returns $html. */
function fakeRenderer(string $html): object
{
    $fake = new class($html) implements WidgetRenderer {
        /** @var list<array{context: string, config: string}> */
        public array $calls = [];

        public function __construct(private readonly string $html)
        {
        }

        public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult
        {
            $this->calls[] = ['context' => $context->projectName, 'config' => (string) config('ispa-cms.folder_name')];

            return new WidgetRenderResult(html: $this->html, ok: true);
        }
    };
    app()->instance(WidgetRenderer::class, $fake);

    return $fake;
}

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/ispa-widget-preview-' . uniqid();
    $this->catalog = $this->root . '/catalog';
    $this->out = $this->root . '/out';
    File::ensureDirectoryExists($this->out);
    config()->set('ispa-cms.folder_name', 'Default');
    config()->set('ispa-cms.widget_catalog_sources', ['TestPub' => $this->catalog]);
    config()->set('ispa-cms.widget_hub.publish_ledger', $this->root . '/ledger/published.json');
    config()->set('ispa-cms.widget.catalog_authoring', true);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('previews a design under the publisher project, else Default, else the first project named', function (mixed $projects, string $publisher, string $expected) {
    expect(WidgetPreviewCapture::projectFor($projects, $publisher))->toBe($expected);
})->with([
    'publisher listed' => [['BHaku', 'Default', 'ExampleShop'], 'ExampleShop', 'ExampleShop'],
    'Default listed' => [['BHaku', 'Default', 'ExampleJournal'], 'iSPA', 'Default'],
    'first listed' => [['BHaku', 'ExampleJournal'], 'iSPA', 'BHaku'],
    'json string' => ['["ExampleJournal","ExampleShop"]', 'ExampleShop', 'ExampleShop'],
    'scalar' => ['ExampleCafe', 'iSPA', 'ExampleCafe'],
    'missing' => [null, 'iSPA', 'Default'],
    'empty list' => [[], 'ExampleShop', 'Default'],
]);

it('treats empty, whitespace, comment-only, style-only and marker HTML as nothing to show', function (string $html, bool $visible) {
    expect(WidgetPreviewCapture::hasVisibleMarkup($html))->toBe($visible);
})->with([
    'empty' => ['', false],
    'whitespace' => ["  \n\t ", false],
    'render error marker' => [' -n- ', false],
    'comment' => ['<!-- widget not found -->', false],
    'style and script' => ['<style>p{}</style><script>x()</script><link rel="stylesheet" href="a.css">', false],
    'markup' => ['<section><p>Hi</p></section>', true],
]);

it('fails the build without capturing when a design renders nothing', function (string $html) {
    previewCatalog($this, ['["Default"]']);
    fakeRenderer($html);

    $exit = Artisan::call('cms:widget-bundle-build', ['widget_key' => 'testpub.preview', '--out' => $this->out]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('design-0')->toContain('empty HTML')->toContain('Default')
        ->and(File::allFiles($this->out))->toBe([])
        ->and(File::directories($this->out))->toBe([]);
})->with(['empty' => [''], 'whitespace' => ["  \n "], 'marker' => ['-n-']]);

it('renders each design under its own project and restores the active project', function () {
    previewCatalog($this, ['["Zeta","Default"]', '["Zeta","Yota"]']);
    $fake = fakeRenderer('');
    $capture = new WidgetPreviewCapture();
    $widget = Widget::query()->where('widget_key', 'testpub.preview')->firstOrFail();
    $themes = WidgetTheme::query()->where('widget_id', $widget->id)->orderBy('theme_key')->get();

    foreach ($themes as $theme) {
        try {
            $capture->renderHtml($widget, $theme, WidgetPreviewCapture::projectFor($theme->project_name, 'TestPub'), []);
        } catch (RuntimeException) {
        }
    }

    expect($fake->calls)->toBe([
        ['context' => 'Default', 'config' => 'Default'],
        ['context' => 'Zeta', 'config' => 'Zeta'],
    ])->and(config('ispa-cms.folder_name'))->toBe('Default');
});

it('generates a missing project template for the render and removes it after', function () {
    previewCatalog($this, ['["Zzpreviewtest"]']);
    $widget = Widget::query()->where('widget_key', 'testpub.preview')->firstOrFail();
    $theme = WidgetTheme::query()->where('widget_id', $widget->id)->firstOrFail();
    $generated = storage_path('app/generated_widgets/zzpreviewtest');
    expect(is_dir($generated))->toBeFalse();

    $html = (new WidgetPreviewCapture())->renderHtml($widget, $theme, 'Zzpreviewtest', ['title' => 'Xin chào']);

    expect($html)->toContain('Xin chào')
        ->and(is_dir($generated))->toBeFalse();
});

it('previews inputs without a default with samples and keeps the defaults it has', function () {
    previewCatalog($this, ['["Default"]']);
    $widget = Widget::query()->where('widget_key', 'testpub.preview')->firstOrFail();
    foreach ([['primary_url', 'text'], ['subtitle', 'textarea'], ['hero_image', 'image'], ['items', 'list']] as $i => [$name, $type]) {
        Cms\Core\Models\WidgetInput::query()->create([
            'widget_id' => $widget->id, 'input_key' => $name, 'name' => $name, 'type' => $type, 'default_value' => null,
        ]);
    }

    $values = (new WidgetPreviewCapture())->previewValues($widget, 'file:///tmp/placeholder.svg');

    expect($values)->toBe([
        'title' => 'Xin chào',
        'primary_url' => '#',
        'subtitle' => 'Đây là nội dung mẫu. Bạn thay bằng nội dung của mình khi thêm khối này vào trang.',
        'hero_image' => ['url' => 'file:///tmp/placeholder.svg', 'name' => 'Hero Image'],
        'items' => ['Items 1', 'Items 2', 'Items 3'],
    ]);
});

it('samples text inputs by what their name holds, never a colour or highlight label', function () {
    previewCatalog($this, ['["Default"]']);
    $widget = Widget::query()->where('widget_key', 'testpub.preview')->firstOrFail();
    $names = ['heading', 'color_title', 'bg_color', 'btn_text', 'button_text', 'c1_title', 'eyebrow'];
    foreach ($names as $name) {
        Cms\Core\Models\WidgetInput::query()->create([
            'widget_id' => $widget->id, 'input_key' => $name, 'name' => $name, 'type' => 'text', 'default_value' => null, 'options' => ['label' => 'Nhãn ' . $name],
        ]);
    }

    $values = (new WidgetPreviewCapture())->previewValues($widget, 'file:///tmp/placeholder.svg');

    expect(array_intersect_key($values, array_flip($names)))->toBe([
        'heading' => 'Câu chuyện',
        'color_title' => 'của chúng tôi',
        'bg_color' => '#155ea2',
        'btn_text' => 'Xem thêm',
        'button_text' => 'Xem thêm',
        'c1_title' => 'Câu chuyện',
        'eyebrow' => 'Nhãn eyebrow',
    ]);

    Cms\Core\Models\WidgetInput::query()->create([
        'widget_id' => $widget->id, 'input_key' => 'q', 'name' => 'query:product.featured', 'type' => 'query', 'default_value' => '3',
    ]);
    expect((new WidgetPreviewCapture())->previewValues($widget, 'file:///tmp/placeholder.svg')['heading'])->toBe('Sản phẩm nổi bật');
});

it('samples a text input with its editor label and a list stored as "[]" with numbered items', function () {
    previewCatalog($this, ['["Default"]']);
    $widget = Widget::query()->where('widget_key', 'testpub.preview')->firstOrFail();
    Cms\Core\Models\WidgetInput::query()->create([
        'widget_id' => $widget->id, 'input_key' => 'note', 'name' => 'note', 'type' => 'text', 'default_value' => null, 'options' => ['label' => 'Ghi chú'],
    ]);
    Cms\Core\Models\WidgetInput::query()->create([
        'widget_id' => $widget->id, 'input_key' => 'list_hours', 'name' => 'list_hours', 'type' => 'list', 'default_value' => '[]', 'options' => ['label' => 'Giờ làm việc'],
    ]);

    $values = (new WidgetPreviewCapture())->previewValues($widget, 'file:///tmp/placeholder.svg');

    expect($values['note'])->toBe('Ghi chú')
        ->and($values['list_hours'])->toBe(['Giờ làm việc 1', 'Giờ làm việc 2', 'Giờ làm việc 3']);
});

it('renders sample rows for a product query without rows and clears the hook after', function () {
    previewCatalog($this, ['["Default"]'], '<ul>{query:product.featured variable="$product" limit="2" template=`<li>{{ $product->name }}</li>`}</ul>');
    $widget = Widget::query()->where('widget_key', 'testpub.preview')->firstOrFail();
    $theme = WidgetTheme::query()->where('widget_id', $widget->id)->firstOrFail();

    $html = (new WidgetPreviewCapture())->renderHtml($widget, $theme, 'Default', []);

    expect($html)->toContain('<li>Sản phẩm mẫu 1</li>')->toContain('<li>Sản phẩm mẫu 2</li>')->not->toContain('Sản phẩm mẫu 3')
        ->and(App\Services\WidgetQueryService::$sampleQueryData)->toBeNull();
});

it('builds without a preview for a shared design named as drawn by the site theme that cannot render here', function () {
    previewCatalog($this, ['["Default"]']);
    fakeRenderer('');

    $exit = Artisan::call('cms:widget-bundle-build', ['widget_key' => 'testpub.preview', '--out' => $this->out, '--no-preview-for' => ['design-0']]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('No preview for design design-0 (drawn by the site theme)')->toContain('1 designs, 0 previews');
});

it('renders sample slides for a banner list without banners, in a throwaway cache, and restores both after', function () {
    $list = 'Cms\\Core\\Facades\\CMSCache::rememberRandomWithTracking("preview-test-banners", fn () => app(App\\Repositories\\Interfaces\\BannerInterface::class)->GetBannerList("slider_main"))';
    previewCatalog($this, ['["Default"]'], '<ul>@foreach (' . $list . ' as $slide)<li>{{ $slide->name }}|{{ $slide->is_enabled ? $slide->image->lg : "" }}|{{ $slide->custom["title"] }}</li>@endforeach</ul>');
    $widget = Widget::query()->where('widget_key', 'testpub.preview')->firstOrFail();
    $theme = WidgetTheme::query()->where('widget_id', $widget->id)->firstOrFail();
    $store = config('cache.default');

    $html = (new WidgetPreviewCapture())->renderHtml($widget, $theme, 'Default', []);

    expect(substr_count($html, '<li>'))->toBe(3)
        ->and($html)->toContain('<li>Tiêu đề ảnh 1||Dòng giới thiệu ngắn</li>')
        ->and(config('cache.default'))->toBe($store)
        ->and(cache()->has('preview-test-banners'))->toBeFalse()
        ->and(app(App\Repositories\Interfaces\BannerInterface::class)->GetBannerList('slider_main'))->toBeEmpty();
});

it('builds without a preview for a design of one project alone that cannot render here', function () {
    previewCatalog($this, ['["Zeta"]']);
    fakeRenderer('');

    $exit = Artisan::call('cms:widget-bundle-build', ['widget_key' => 'testpub.preview', '--out' => $this->out]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('No preview for design design-0 (only for Zeta)')->toContain('1 designs, 0 previews');
});
