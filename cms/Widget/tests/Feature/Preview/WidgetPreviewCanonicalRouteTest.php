<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Preview;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Http\Middleware\PrepareAnonymousWidgetPreviewRequest;
use App\Models\User;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Preview\WidgetPreviewContext;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Predis\Client as PredisClient;
use Tests\TestCase;

/**
 * Chunk 2 Task 19: full HTTP round-trip through the REAL Kernel middleware
 * stack against a test-only canonical route fixture (plan lines 1082-1124
 * DoD 1105, 1109, 1111, 1113; architecture.md "Public preview request").
 *
 * The fixture route below is registered ONLY inside this test file (DoD
 * 1109: "test-only canonical route fixture ... without editing pilot Blade
 * early"). Its closure introspects {@see WidgetPreviewContext} container
 * binding to PROVE the overlay was actually installed by
 * {@see \Cms\Widget\Http\Middleware\ResolveWidgetPreviewSession} for the
 * request, and that the SAME existing route/closure executes anonymously
 * either way -- never a parallel preview-only renderer.
 */
final class WidgetPreviewCanonicalRouteTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.canonicalroute.slot';

    private const TEMPLATE_CONTEXT = 'test.canonicalroute.context';

    private const TARGET_TYPE = 'test.canonicalroute.target';

    private const ROUTE_NAME = 'test.widget.preview.canonical-route';

    private string $storageRoot;

    /** @var array<string, string|null> */
    private static array $modulesStatusesSnapshot = [];

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $path = dirname(__DIR__, 5) . '/modules_statuses.json';
        self::$modulesStatusesSnapshot = [$path => file_exists($path) ? file_get_contents($path) : null];

        register_shutdown_function(static function (): void {
            self::restoreModulesStatuses();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreModulesStatuses();

        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private static function restoreModulesStatuses(): void
    {
        foreach (self::$modulesStatusesSnapshot as $path => $original) {
            if ($original !== null) {
                file_put_contents($path, $original);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        self::$modulesStatusesSnapshot = [];
    }

    /**
     * Whether the ephemeral test Redis (127.0.0.1:6399) accepts TCP connections.
     * Probed once per PHP process so unreachable-Redis runs skip cheaply instead
     * of building huge Predis error traces.
     */
    private static function ephemeralRedisReachable(): bool
    {
        static $reachable = null;

        if ($reachable === null) {
            // A no-op handler keeps the expected connection warning out of the test report.
            set_error_handler(static fn (): bool => true);
            $socket = fsockopen('127.0.0.1', 6399, $errno, $errstr, 0.5);
            restore_error_handler();
            $reachable = $socket !== false;
            if ($socket !== false) {
                fclose($socket);
            }
        }

        return $reachable;
    }

    protected function setUp(): void
    {
        if (! self::ephemeralRedisReachable()) {
            $this->markTestSkipped('Redis 127.0.0.1:6399 not reachable');
        }

        parent::setUp();

        config(['database.redis.widget_preview' => [
            'host' => '127.0.0.1',
            'port' => 6399,
            'database' => 15,
            'timeout' => 2.0,
        ]]);
        (new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 15]))->flushdb();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = storage_path('framework/testing/widget-preview-route-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $slots = $this->app->make(WidgetSlotRegistry::class);
        $slots->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'test.canonicalroute.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        $templateContexts = $this->app->make(WidgetTemplateContextRegistry::class);
        $templateContexts->registerTemplateContext(self::TEMPLATE_CONTEXT, [self::SLOT]);

        $fakeResolver = new FakeWidgetTargetContextResolver();
        $fakeResolver->withTarget(
            targetId: 'route-target-1',
            targetType: self::TARGET_TYPE,
            projectName: self::PROJECT,
            label: 'Route Target One',
            routeName: self::ROUTE_NAME,
            routeParameters: ['slug' => 'route-target-1'],
        );
        $fakeResolver->withTarget(
            targetId: 'route-target-2',
            targetType: self::TARGET_TYPE,
            projectName: self::PROJECT,
            label: 'Route Target Two',
            routeName: self::ROUTE_NAME,
            routeParameters: ['slug' => 'route-target-2'],
        );
        $this->app->instance(FakeWidgetTargetContextResolver::class, $fakeResolver);

        $targetContexts = $this->app->make(WidgetTargetContextRegistry::class);
        $targetContexts->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: self::TARGET_TYPE,
            labelKey: 'test.canonicalroute.target.label',
            templateContextKey: self::TEMPLATE_CONTEXT,
            resolverService: FakeWidgetTargetContextResolver::class,
        ));

        if (!Route::has(self::ROUTE_NAME)) {
            Route::middleware(['web'])
                ->get('/__test/widget-preview-canonical/{slug}', static function (string $slug) {
                    if (app()->bound(WidgetPreviewContext::class)) {
                        /** @var WidgetPreviewContext $context */
                        $context = app(WidgetPreviewContext::class);

                        return response()->json([
                            'mode' => 'preview',
                            'slug' => $slug,
                            'locale' => $context->locale,
                            'overlay_slot_keys' => array_values(array_unique(array_column($context->overlayPlacements, 'slot_key'))),
                            'viewport' => $context->viewport,
                        ]);
                    }

                    return response()->json(['mode' => 'public', 'slug' => $slug]);
                })
                ->name(self::ROUTE_NAME);
            app('router')->getRoutes()->refreshNameLookups();
        }

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return $onAcquired();
            }
        });

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);
    }

    protected function tearDown(): void
    {
        // storageRoot is uninitialized when setUp() skipped before creating it.
        if (isset($this->storageRoot) && is_dir($this->storageRoot)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function createWidget(): Widget
    {
        return Widget::query()->create(['name' => 'Test Widget', 'description' => '', 'js' => '']);
    }

    private function createTheme(Widget $widget): WidgetTheme
    {
        return WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Test Theme',
            'content' => '<div class="card">{title}</div>',
            'css' => '.card { color: red; }',
            'project_name' => [self::PROJECT],
        ]);
    }

    private function liveSourceFingerprint(Widget $widget, WidgetTheme $theme): string
    {
        $fingerprint = (new LegacyWidgetExecutableSurfaceAnalyzer())->analyze(
            widgetJs: (string) $widget->js,
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: (new CanonicalWidgetSchema())->resolve($widget->id, $theme->id),
            policyVersion: ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: is_array($theme->project_name) ? $theme->project_name : ['Default'],
            artifactBytes: (new LegacyWidgetArtifactSetPublisher())->renderThemeContent($theme),
        );

        return $fingerprint->manifestHash;
    }

    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Canonical Route Tester',
            'email' => 'canonical-route-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
            'type' => $type,
        ]);

        if ($permissionKeys === []) {
            return $user;
        }

        (new WidgetPermissionTableSeeder())->run();

        $role = Role::query()->create(['name' => 'Test Role ' . uniqid('', true), 'custom' => ['level' => 50]]);
        foreach ($permissionKeys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permission = Permission::query()->where('group', $group)->where('name', $name)->firstOrFail();
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function fullAccessUser(): User
    {
        return $this->userOfType(UserType::ADMIN, [
            'widget_instance.create',
            'widget_layout.preview',
            'widget_template.approve',
        ]);
    }

    private function approveManifestFor(Widget $widget, WidgetTheme $theme, User $approver): string
    {
        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
            ]);
        $response->assertSuccessful();

        $manifestHash = (string) $response->json('data.manifest_hash');
        app(WidgetManifestApprovalService::class)->approve(self::PROJECT, $manifestHash, 1, (int) $approver->id, 'test approval');

        return $manifestHash;
    }

    private function createReadyInstance(Widget $widget, WidgetTheme $theme, User $user): WidgetInstance
    {
        $this->approveManifestFor($widget, $theme, $user);

        $result = app(WidgetInstanceService::class)->create(
            self::PROJECT,
            (int) $user->id,
            'create-key-' . uniqid('', true),
            [
                'name' => 'Canonical Route Instance',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Original'],
            ],
        );

        return $result->instance;
    }

    private function createLayout(string $key = 'site'): WidgetLayout
    {
        return WidgetLayout::query()->create(['project_name' => self::PROJECT, 'key' => $key]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overlayFor(WidgetInstance $instance, string $targetId = 'route-target-1'): array
    {
        return [[
            'slot_key' => self::SLOT,
            'scope_type' => 'target',
            'target_type' => self::TARGET_TYPE,
            'target_id' => $targetId,
            'widget_instance_uuid' => $instance->uuid,
            'widget_instance_version' => 1,
            'position' => 0,
            'enabled' => true,
            'rules' => [],
        ]];
    }

    private function mintPreviewUrl(User $admin, User $previewer, WidgetInstance $instance, WidgetLayout $layout, string $targetId = 'route-target-1', ?string $viewport = 'desktop'): string
    {
        $response = $this->actingAs($previewer, 'sanctum')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/preview-sessions", [
                'overlay' => $this->overlayFor($instance, $targetId),
                'locale' => 'vi',
                'template_context' => self::TEMPLATE_CONTEXT,
                'target_type' => self::TARGET_TYPE,
                'target_id' => $targetId,
                'viewport' => $viewport,
            ]);
        $response->assertStatus(201);

        return (string) $response->json('preview_url');
    }

    /**
     * @return array{admin: User, previewer: User, instance: WidgetInstance, layout: WidgetLayout}
     */
    private function readyFixture(): array
    {
        $admin = $this->fullAccessUser();
        $previewer = $this->userOfType(UserType::ADMIN, ['widget_layout.preview']);
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        return ['admin' => $admin, 'previewer' => $previewer, 'instance' => $instance, 'layout' => $layout];
    }

    // -----------------------------------------------------------------
    // Full round trip
    // -----------------------------------------------------------------

    public function test_visiting_the_signed_preview_url_renders_the_overlay_through_the_real_route(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['previewer'], $fixture['instance'], $fixture['layout']);

        $response = $this->get($previewUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('mode', 'preview');
        $response->assertJsonPath('slug', 'route-target-1');
        $response->assertJsonPath('locale', 'vi');
        $response->assertJsonPath('viewport', 'desktop');
        $this->assertContains(self::SLOT, $response->json('overlay_slot_keys'));
    }

    public function test_visiting_the_canonical_route_without_any_token_renders_the_ordinary_public_response(): void
    {
        $response = $this->get('/__test/widget-preview-canonical/route-target-1');

        $response->assertStatus(200);
        $response->assertJsonPath('mode', 'public');
    }

    public function test_replaying_an_already_consumed_preview_url_fails_the_second_time(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['previewer'], $fixture['instance'], $fixture['layout']);

        $first = $this->get($previewUrl);
        $first->assertJsonPath('mode', 'preview');

        $second = $this->get($previewUrl);
        $second->assertJsonPath('code', 'WIDGET_PREVIEW_SESSION_EXPIRED');
    }

    public function test_a_tampered_token_never_consumes_the_real_session_which_remains_valid_afterward(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['previewer'], $fixture['instance'], $fixture['layout']);

        // Flip one character inside the token query value -- this also
        // invalidates Laravel's own HMAC signature over the full URL, so
        // the ROOT middleware never even marks this a candidate (DoD 1107:
        // "Invalid candidates do not receive preview behavior").
        $tampered = preg_replace_callback(
            '/(' . preg_quote(PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER, '/') . '=)([0-9a-f])/',
            static fn (array $m) => $m[1] . ($m[2] === '0' ? '1' : '0'),
            $previewUrl,
            1,
        );
        $this->assertNotSame($previewUrl, $tampered, 'The tamper regex must actually change the URL.');

        $tamperedResponse = $this->get($tampered);
        $tamperedResponse->assertJsonPath('mode', 'public');

        // The ORIGINAL, untampered URL must still work -- proving the
        // tampering attempt never burned the real single-use token.
        $realResponse = $this->get($previewUrl);
        $realResponse->assertJsonPath('mode', 'preview');
    }

    public function test_a_viewport_refresh_mints_an_independent_second_token(): void
    {
        $fixture = $this->readyFixture();
        $desktopUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['previewer'], $fixture['instance'], $fixture['layout'], viewport: 'desktop');
        $mobileUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['previewer'], $fixture['instance'], $fixture['layout'], viewport: 'mobile');

        $this->assertNotSame($desktopUrl, $mobileUrl);

        $desktopResponse = $this->get($desktopUrl);
        $desktopResponse->assertJsonPath('viewport', 'desktop');

        $mobileResponse = $this->get($mobileUrl);
        $mobileResponse->assertJsonPath('viewport', 'mobile');
    }

    public function test_a_token_minted_for_one_target_cannot_be_reused_for_a_different_target_route_parameters(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['previewer'], $fixture['instance'], $fixture['layout'], targetId: 'route-target-1');

        $query = [];
        parse_str((string) parse_url($previewUrl, PHP_URL_QUERY), $query);
        $swapped = str_replace('/route-target-1', '/route-target-2', (string) parse_url($previewUrl, PHP_URL_PATH))
            . '?' . http_build_query($query);

        $response = $this->get($swapped);

        // Same reasoning as the tamper test: the path is part of what
        // Laravel's own signature covers, so this is rejected at the root
        // middleware layer (no preview behavior) -- the deeper "signature
        // still valid but bound context differs" case is proven directly
        // against the service in WidgetPreviewSessionTest.
        $response->assertJsonPath('mode', 'public');
    }

    public function test_full_round_trip_never_mutates_public_layout_state(): void
    {
        $fixture = $this->readyFixture();
        $layout = $fixture['layout']->fresh();
        $placementCountBefore = WidgetPlacement::query()->count();
        $versionCountBefore = WidgetLayoutVersion::query()->count();

        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['previewer'], $fixture['instance'], $fixture['layout']);
        $this->get($previewUrl)->assertJsonPath('mode', 'preview');

        $layoutAfter = $fixture['layout']->fresh();
        $this->assertSame($layout->current_draft_version_id, $layoutAfter->current_draft_version_id);
        $this->assertSame($layout->current_published_version_id, $layoutAfter->current_published_version_id);
        $this->assertSame($placementCountBefore, WidgetPlacement::query()->count());
        $this->assertSame($versionCountBefore, WidgetLayoutVersion::query()->count());
    }
}
