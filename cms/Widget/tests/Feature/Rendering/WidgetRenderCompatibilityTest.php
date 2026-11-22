<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Rendering;

use App\Constants\UserType;
use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Contracts\Widget\WidgetRenderer;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Widget\CanonicalWidgetSchema;
use App\Support\Widget\LegacyWidgetRenderer;
use App\View\Components\WidgetView;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\LegacyWidgetRenderAdapter;
use Cms\Widget\Rendering\WidgetRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Chunk 1 Task 8: module-owned Render Service delegation.
 *
 * Proves:
 * - Cms\Widget\Providers\WidgetServiceProvider::boot() rebinds the root
 *   WidgetRenderer contract to Cms\Widget\Rendering\WidgetRenderService,
 *   winning over the App\Providers\AppServiceProvider::register() default
 *   regardless of other providers' boot() ordering (this is the real proof
 *   that replaces Task 7's simulated rebind test — see
 *   docs/architecture/adr-widget-module-boundary.md decision 3).
 * - Admin preview (WidgetController::preview()) and Inline rendering
 *   (WidgetView) build the same WidgetRenderSpec for the same fixture.
 * - WidgetRenderService output stays byte-identical to the root
 *   LegacyWidgetRenderer default for legacy fixtures, because
 *   LegacyWidgetRenderAdapter composes the SAME root Support classes
 *   instead of re-implementing canonical-schema/sanitize logic.
 * - LegacyWidgetRenderAdapter is Inline/compatibility-only: it exposes no
 *   Site Layout / Slot / Placement surface.
 * - Admin preview maps a render-level validation failure to 422 and an
 *   unexpected render failure to a sanitized 500, without breaking the
 *   frozen WidgetAdminContractTest envelope contract for the untouched
 *   404 / 400 / raw-string-200 paths.
 *
 * Chunk 1 Task 9a additions below further prove, with cms/Widget booted
 * (module enabled):
 * - Cms\Widget\Providers\WidgetServiceProvider::boot() reuses the Task 7
 *   secure root App\Contracts\Widget\CanonicalWidgetSchemaResolver
 *   unchanged — it never rebinds it to a weaker/different resolver.
 * - The real App\View\Components\WidgetView Inline entry point, given a
 *   stale copied Page-block `inputs` schema (the same shape
 *   resources/views/components/page-content-view.blade.php passes via
 *   `:inputs="$item['inputs']"`), still allowlists against the live
 *   canonical schema, not the stale copy.
 *
 * @see \Cms\Core\Tests\Feature\Http\Controllers\V2\Admin\WidgetAdminContractTest
 *      for the frozen legacy HTTP envelope characterization this task must
 *      not break.
 */
final class WidgetRenderCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_BASE = '/api/v2/admin';

    private string $isolatedStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-render-service-' . uniqid();
        File::ensureDirectoryExists($this->isolatedStoragePath . '/app/generated_widgets/default');
        $this->app->useStoragePath($this->isolatedStoragePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->isolatedStoragePath);

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function writeGeneratedTemplate(int $widgetId, int $themeId, string $content): void
    {
        $path = storage_path("app/generated_widgets/default/widget_{$widgetId}_theme_{$themeId}.blade.php");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
    }

    /**
     * Instantiates and runs the real module provider's register()+boot()
     * lifecycle directly against the test container, without touching the
     * real modules_statuses.json file (the module stays disabled there —
     * see cms/Widget/tests/Feature/WidgetModuleBoundaryTest). This is the
     * cleanest in-process module-enable seam investigated for this task:
     * it exercises the exact same provider class Nwidart would boot in
     * production, and later module tests can reuse this pattern.
     */
    /**
     * Widget is an enabled bundled module (since 2026-07-20), so the app has already rebound WidgetRenderer to the
     * module service. Re-apply the root default (AppServiceProvider) to model the module-off state.
     */
    private function useModuleOffRendererBinding(): void
    {
        $this->app->singleton(WidgetRenderer::class, LegacyWidgetRenderer::class);
    }

    private function bootWidgetServiceProvider(): void
    {
        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }

    private function createUserWithWidgetViewPermission(): User
    {
        $permission = Permission::query()->create([
            'group' => 'widget',
            'name' => 'view',
            'title' => 'widget view',
        ]);

        $user = User::query()->create([
            'name' => 'Widget Render Admin',
            'email' => 'widget-render-admin-' . uniqid() . '@test.local',
            'password' => Hash::make('password'),
        ]);
        $user->forceFill(['type' => UserType::ADMIN])->save();

        $role = Role::query()->create(['name' => 'widget-render-admin-' . uniqid(), 'user_id' => $user->id]);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        return $user;
    }

    // -----------------------------------------------------------------
    // Boot-time rebind (real proof, replacing Task 7's simulated version)
    // -----------------------------------------------------------------

    public function test_widget_renderer_resolves_to_legacy_default_before_module_boots(): void
    {
        $this->useModuleOffRendererBinding();
        $this->assertInstanceOf(LegacyWidgetRenderer::class, app(WidgetRenderer::class));
    }

    public function test_module_service_provider_boot_rebinds_widget_renderer_to_widget_render_service(): void
    {
        $this->bootWidgetServiceProvider();

        $renderer = app(WidgetRenderer::class);

        $this->assertInstanceOf(WidgetRenderService::class, $renderer);
        $this->assertNotInstanceOf(LegacyWidgetRenderer::class, $renderer);
    }

    public function test_boot_time_rebind_persists_regardless_of_other_providers_boot_order(): void
    {
        $this->bootWidgetServiceProvider();
        $this->assertInstanceOf(WidgetRenderService::class, app(WidgetRenderer::class));

        // A later boot() call from another provider that never touches
        // WidgetRenderer (AppServiceProvider only rebinds it in register(),
        // never in boot()) must not undo the module rebind, no matter where
        // in the boot-phase sequence it falls relative to the module.
        (new AppServiceProvider($this->app))->boot();

        $this->assertInstanceOf(
            WidgetRenderService::class,
            app(WidgetRenderer::class),
            'A later boot() call from another provider must not undo the module boot()-time rebind.'
        );
    }

    public function test_boot_phase_rebind_wins_even_when_app_service_provider_registers_after_the_module(): void
    {
        // Reproduce the exact production ordering hazard ADR decision 3
        // guards against: Nwidart auto-discovery runs every enabled module
        // provider's register() BEFORE AppServiceProvider::register(), so
        // anything the module binds during its register() phase is silently
        // overwritten by the App default bound below.
        $moduleProvider = new WidgetServiceProvider($this->app);
        $moduleProvider->register();

        (new AppServiceProvider($this->app))->register();

        // After both register() phases the App default holds — a module
        // register()-time rebind (the known-broken pattern) would be gone.
        $this->assertInstanceOf(LegacyWidgetRenderer::class, app(WidgetRenderer::class));

        // Only the boot()-phase rebind survives, because Laravel runs every
        // register() before any boot(). Moving the singleton rebind from
        // WidgetServiceProvider::boot() to register() makes this fail.
        $moduleProvider->boot();

        $this->assertInstanceOf(WidgetRenderService::class, app(WidgetRenderer::class));
    }

    public function test_module_boot_does_not_rebind_canonical_widget_schema_resolver_to_a_weaker_implementation(): void
    {
        // WidgetServiceProvider::boot() rebinds exactly one contract
        // (WidgetRenderer). It must never touch CanonicalWidgetSchemaResolver
        // — the Task 7 secure root resolver is reused unchanged in every
        // Inline/admin/module render path, module enabled or disabled.
        $beforeBoot = app(CanonicalWidgetSchemaResolver::class);
        $this->assertInstanceOf(CanonicalWidgetSchema::class, $beforeBoot);

        $this->bootWidgetServiceProvider();

        $afterBoot = app(CanonicalWidgetSchemaResolver::class);

        $this->assertInstanceOf(
            CanonicalWidgetSchema::class,
            $afterBoot,
            'Cms\\Widget\\Providers\\WidgetServiceProvider::boot() must reuse the Task 7 secure root '
                . 'CanonicalWidgetSchemaResolver, never rebind it to a weaker/different resolver.'
        );
        $this->assertSame(
            $beforeBoot,
            $afterBoot,
            'The root CanonicalWidgetSchemaResolver singleton must be untouched by module boot — same '
                . 'instance before and after, proving no rebind happened at all (not even to an equivalent class).'
        );
    }

    // -----------------------------------------------------------------
    // Same WidgetRenderSpec for preview and Inline
    // -----------------------------------------------------------------

    public function test_admin_preview_and_inline_widget_view_construct_the_same_widget_render_spec(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section>{title}</section>');

        $recorder = new class implements WidgetRenderer {
            /** @var array<int, WidgetRenderSpec> */
            public array $specs = [];

            public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult
            {
                $this->specs[] = $spec;

                return new WidgetRenderResult(html: '<recorded/>', ok: true);
            }
        };
        $this->app->instance(WidgetRenderer::class, $recorder);

        // Path 1: Inline rendering (WidgetView), given the same value shape
        // a persisted Page/Post widget block already carries.
        (new WidgetView(widget: $widget->id, templateId: $theme->id, value: ['title' => 'Hello World']))->render();

        // Path 2: admin preview, given the exact same explicit value so the
        // default-fill branch (preview-only convenience, absent from
        // Inline) never triggers.
        $user = $this->createUserWithWidgetViewPermission();
        Sanctum::actingAs($user);
        URL::forceScheme('https');

        $this->postJson(self::ADMIN_BASE . "/widget/{$widget->id}/preview", [
            'template_id' => $theme->id,
            'title' => 'Hello World',
        ])->assertOk();

        $this->assertCount(2, $recorder->specs, 'Both render paths must call the resolved WidgetRenderer exactly once.');
        $this->assertEquals($recorder->specs[0], $recorder->specs[1]);
        $this->assertSame($widget->id, $recorder->specs[0]->widgetId);
        $this->assertSame($theme->id, $recorder->specs[0]->themeId);
        $this->assertSame(['title' => 'Hello World'], $recorder->specs[0]->values);
    }

    // -----------------------------------------------------------------
    // Byte-parity through the module
    // -----------------------------------------------------------------

    public function test_widget_render_service_output_is_byte_identical_to_the_legacy_default_for_a_valid_fixture(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section data-marker="w-t">{title}</section>');

        $spec = new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Hello World']);
        $context = new WidgetRenderContext(projectName: 'Default');

        $this->useModuleOffRendererBinding();
        $expected = app(WidgetRenderer::class)->render($spec, $context);
        $this->assertInstanceOf(LegacyWidgetRenderer::class, app(WidgetRenderer::class));

        $this->bootWidgetServiceProvider();
        $this->assertInstanceOf(WidgetRenderService::class, app(WidgetRenderer::class));

        $actual = app(WidgetRenderer::class)->render($spec, $context);

        $this->assertTrue($expected->ok);
        $this->assertTrue($actual->ok);
        $this->assertSame($expected->html, $actual->html);
        $this->assertStringContainsString('Hello World', $actual->html);
    }

    public function test_legacy_renderer_escapes_list_values_exactly_once(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget List']);
        $theme = WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Theme List',
            'content' => 'List',
        ]);
        WidgetInput::query()->create([
            'widget_id' => $widget->id,
            'name' => 'list_bullets',
            'type' => 'list',
        ]);
        $this->writeGeneratedTemplate(
            $widget->id,
            $theme->id,
            '{list:bullets variable="$item" template=`<p>{{ $item }}</p>`}'
        );

        $result = app(WidgetRenderer::class)->render(
            new WidgetRenderSpec($widget->id, $theme->id, [
                'list_bullets' => [
                    'Ekip quay chụp & edit video đẹp, chuyên nghiệp.',
                    '<script>alert(1)</script>',
                ],
            ]),
            new WidgetRenderContext(projectName: 'Default'),
        );

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('Ekip quay chụp &amp; edit video đẹp, chuyên nghiệp.', $result->html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result->html);
        $this->assertStringNotContainsString('&amp;amp;', $result->html);
        $this->assertStringNotContainsString('<script>', $result->html);
    }

    public function test_widget_render_service_rejects_widget_theme_mismatch_exactly_like_the_legacy_default(): void
    {
        $widgetA = Widget::query()->create(['name' => 'Widget A']);
        WidgetTheme::query()->create(['widget_id' => $widgetA->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);

        $widgetB = Widget::query()->create(['name' => 'Widget B']);
        $themeB1 = WidgetTheme::query()->create(['widget_id' => $widgetB->id, 'name' => 'Theme B1', 'content' => '<div>B1</div>']);

        $this->bootWidgetServiceProvider();
        $this->assertInstanceOf(WidgetRenderService::class, app(WidgetRenderer::class));

        $result = app(WidgetRenderer::class)->render(
            new WidgetRenderSpec($widgetA->id, $themeB1->id, ['title' => 'Hello']),
            new WidgetRenderContext(projectName: 'Default'),
        );

        // "Public returns an isolated empty result plus diagnostics" (plan
        // Task 8): the module-owned service inherits this shape unchanged
        // from the root default via LegacyWidgetRenderAdapter.
        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame('WIDGET_SCHEMA_INVALID', $result->errorCode);
    }

    // -----------------------------------------------------------------
    // Stale copied Page metadata supersession (module enabled)
    // -----------------------------------------------------------------

    public function test_widget_view_component_supersedes_stale_copied_page_inputs_schema_when_module_enabled(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);
        // Canonical schema today: only 'title'. 'subtitle' is a key a Page
        // block's cached `inputs` snapshot still carries (e.g. it existed at
        // authoring time, or never really was a canonical field) — a
        // stale/copied schema by render time. The template deliberately
        // carries BOTH tokens: if the stale copied schema won, the subtitle
        // payload WOULD be substituted into {subtitle} and leak into the
        // rendered HTML (escaped or raw, either contains 'alert(1)') — that
        // substitution target is what makes the assertions below
        // discriminating rather than vacuously green.
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section data-marker="w-t">{title}{subtitle}</section>');

        $this->bootWidgetServiceProvider();
        $this->assertInstanceOf(WidgetRenderService::class, app(WidgetRenderer::class));

        // Matches resources/views/components/page-content-view.blade.php's
        // real <x-widget-view :inputs="$item['inputs']" ...> usage: `inputs`
        // is the schema snapshot a Page/Post content block cached, `value`
        // is the caller-supplied data — both advisory, never authoritative.
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

    // -----------------------------------------------------------------
    // LegacyWidgetRenderAdapter: Inline/compatibility-only guard
    // -----------------------------------------------------------------

    public function test_legacy_widget_render_adapter_exposes_no_site_layout_surface(): void
    {
        $reflection = new ReflectionClass(LegacyWidgetRenderAdapter::class);

        $this->assertTrue(
            $reflection->isFinal(),
            'LegacyWidgetRenderAdapter must be final so no subclass can smuggle in a Site Layout path.'
        );

        $publicMethods = array_values(array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => !$method->isConstructor()
        ));
        $methodNames = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $publicMethods);

        // Pinning the exact method list to ['render'] already guarantees no
        // Site Layout / Slot / Placement method can appear outside Task 9c
        // review — any new public method fails the assertion above.
        $this->assertSame(
            ['render'],
            $methodNames,
            'LegacyWidgetRenderAdapter must expose exactly one public method: render().'
        );

        $renderMethod = $reflection->getMethod('render');
        $parameters = $renderMethod->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertSame(WidgetRenderSpec::class, $parameters[0]->getType()?->getName());
        $this->assertSame(WidgetRenderContext::class, $parameters[1]->getType()?->getName());
        $this->assertSame(WidgetRenderResult::class, $renderMethod->getReturnType()?->getName());

        $docComment = (string) $reflection->getDocComment();
        $this->assertStringContainsStringIgnoringCase('inline', $docComment);
        $this->assertStringContainsStringIgnoringCase('compatibility', $docComment);
    }

    // -----------------------------------------------------------------
    // Admin preview: 422 validation failure / sanitized 500 / raw-string 200
    // -----------------------------------------------------------------

    public function test_admin_preview_still_maps_widget_theme_mismatch_to_422(): void
    {
        URL::forceScheme('https');
        $user = $this->createUserWithWidgetViewPermission();
        Sanctum::actingAs($user);

        $widgetA = Widget::query()->create(['name' => 'Widget A']);
        WidgetTheme::query()->create(['widget_id' => $widgetA->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);

        $widgetB = Widget::query()->create(['name' => 'Widget B']);
        $themeB1 = WidgetTheme::query()->create(['widget_id' => $widgetB->id, 'name' => 'Theme B1', 'content' => '<div>B1</div>']);

        $this->postJson(self::ADMIN_BASE . "/widget/{$widgetA->id}/preview", [
            'template_id' => $themeB1->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_admin_preview_maps_an_unexpected_render_failure_to_a_sanitized_500(): void
    {
        URL::forceScheme('https');
        $user = $this->createUserWithWidgetViewPermission();
        Sanctum::actingAs($user);

        $widget = Widget::query()->create(['name' => 'Widget A']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);

        $throwing = new class implements WidgetRenderer {
            public function render(WidgetRenderSpec $spec, WidgetRenderContext $context): WidgetRenderResult
            {
                throw new RuntimeException('RENDER_SERVICE_INTERNAL_FAILURE_MARKER: connection string leaked here');
            }
        };
        $this->app->instance(WidgetRenderer::class, $throwing);

        $response = $this->postJson(self::ADMIN_BASE . "/widget/{$widget->id}/preview", [
            'template_id' => $theme->id,
        ]);

        $response->assertStatus(500)->assertJsonPath('status', 'error');
        $this->assertStringNotContainsString('RENDER_SERVICE_INTERNAL_FAILURE_MARKER', $response->getContent());
        $this->assertStringNotContainsString('connection string leaked', $response->getContent());
    }

    public function test_admin_preview_success_stays_a_raw_html_response_not_a_json_envelope(): void
    {
        // The preview prepends an @vite() entry for the project theme. It resolves only in a checkout whose Vite build
        // knows that entry, which this test does not depend on.
        $this->withoutVite();
        URL::forceScheme('https');
        $user = $this->createUserWithWidgetViewPermission();
        Sanctum::actingAs($user);

        $widget = Widget::query()->create(['name' => 'Widget A']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section data-marker="preview-raw">{title}</section>');

        $response = $this->postJson(self::ADMIN_BASE . "/widget/{$widget->id}/preview", [
            'template_id' => $theme->id,
            'title' => 'Preview Body',
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString('application/json', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('Preview Body', $response->getContent());
        $this->assertStringContainsString('data-marker="preview-raw"', $response->getContent());
    }
}
