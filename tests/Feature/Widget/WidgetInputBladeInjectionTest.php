<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use App\Services\WidgetQueryService;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Widget field values are data, never template source. A value an admin saves on a widget (or inside a page/post
 * content block) that looks like Blade (`{{ … }}`, `@php … @endphp`, `{!! … !!}`) must reach the page as text and
 * must not run on the server. Each payload computes a marker number (1001 * N) that only appears in the output if
 * the Blade code ran.
 */
class WidgetInputBladeInjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-blade-injection-' . uniqid();
        File::ensureDirectoryExists($this->isolatedStoragePath . '/app/generated_widgets/default');
        $this->app->useStoragePath($this->isolatedStoragePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->isolatedStoragePath);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $inputTypes field name => input type
     * @return array{int, int} widget id, theme id
     */
    private function widgetWithTemplate(string $template, array $inputTypes): array
    {
        $widget = Widget::query()->create(['name' => 'Injection widget']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Injection theme', 'content' => $template]);

        foreach ($inputTypes as $name => $type) {
            WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => $name, 'type' => $type]);
        }

        $path = storage_path("app/generated_widgets/default/widget_{$widget->id}_theme_{$theme->id}.blade.php");
        File::put($path, $template);

        return [$widget->id, $theme->id];
    }

    /**
     * @param array<string, string> $inputTypes
     * @param array<string, mixed> $values
     */
    private function renderWidget(string $template, array $inputTypes, array $values): string
    {
        [$widgetId, $themeId] = $this->widgetWithTemplate($template, $inputTypes);

        $result = app(WidgetRenderer::class)->render(
            new WidgetRenderSpec($widgetId, $themeId, $values),
            new WidgetRenderContext(projectName: 'Default'),
        );

        $this->assertTrue($result->ok);
        $this->assertNotSame('-n-', $result->html, 'The widget failed to render.');

        return $result->html;
    }

    public function test_a_blade_echo_in_a_text_value_shows_as_text_and_does_not_run(): void
    {
        $html = $this->renderWidget('<h2 data-marker="t">{title}</h2>', ['title' => 'text'], [
            'title' => '{{ 1001 * 43 }}',
        ]);

        $this->assertStringNotContainsString('43043', $html);
        $this->assertStringContainsString('<h2 data-marker="t">{{ 1001 * 43 }}</h2>', $html);
    }

    public function test_a_php_directive_in_a_text_value_shows_as_text_and_does_not_run(): void
    {
        $html = $this->renderWidget('<h2 data-marker="t">{title}</h2>', ['title' => 'text'], [
            'title' => '@php echo 1001 * 44; @endphp',
        ]);

        $this->assertStringNotContainsString('44044', $html);
        $this->assertStringContainsString('@php echo 1001 * 44; @endphp', $html);
    }

    public function test_blade_in_a_rich_text_value_shows_as_text_and_does_not_run(): void
    {
        $html = $this->renderWidget('<div data-marker="t">{body}</div>', ['body' => 'textarea'], [
            'body' => "<p>{!! 1001 * 45 !!} and {{ strtoupper('pwned') }}</p>",
        ]);

        $this->assertStringNotContainsString('45045', $html);
        $this->assertStringNotContainsString('PWNED', $html);
        $this->assertStringContainsString("<p>{!! 1001 * 45 !!} and {{ strtoupper('pwned') }}</p>", $html);
    }

    public function test_blade_in_an_image_url_does_not_run(): void
    {
        $html = $this->renderWidget('<figure data-marker="t">{image name="photo" class="cover"}</figure>', ['photo' => 'image'], [
            'photo' => ['url' => 'https://example.test/{{ 1001 * 46 }}.png', 'name' => '@php echo 1001 * 47; @endphp'],
        ]);

        $this->assertStringNotContainsString('46046', $html);
        $this->assertStringNotContainsString('47047', $html);
        $this->assertStringContainsString('src="https://example.test/{{ 1001 * 46 }}.png"', $html);
    }

    public function test_a_value_compared_inside_a_blade_condition_is_data_and_keeps_its_meaning(): void
    {
        $template = '@if("{subtitle}" !== "")<h3>{subtitle}</h3>@endif<p>{title}</p>';
        $types = ['subtitle' => 'text', 'title' => 'text'];

        // A trailing backslash used to escape the closing quote of the pasted PHP string literal.
        $html = $this->renderWidget($template, $types, ['subtitle' => 'Ends with \\', 'title' => 'Kept']);
        $this->assertStringContainsString('<h3>Ends with \\</h3>', $html);
        $this->assertStringContainsString('<p>Kept</p>', $html);

        $empty = $this->renderWidget($template, $types, ['subtitle' => '', 'title' => 'Kept']);
        $this->assertStringNotContainsString('<h3>', $empty);
        $this->assertStringContainsString('<p>Kept</p>', $empty);

        $code = $this->renderWidget($template, $types, ['subtitle' => '{{ 1001 * 48 }}', 'title' => 'Kept']);
        $this->assertStringNotContainsString('48048', $code);
        $this->assertStringContainsString('<h3>{{ 1001 * 48 }}</h3>', $code);
    }

    public function test_a_value_holding_another_placeholder_is_not_expanded(): void
    {
        $html = $this->renderWidget('<h2>{title}</h2><p>{subtitle}</p>', ['title' => 'text', 'subtitle' => 'text'], [
            'title' => 'Before {subtitle} after',
            'subtitle' => 'Second',
        ]);

        $this->assertStringContainsString('<h2>Before {subtitle} after</h2>', $html);
        $this->assertStringContainsString('<p>Second</p>', $html);
    }

    public function test_the_admin_preview_with_edit_markers_does_not_run_a_value(): void
    {
        $html = WidgetQueryService::render(
            '<h2>{title}</h2>',
            ['title' => e('{{ 1001 * 49 }}')],
            false,
            '',
            true,
            ['title' => 'text'],
        );

        $this->assertStringNotContainsString('49049', $html);
        $this->assertStringContainsString('{{ 1001 * 49 }}</ispa-field>', $html);
    }

    public function test_a_widget_block_in_page_post_content_does_not_run_its_values(): void
    {
        [$widgetId, $themeId] = $this->widgetWithTemplate('<h2 data-marker="block">{title}</h2>', ['title' => 'text']);

        $html = view('components.page-content-view', [
            'content' => [[
                'type' => 'widget',
                'widget_id' => $widgetId,
                'template_id' => $themeId,
                'value' => ['title' => '@php echo 1001 * 50; @endphp {{ 1001 * 51 }}'],
                'inputs' => [['name' => 'title', 'type' => 'text', 'default_value' => null, 'value' => null, 'options' => []]],
            ]],
        ])->render();

        $this->assertStringNotContainsString('50050', $html);
        $this->assertStringNotContainsString('51051', $html);
        $this->assertStringContainsString('<h2 data-marker="block">@php echo 1001 * 50; @endphp {{ 1001 * 51 }}</h2>', $html);
    }
}
