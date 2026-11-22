<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Contracts\Widget\WidgetDataProviderRegistrar;
use App\Contracts\Widget\WidgetMutationGuard;
use App\Contracts\Widget\WidgetRenderer;
use App\Contracts\Widget\WidgetSlotRegistrar;
use App\Contracts\Widget\WidgetSlotRuntime;
use App\Contracts\Widget\WidgetTargetContextRegistrar;
use App\Contracts\Widget\WidgetTemplateContextRegistrar;
use App\Services\WidgetQueryService;
use App\Support\Widget\LegacyWidgetMutationGuard;
use App\Support\Widget\LegacyWidgetRenderer;
use App\Support\Widget\NullWidgetExtensionRegistrar;
use App\Support\Widget\NullWidgetSlotRuntime;
use App\View\Components\WidgetView;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Nwidart\Modules\Facades\Module;
use Tests\TestCase;

/**
 * Proves the Chunk 1 Task 7 module-off compatibility contract fixed by
 * docs/architecture/adr-widget-module-boundary.md (decision 4) and the
 * Achievements-precedent binding pattern: with cms/Widget disabled (its
 * default state, per cms/Widget/tests/Feature/WidgetModuleBoundaryTest),
 *
 * - every root Widget contract resolves to its safe/legacy App/root
 *   default, never a Cms\Widget class;
 * - Inline rendering through WidgetRenderer stays byte-identical to the
 *   legacy WidgetQueryService::preRender() path for a valid fixture;
 * - a stale copied-schema/unknown value and a Widget/Theme mismatch are
 *   rejected by the secure canonical default without diagnostics;
 * - the root <x-widget-slot> component renders its fallback/empty output;
 * - the registrar no-ops accept declarations harmlessly;
 * - LegacyWidgetMutationGuard permits ordinary rows and denies a marked
 *   safe-import-locked row;
 * - resolving a root registrar contract from an application-booted
 *   callback observes a boot()-time rebind that happened before it ran.
 *
 * Chunk 1 Task 9a addition below further proves, module disabled, that the
 * real App\View\Components\WidgetView Inline entry point (not just the
 * WidgetRenderer contract directly) allowlists against the live canonical
 * schema even when given a stale copied Page-block `inputs` schema — the
 * same shape resources/views/components/page-content-view.blade.php passes
 * via `:inputs="$item['inputs']"`. See the module-enabled counterpart in
 * cms/Widget/tests/Feature/Rendering/WidgetRenderCompatibilityTest.php.
 */
class WidgetModuleOffCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string|false $previousStatusesFile = false;

    private ?string $offStatusesFile = null;

    protected function setUp(): void
    {
        // Widget is a bundled, enabled module (modules_statuses.json, 2b2827538). This class proves the module-off
        // contract, so boot the application against a status copy with Widget switched off.
        $this->previousStatusesFile = getenv('MODULES_STATUSES_FILE');
        $source = dirname(__DIR__, 3) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
        $statuses['Widget'] = false;
        $this->offStatusesFile = sys_get_temp_dir() . '/ispa-widget-off-statuses-' . uniqid() . '.json';
        file_put_contents($this->offStatusesFile, json_encode($statuses));
        putenv('MODULES_STATUSES_FILE=' . $this->offStatusesFile);
        $_ENV['MODULES_STATUSES_FILE'] = $_SERVER['MODULES_STATUSES_FILE'] = $this->offStatusesFile;

        parent::setUp();

        $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-module-off-' . uniqid();
        File::ensureDirectoryExists($this->isolatedStoragePath . '/app/generated_widgets/default');
        $this->app->useStoragePath($this->isolatedStoragePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->isolatedStoragePath);

        parent::tearDown();

        if ($this->previousStatusesFile === false) {
            putenv('MODULES_STATUSES_FILE');
            unset($_ENV['MODULES_STATUSES_FILE'], $_SERVER['MODULES_STATUSES_FILE']);
        } else {
            putenv('MODULES_STATUSES_FILE=' . $this->previousStatusesFile);
            $_ENV['MODULES_STATUSES_FILE'] = $_SERVER['MODULES_STATUSES_FILE'] = $this->previousStatusesFile;
        }
        if ($this->offStatusesFile !== null) {
            @unlink($this->offStatusesFile);
        }
    }

    private function writeGeneratedTemplate(int $widgetId, int $themeId, string $content): string
    {
        $path = storage_path("app/generated_widgets/default/widget_{$widgetId}_theme_{$themeId}.blade.php");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        return $path;
    }

    // -----------------------------------------------------------------
    // Module-off ambient state + default bindings
    // -----------------------------------------------------------------

    public function test_widget_module_is_disabled_by_default(): void
    {
        $module = Module::find('Widget');

        $this->assertNotNull($module);
        $this->assertFalse($module->isEnabled());
    }

    public function test_every_root_widget_contract_resolves_to_its_safe_default_and_never_a_cms_widget_class(): void
    {
        $this->assertInstanceOf(LegacyWidgetRenderer::class, app(WidgetRenderer::class));
        $this->assertInstanceOf(NullWidgetSlotRuntime::class, app(WidgetSlotRuntime::class));
        $this->assertInstanceOf(LegacyWidgetMutationGuard::class, app(WidgetMutationGuard::class));
        $this->assertInstanceOf(NullWidgetExtensionRegistrar::class, app(WidgetSlotRegistrar::class));
        $this->assertInstanceOf(NullWidgetExtensionRegistrar::class, app(WidgetTemplateContextRegistrar::class));
        $this->assertInstanceOf(NullWidgetExtensionRegistrar::class, app(WidgetTargetContextRegistrar::class));
        $this->assertInstanceOf(NullWidgetExtensionRegistrar::class, app(WidgetDataProviderRegistrar::class));

        foreach ([
            WidgetRenderer::class,
            WidgetSlotRuntime::class,
            WidgetMutationGuard::class,
            WidgetSlotRegistrar::class,
            WidgetTemplateContextRegistrar::class,
            WidgetTargetContextRegistrar::class,
            WidgetDataProviderRegistrar::class,
            CanonicalWidgetSchemaResolver::class,
        ] as $contract) {
            $this->assertStringNotContainsStringIgnoringCase(
                'Cms\\Widget',
                get_class(app($contract)),
                "{$contract} must never resolve to a Cms\\Widget class while the module is disabled."
            );
        }
    }

    // -----------------------------------------------------------------
    // Inline rendering parity
    // -----------------------------------------------------------------

    public function test_inline_render_stays_byte_identical_to_the_legacy_prerender_path(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);

        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section data-marker="w-t">{title}</section>');

        $result = app(WidgetRenderer::class)->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Hello World']),
            new WidgetRenderContext(projectName: 'Default'),
        );

        $expectedHtml = WidgetQueryService::preRender(
            $widget->id,
            $theme->id,
            WidgetQueryService::sanitizeInputValues(['title' => 'Hello World'], [
                ['name' => 'title', 'type' => 'text'],
            ]),
        );

        $this->assertTrue($result->ok);
        $this->assertSame($expectedHtml, $result->html);
        $this->assertStringContainsString('Hello World', $result->html);
    }

    public function test_stale_copied_schema_key_is_dropped_without_diagnostics(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);

        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section data-marker="w-t">{title}{unknown_key}</section>');

        $withUnknown = app(WidgetRenderer::class)->render(
            new WidgetRenderSpec($widget->id, $theme->id, [
                'title' => 'Hello',
                'unknown_key' => '<script>alert(1)</script>',
            ]),
            new WidgetRenderContext(projectName: 'Default'),
        );

        $this->assertTrue($withUnknown->ok);
        $this->assertStringNotContainsString('<script>', $withUnknown->html);
        $this->assertStringNotContainsString('alert(1)', $withUnknown->html);
    }

    public function test_widget_view_component_supersedes_stale_copied_page_inputs_schema_when_module_disabled(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        // The template deliberately carries BOTH tokens: if the stale copied
        // schema won, the subtitle payload WOULD be substituted into
        // {subtitle} and leak into the rendered HTML (escaped or raw, either
        // contains 'alert(1)') — that substitution target is what makes the
        // assertions below discriminating rather than vacuously green.
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section data-marker="w-t">{title}{subtitle}</section>');

        $this->assertInstanceOf(LegacyWidgetRenderer::class, app(WidgetRenderer::class));

        // Matches resources/views/components/page-content-view.blade.php's
        // real <x-widget-view :inputs="$item['inputs']" ...> usage: `inputs`
        // is the schema snapshot a Page/Post content block cached at
        // authoring time — stale relative to the live canonical Widget
        // Inputs by render time, and advisory only.
        $staleInputs = [
            ['name' => 'title', 'type' => 'text', 'default_value' => null, 'value' => null, 'options' => []],
            ['name' => 'subtitle', 'type' => 'text', 'default_value' => null, 'value' => null, 'options' => []],
        ];

        $html = (new WidgetView(
            widget: $widget->id,
            templateId: $theme->id,
            value: ['title' => 'Hello World', 'subtitle' => '<script>alert(1)</script>'],
            inputs: $staleInputs,
        ))->render()->render();

        $this->assertStringContainsString('Hello World', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        // The canonical allowlist dropped 'subtitle' BEFORE token
        // substitution, so the literal {subtitle} placeholder survives
        // unreplaced (current legacy behavior for unfilled tokens). Had the
        // stale copied schema been trusted, substitution would have consumed
        // the token — escaped payload still contains 'alert(1)' and fails
        // the assertions above.
        $this->assertStringContainsString('{subtitle}', $html);
    }

    public function test_widget_view_repairs_stale_numeric_references_from_stable_keys(): void
    {
        $widget = (new Widget())->forceFill([
            'widget_key' => 'test.identity-widget',
            'name' => 'Identity Widget',
        ]);
        $widget->save();
        $theme = (new WidgetTheme())->forceFill([
            'widget_id' => $widget->id,
            'theme_key' => 'default',
            'name' => 'Identity Theme',
            'content' => '<div>{title}</div>',
        ]);
        $theme->save();
        $input = (new WidgetInput())->forceFill([
            'widget_id' => $widget->id,
            'input_key' => 'title',
            'name' => 'title',
            'type' => 'text',
        ]);
        $input->save();

        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section>{title}</section>');

        $html = (new WidgetView(
            widget: 999999,
            templateId: 999999,
            widgetKey: 'test.identity-widget',
            themeKey: 'default',
            value: ['title' => 'Stable identity'],
        ))->render()->render();

        $this->assertStringContainsString('Stable identity', $html);
    }

    public function test_widget_view_keeps_valid_numeric_references_when_only_one_stable_key_is_present(): void
    {
        $widget = Widget::query()->create(['name' => 'Partial Key Widget']);
        $theme = WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Partial Key Theme',
            'content' => '<div>{title}</div>',
        ]);
        WidgetInput::query()->create([
            'widget_id' => $widget->id,
            'name' => 'title',
            'type' => 'text',
        ]);
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section>{title}</section>');

        $html = (new WidgetView(
            widget: $widget->id,
            templateId: $theme->id,
            widgetKey: $widget->widget_key,
            value: ['title' => 'Numeric fallback'],
        ))->render()->render();

        $this->assertStringContainsString('Numeric fallback', $html);
    }

    public function test_widget_theme_mismatch_is_rejected_without_diagnostics(): void
    {
        $widgetA = Widget::query()->create(['name' => 'Widget A']);
        WidgetTheme::query()->create(['widget_id' => $widgetA->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);

        $widgetB = Widget::query()->create(['name' => 'Widget B']);
        $themeB1 = WidgetTheme::query()->create(['widget_id' => $widgetB->id, 'name' => 'Theme B1', 'content' => '<div>B1</div>']);

        $result = app(WidgetRenderer::class)->render(
            new WidgetRenderSpec($widgetA->id, $themeB1->id, ['title' => 'Hello']),
            new WidgetRenderContext(projectName: 'Default'),
        );

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame('WIDGET_SCHEMA_INVALID', $result->errorCode);
    }

    // -----------------------------------------------------------------
    // Root <x-widget-slot> component
    // -----------------------------------------------------------------

    public function test_widget_slot_component_renders_fallback_when_module_is_disabled(): void
    {
        $html = (string) Blade::render('<x-widget-slot slot-key="homepage.header">Fallback content</x-widget-slot>');

        $this->assertStringContainsString('Fallback content', $html);
    }

    public function test_widget_slot_component_renders_empty_output_without_a_fallback(): void
    {
        $html = trim((string) Blade::render('<x-widget-slot slot-key="homepage.header"></x-widget-slot>'));

        $this->assertSame('', $html);
    }

    // -----------------------------------------------------------------
    // Registrar no-ops
    // -----------------------------------------------------------------

    public function test_registrar_defaults_accept_declarations_harmlessly(): void
    {
        app(WidgetSlotRegistrar::class)->registerSlot(new WidgetSlotDefinition('homepage.header', 'slot.homepage_header', 'stack'));
        app(WidgetTemplateContextRegistrar::class)->registerTemplateContext('homepage', ['homepage.header']);
        app(WidgetTargetContextRegistrar::class)->registerTargetContext(new WidgetTargetContextDefinition(
            'page',
            'target.page',
            'homepage',
            'App\\NonExistent\\PageTargetResolver',
        ));
        app(WidgetDataProviderRegistrar::class); // resolvable without error; providers registered per-instance elsewhere.

        $this->addToAssertionCount(1); // No exception thrown above is the proof.
    }

    // -----------------------------------------------------------------
    // LegacyWidgetMutationGuard / lock gateway
    // -----------------------------------------------------------------

    public function test_mutation_guard_permits_an_ordinary_unmarked_legacy_row(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A']);

        $this->assertTrue(app(WidgetMutationGuard::class)->permitsMutation('widgets', $widget->id));
    }

    public function test_mutation_guard_denies_a_row_carrying_the_safe_import_lock_marker(): void
    {
        // Runtime ALTER TABLE is safe here ONLY because the suite runs on
        // sqlite :memory: (phpunit.xml DB_CONNECTION=sqlite): RefreshDatabase
        // gives every test its own in-memory schema, so the added column
        // cannot leak into another test or a real database. On MySQL a DDL
        // statement would implicitly commit the wrapping transaction — do
        // not copy this pattern outside sqlite-backed tests.
        Schema::table('widgets', function ($table) {
            $table->timestamp('safe_import_locked_at')->nullable();
        });

        $locked = Widget::query()->create(['name' => 'Locked Widget']);
        $unlocked = Widget::query()->create(['name' => 'Unlocked Widget']);

        Widget::query()->whereKey($locked->id)->update(['safe_import_locked_at' => now()]);

        $guard = app(WidgetMutationGuard::class);

        $this->assertFalse($guard->permitsMutation('widgets', $locked->id));
        $this->assertTrue($guard->permitsMutation('widgets', $unlocked->id));
    }

    // -----------------------------------------------------------------
    // Provider-order (boot-not-register) regression proof
    // -----------------------------------------------------------------

    public function test_resolving_a_registrar_from_a_booted_callback_observes_a_prior_boot_time_rebind(): void
    {
        // Resolving eagerly, before any rebind, captures the root default —
        // this is the failure mode the ADR's boot()-not-register() rebind
        // ordering exists to avoid for a real module provider.
        $eager = app(WidgetSlotRegistrar::class);
        $this->assertInstanceOf(NullWidgetExtensionRegistrar::class, $eager);

        // Simulate what Cms\Widget\Providers\WidgetServiceProvider::boot()
        // does: a plain singleton() rebind, performed from a service
        // provider's boot() method.
        $fake = new class implements WidgetSlotRegistrar {
            public function registerSlot(WidgetSlotDefinition $definition): void
            {
            }
        };
        $this->app->singleton(WidgetSlotRegistrar::class, fn () => $fake);

        // A project/module declaration that resolves the contract from an
        // application-booted callback (the frozen registration-timing
        // contract) must observe the rebind that already happened above —
        // proving the "resolve from booted(), not at file-load time" rule
        // actually protects against stale early resolution.
        $resolvedDuringBooted = null;
        $this->app->booted(function () use (&$resolvedDuringBooted) {
            $resolvedDuringBooted = app(WidgetSlotRegistrar::class);
        });

        $this->assertSame($fake, $resolvedDuringBooted);
        $this->assertNotSame($eager, $resolvedDuringBooted);
    }
}
