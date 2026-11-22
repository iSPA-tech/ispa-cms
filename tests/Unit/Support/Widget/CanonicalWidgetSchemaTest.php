<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Widget;

use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use App\Services\WidgetQueryService;
use App\Support\Widget\CanonicalWidgetSchema;
use App\Support\Widget\WidgetInputSanitizer;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the Task 7 secure root defaults for
 * App\Contracts\Widget\CanonicalWidgetSchemaResolver (bound default:
 * App\Support\Widget\CanonicalWidgetSchema) and
 * App\Support\Widget\WidgetInputSanitizer:
 *
 * - canonical Core-owned Widget/WidgetInput relations are the sole source
 *   of truth for allowed input keys/types;
 * - a Widget/Theme mismatch is rejected without any diagnostic detail;
 * - a caller-supplied (e.g. stale copied Page block) key absent from the
 *   canonical definitions is dropped silently;
 * - the sanitize rules themselves match the legacy
 *   WidgetQueryService::sanitizeInputValues() behavior for canonical keys.
 *
 * Chunk 1 Task 9a additions below characterize (pin, not modernize) four
 * further value states/types against the same legacy-parity bar: an empty
 * scalar value, a canonical key missing from the caller-supplied values, and
 * the 'query' and 'iSeo' WidgetInput types (both fall through to the same
 * plain-scalar-escape branch as 'text' in WidgetInputSanitizer::sanitize()
 * and WidgetQueryService::sanitizeInputValues() — neither special-cases
 * them). Rich-text/textarea and media/image are already characterized above
 * by test_sanitizer_escapes_plain_text_and_keeps_safe_rich_text_like_the_
 * legacy_service() and test_sanitizer_rejects_unsafe_media_urls() and are
 * not duplicated here.
 */
class CanonicalWidgetSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Widget $widgetA;
    private WidgetTheme $themeA1;
    private Widget $widgetB;
    private WidgetTheme $themeB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->widgetA = Widget::query()->create(['name' => 'Widget A']);
        $this->themeA1 = WidgetTheme::query()->create([
            'widget_id' => $this->widgetA->id,
            'name' => 'Theme A1',
            'content' => '<div>{title}</div>',
        ]);
        WidgetInput::query()->create(['widget_id' => $this->widgetA->id, 'name' => 'title', 'type' => 'text']);
        WidgetInput::query()->create(['widget_id' => $this->widgetA->id, 'name' => 'body', 'type' => 'textarea']);

        $this->widgetB = Widget::query()->create(['name' => 'Widget B']);
        $this->themeB1 = WidgetTheme::query()->create([
            'widget_id' => $this->widgetB->id,
            'name' => 'Theme B1',
            'content' => '<div>B1</div>',
        ]);
        WidgetInput::query()->create(['widget_id' => $this->widgetB->id, 'name' => 'headline', 'type' => 'text']);
    }

    public function test_default_binding_is_the_secure_root_resolver(): void
    {
        $this->assertInstanceOf(CanonicalWidgetSchema::class, app(CanonicalWidgetSchemaResolver::class));
    }

    public function test_resolve_returns_canonical_input_definitions_for_a_valid_pairing(): void
    {
        $resolver = app(CanonicalWidgetSchemaResolver::class);

        $definitions = $resolver->resolve($this->widgetA->id, $this->themeA1->id);

        $this->assertSame(['type' => 'text'], $definitions['title']);
        $this->assertSame(['type' => 'textarea'], $definitions['body']);
        $this->assertArrayNotHasKey('headline', $definitions);
    }

    public function test_is_valid_pairing_true_for_owning_theme(): void
    {
        $resolver = app(CanonicalWidgetSchemaResolver::class);

        $this->assertTrue($resolver->isValidPairing($this->widgetA->id, $this->themeA1->id));
    }

    public function test_is_valid_pairing_false_for_widget_theme_mismatch(): void
    {
        $resolver = app(CanonicalWidgetSchemaResolver::class);

        // themeB1 belongs to widgetB, not widgetA.
        $this->assertFalse($resolver->isValidPairing($this->widgetA->id, $this->themeB1->id));
    }

    public function test_resolve_rejects_a_widget_theme_mismatch_without_diagnostics(): void
    {
        $resolver = app(CanonicalWidgetSchemaResolver::class);

        $this->assertSame([], $resolver->resolve($this->widgetA->id, $this->themeB1->id));
    }

    public function test_resolve_rejects_an_unknown_pairing(): void
    {
        $resolver = app(CanonicalWidgetSchemaResolver::class);

        $this->assertSame([], $resolver->resolve(999999, 999999));
        $this->assertFalse($resolver->isValidPairing(999999, 999999));
        $this->assertSame([], $resolver->resolve(0, 0));
    }

    public function test_sanitizer_drops_a_key_absent_from_the_canonical_schema(): void
    {
        $sanitizer = app(WidgetInputSanitizer::class);

        $sanitized = $sanitizer->sanitize($this->widgetA->id, $this->themeA1->id, [
            'title' => 'Hello',
            'stale_copied_key' => '<script>alert(1)</script>',
        ]);

        $this->assertSame('Hello', $sanitized['title']);
        $this->assertArrayNotHasKey('stale_copied_key', $sanitized);
    }

    public function test_sanitizer_rejects_all_values_on_widget_theme_mismatch(): void
    {
        $sanitizer = app(WidgetInputSanitizer::class);

        $sanitized = $sanitizer->sanitize($this->widgetA->id, $this->themeB1->id, [
            'title' => 'Hello',
        ]);

        $this->assertSame([], $sanitized);
    }

    public function test_sanitizer_escapes_plain_text_and_keeps_safe_rich_text_like_the_legacy_service(): void
    {
        $sanitizer = app(WidgetInputSanitizer::class);

        $sanitized = $sanitizer->sanitize($this->widgetA->id, $this->themeA1->id, [
            'title' => '<script>alert(1)</script>',
            'body' => '<p onclick="alert(1)"><strong>Safe</strong><script>alert(2)</script></p>',
        ]);

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $sanitized['title']);
        $this->assertSame('<p><strong>Safe</strong>alert(2)</p>', $sanitized['body']);
    }

    public function test_sanitizer_rejects_unsafe_media_urls(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget Media']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme Media', 'content' => '']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'image', 'type' => 'image']);

        $sanitizer = app(WidgetInputSanitizer::class);

        $sanitized = $sanitizer->sanitize($widget->id, $theme->id, [
            'image' => ['url' => 'javascript:alert(1)', 'name' => '<x>'],
        ]);

        $this->assertSame('', $sanitized['image']['url']);
        $this->assertSame('<x>', $sanitized['image']['name']);
    }

    // -----------------------------------------------------------------
    // Chunk 1 Task 9a: further value-type characterization, pinned against
    // the legacy WidgetQueryService::sanitizeInputValues() parity bar.
    // -----------------------------------------------------------------

    public function test_sanitizer_matches_legacy_service_for_an_empty_scalar_value(): void
    {
        $sanitizer = app(WidgetInputSanitizer::class);

        $values = ['title' => ''];
        $sanitized = $sanitizer->sanitize($this->widgetA->id, $this->themeA1->id, $values);

        $legacy = WidgetQueryService::sanitizeInputValues($values, [
            ['name' => 'title', 'type' => 'text'],
        ]);

        $this->assertSame('', $sanitized['title']);
        $this->assertSame($legacy['title'], $sanitized['title']);
    }

    public function test_sanitizer_drops_a_missing_value_key_like_the_legacy_service_skips_it(): void
    {
        $sanitizer = app(WidgetInputSanitizer::class);

        // 'title' is canonical but absent from the caller-supplied values —
        // the "missing" characterization: dropped entirely, never defaulted
        // to null/empty string.
        $sanitized = $sanitizer->sanitize($this->widgetA->id, $this->themeA1->id, [
            'body' => 'Present',
        ]);

        $this->assertArrayNotHasKey('title', $sanitized);
        $this->assertSame('Present', $sanitized['body']);

        $legacy = WidgetQueryService::sanitizeInputValues(['body' => 'Present'], [
            ['name' => 'title', 'type' => 'text'],
            ['name' => 'body', 'type' => 'textarea'],
        ]);
        $this->assertArrayNotHasKey('title', $legacy);
    }

    public function test_sanitizer_escapes_a_query_type_value_as_plain_scalar_text_like_the_legacy_service(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget Query']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme Query', 'content' => '']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'source', 'type' => 'query']);

        $sanitizer = app(WidgetInputSanitizer::class);
        $values = ['source' => '<script>alert(1)</script>product.latest'];

        $sanitized = $sanitizer->sanitize($widget->id, $theme->id, $values);
        $legacy = WidgetQueryService::sanitizeInputValues($values, [
            ['name' => 'source', 'type' => 'query'],
        ]);

        $this->assertSame(e('<script>alert(1)</script>product.latest'), $sanitized['source']);
        $this->assertStringNotContainsString('<script>', $sanitized['source']);
        $this->assertSame($legacy['source'], $sanitized['source']);
    }

    public function test_sanitizer_escapes_an_iseo_type_value_as_plain_scalar_text_like_the_legacy_service(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget iSeo']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme iSeo', 'content' => '']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'seo_key', 'type' => 'iSeo']);

        $sanitizer = app(WidgetInputSanitizer::class);
        $values = ['seo_key' => 'brand"><script>alert(1)</script>'];

        $sanitized = $sanitizer->sanitize($widget->id, $theme->id, $values);
        $legacy = WidgetQueryService::sanitizeInputValues($values, [
            ['name' => 'seo_key', 'type' => 'iSeo'],
        ]);

        $this->assertSame(e('brand"><script>alert(1)</script>'), $sanitized['seo_key']);
        $this->assertStringNotContainsString('<script>', $sanitized['seo_key']);
        $this->assertSame($legacy['seo_key'], $sanitized['seo_key']);
    }

    public function test_resolve_adds_options_only_for_repeater(): void
    {
        $options = ['max' => 4, 'fields' => [['name' => 'name', 'type' => 'text']]];
        WidgetInput::query()->create([
            'widget_id' => $this->widgetA->id,
            'name' => 'cards',
            'type' => 'repeater',
            'options' => $options,
        ]);

        $definitions = (new CanonicalWidgetSchema())->resolve($this->widgetA->id, $this->themeA1->id);

        $this->assertSame(['type' => 'repeater', 'options' => $options], $definitions['cards']);
        $this->assertSame(['type' => 'text'], $definitions['title']);
        $this->assertSame(['type' => 'textarea'], $definitions['body']);
    }
}
