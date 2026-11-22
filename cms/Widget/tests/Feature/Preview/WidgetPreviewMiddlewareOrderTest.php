<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Preview;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Http\Middleware\PrepareAnonymousWidgetPreviewRequest;
use App\Http\Middleware\TrackOnlineUsers;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Http\Middleware\ResolveWidgetPreviewSession;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetLayout;
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
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Predis\Client as PredisClient;
use Tests\TestCase;

/**
 * Chunk 2 Task 19: middleware-ordering and credential-isolation proofs (plan
 * lines 1082-1124 DoD 1107, 1108, 1112, 1113; architecture.md "Public
 * preview request": "Middleware-order tests install probe session/auth/
 * personalization middleware and prove supplied admin/public credentials
 * are absent before any probe runs; asserting only Auth::user() === null at
 * response time is insufficient.").
 *
 * Rebuilds the REAL `web` middleware group (via `Router::middlewareGroup()`,
 * which fully replaces the group definition) with three probe middleware
 * classes (declared at the bottom of this file) interleaved at the exact
 * boundaries DoD 1112 names: after session start, after auth-resolution
 * position (SubstituteBindings, immediately before where `Authenticate`
 * would run on a route that opted into it), and immediately before
 * {@see ResolveWidgetPreviewSession} (the personalization/experiments
 * boundary). Every probe records what it observed into a shared
 * {@see WidgetPreviewProbeLog} singleton; the test then asserts EVERY
 * recorded boundary saw no Cookie/Authorization data, not just the final
 * response state.
 */
final class WidgetPreviewMiddlewareOrderTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.order.slot';

    private const TEMPLATE_CONTEXT = 'test.order.context';

    private const TARGET_TYPE = 'test.order.target';

    private const ROUTE_NAME = 'test.widget.preview.order-canonical';

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

        $this->storageRoot = storage_path('framework/testing/widget-preview-order-' . bin2hex(random_bytes(6)));

        $this->app->singleton(WidgetPreviewProbeLog::class);

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        // Rebuild the REAL `web` group with probes interleaved at the
        // named boundaries -- see class docblock. Done AFTER
        // WidgetServiceProvider::boot() so ResolveWidgetPreviewSession
        // (already pushed to the end by boot()) is preserved as the LAST
        // entry by this explicit full replacement.
        $this->app->make(Router::class)->middlewareGroup('web', [
            EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            WidgetPreviewSessionBoundaryProbe::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            WidgetPreviewAuthBoundaryProbe::class,
            Localization::class,
            TrackOnlineUsers::class,
            WidgetPreviewPersonalizationBoundaryProbe::class,
            ResolveWidgetPreviewSession::class,
        ]);

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $slots = $this->app->make(WidgetSlotRegistry::class);
        $slots->register(new WidgetSlotDeclaration(
            key: self::SLOT,
            labelKey: 'test.order.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        $templateContexts = $this->app->make(WidgetTemplateContextRegistry::class);
        $templateContexts->registerTemplateContext(self::TEMPLATE_CONTEXT, [self::SLOT]);

        $fakeResolver = new FakeWidgetTargetContextResolver();
        $fakeResolver->withTarget(
            targetId: 'order-target-1',
            targetType: self::TARGET_TYPE,
            projectName: self::PROJECT,
            label: 'Order Target One',
            routeName: self::ROUTE_NAME,
            routeParameters: ['slug' => 'order-target-1'],
        );
        $this->app->instance(FakeWidgetTargetContextResolver::class, $fakeResolver);

        $targetContexts = $this->app->make(WidgetTargetContextRegistry::class);
        $targetContexts->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: self::TARGET_TYPE,
            labelKey: 'test.order.target.label',
            templateContextKey: self::TEMPLATE_CONTEXT,
            resolverService: FakeWidgetTargetContextResolver::class,
        ));

        if (!Route::has(self::ROUTE_NAME)) {
            Route::middleware(['web'])
                ->get('/__test/widget-preview-order/{slug}', static function (string $slug) {
                    if (request()->boolean('__throw')) {
                        throw new \RuntimeException('simulated downstream failure for cleanup-after-exception proof');
                    }

                    return response()->json([
                        'mode' => app()->bound(WidgetPreviewContext::class) ? 'preview' : 'public',
                        'slug' => $slug,
                        'bound_during_request' => app()->bound(WidgetPreviewContext::class),
                    ]);
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
            'name' => 'Order Tester',
            'email' => 'order-tester-' . uniqid('', true) . '@test.local',
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

    private function createReadyInstance(Widget $widget, WidgetTheme $theme, User $user): WidgetInstance
    {
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
            ]);
        $response->assertSuccessful();
        $manifestHash = (string) $response->json('data.manifest_hash');
        app(WidgetManifestApprovalService::class)->approve(self::PROJECT, $manifestHash, 1, (int) $user->id, 'test approval');

        $result = app(WidgetInstanceService::class)->create(
            self::PROJECT,
            (int) $user->id,
            'create-key-' . uniqid('', true),
            [
                'name' => 'Order Instance',
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

    private function mintPreviewUrl(User $mintingUser, WidgetInstance $instance, WidgetLayout $layout): string
    {
        $response = $this->actingAs($mintingUser, 'sanctum')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/preview-sessions", [
                'overlay' => [[
                    'slot_key' => self::SLOT,
                    'scope_type' => 'target',
                    'target_type' => self::TARGET_TYPE,
                    'target_id' => 'order-target-1',
                    'widget_instance_uuid' => $instance->uuid,
                    'widget_instance_version' => 1,
                    'position' => 0,
                    'enabled' => true,
                    'rules' => [],
                ]],
                'locale' => 'vi',
                'template_context' => self::TEMPLATE_CONTEXT,
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'order-target-1',
                'viewport' => 'desktop',
            ]);
        $response->assertStatus(201);

        return (string) $response->json('preview_url');
    }

    /**
     * @return array{admin: User, instance: WidgetInstance, layout: WidgetLayout}
     */
    private function readyFixture(): array
    {
        $admin = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        return ['admin' => $admin, 'instance' => $instance, 'layout' => $layout];
    }

    // -----------------------------------------------------------------
    // DoD 1112: credentials absent at EVERY probed boundary, not just the
    // final response.
    // -----------------------------------------------------------------

    public function test_supplied_admin_cookie_and_authorization_are_absent_at_every_pipeline_boundary(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['instance'], $fixture['layout']);

        // Simulate a browser tab that ALSO carries a real admin session
        // cookie and bearer token (e.g. the admin previewing from the same
        // browser that manages the CMS) -- these must never reach the
        // public route/controller for a preview request.
        $adminToken = $fixture['admin']->createToken('leftover-admin-token')->plainTextToken;

        /** @var WidgetPreviewProbeLog $log */
        $log = $this->app->make(WidgetPreviewProbeLog::class);
        $log->reset();

        // Test-harness-only reset: fixture setup above called actingAs()
        // (materialize/mint), which sets the resolved user DIRECTLY on the
        // 'sanctum' guard OBJECT -- a singleton this SAME $this->app
        // instance reuses across every HTTP call in this one test method.
        // A real production request never has this residue (php-fpm is
        // request-per-process, a fresh Application+Guard every time); this
        // call removes the test-only artifact so the assertions below
        // prove what the MIDDLEWARE actually does with a fresh request's
        // OWN Cookie/Authorization headers, not leftover harness state.
        Auth::forgetGuards();

        $response = $this
            ->withCookie('ispa_admin_session', 'fake-leftover-session-value')
            ->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->get($previewUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('mode', 'preview');

        $entries = $log->all();
        $this->assertSame(
            ['after_session', 'after_auth_position', 'before_resolve_preview_session'],
            array_keys($entries),
            'All three probe boundaries must have run, in order, for a successful preview request.'
        );

        foreach ($entries as $boundary => $observation) {
            $this->assertFalse($observation['has_cookie_header'], "Cookie header must be absent at boundary '{$boundary}'.");
            $this->assertSame(0, $observation['cookie_bag_count'], "Parsed cookie bag must be empty at boundary '{$boundary}'.");
            $this->assertFalse($observation['has_authorization_header'], "Authorization header must be absent at boundary '{$boundary}'.");
            $this->assertFalse($observation['auth_check'], "Auth::check() must be false at boundary '{$boundary}'.");
        }

        // Also true at response time -- but per architecture.md this alone
        // is explicitly NOT sufficient acceptance; the per-boundary proof
        // above is the actual requirement.
        $this->assertFalse(Auth::check());
    }

    public function test_an_invalid_candidate_request_is_completely_unaffected_leftover_credentials_reach_the_public_route(): void
    {
        // DoD 1107: "Invalid candidates do not receive preview behavior."
        // A request with NO preview marker at all must behave like any
        // ordinary public request -- including carrying real credentials
        // through untouched (this route has no auth requirement, so this
        // just proves the root middleware did not strip anything it had no
        // business touching).
        $user = $this->fullAccessUser();

        $response = $this
            ->withCookie('some_cookie', 'value')
            ->actingAs($user, 'sanctum')
            ->get('/__test/widget-preview-order/order-target-1');

        $response->assertStatus(200);
        $response->assertJsonPath('mode', 'public');
    }

    // -----------------------------------------------------------------
    // DoD 1108: cleanup in `finally` on both success and exception paths;
    // no leakage across a simulated long-lived worker.
    // -----------------------------------------------------------------

    public function test_the_preview_context_binding_is_cleared_after_the_request_completes_successfully(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['instance'], $fixture['layout']);

        $this->get($previewUrl)->assertJsonPath('mode', 'preview');

        $this->assertFalse(
            $this->app->bound(WidgetPreviewContext::class),
            'WidgetPreviewContext must be removed from the container after the request completes.'
        );
    }

    public function test_the_preview_context_binding_is_cleared_even_when_the_downstream_route_throws(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['instance'], $fixture['layout']);

        $query = [];
        parse_str((string) parse_url($previewUrl, PHP_URL_QUERY), $query);
        $token = (string) $query[PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER];

        $throwingUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            self::ROUTE_NAME,
            now()->addMinutes(5),
            ['slug' => 'order-target-1', PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER => $token, '__throw' => '1'],
        );

        $response = $this->get($throwingUrl);
        $response->assertStatus(500);

        $this->assertFalse(
            $this->app->bound(WidgetPreviewContext::class),
            'WidgetPreviewContext must be removed from the container even when the downstream handler threw.'
        );
    }

    /**
     * The closest feasible proxy for "no Octane worker leakage" inside a
     * single PHPUnit process (production is proven php-fpm/request-per-
     * process, per Task 5a evidence -- this proves the CODE PATH is
     * correct regardless of worker model, not that a real Octane worker
     * was exercised): run two SEQUENTIAL requests reusing the SAME
     * container/application instance without a fresh boot between them,
     * and prove the second (ordinary, tokenless) request never observes
     * the first request's preview overlay.
     */
    public function test_no_preview_context_leaks_into_a_second_request_reusing_the_same_application_instance(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['instance'], $fixture['layout']);

        $first = $this->get($previewUrl);
        $first->assertJsonPath('mode', 'preview');

        $second = $this->get('/__test/widget-preview-order/order-target-1');
        $second->assertJsonPath('mode', 'public');
        $second->assertJsonPath('bound_during_request', false);
    }

    // -----------------------------------------------------------------
    // DoD 1113: admin mint on node A / public consume on node B; cross-
    // project use, at the full HTTP level (complements the raw-Redis-level
    // proofs in WidgetPreviewInfrastructureContractTest).
    // -----------------------------------------------------------------

    public function test_admin_mints_authenticated_and_a_separate_unauthenticated_request_consumes_successfully(): void
    {
        $fixture = $this->readyFixture();

        // "Node A": admin mint, fully authenticated HTTP call.
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['instance'], $fixture['layout']);

        // "Node B": a BRAND NEW, completely unauthenticated test HTTP call
        // -- no actingAs(), no cookie, no token. This is the "public node"
        // consuming a token an "admin node" minted.
        $response = $this->get($previewUrl);

        $response->assertStatus(200);
        $response->assertJsonPath('mode', 'preview');
    }

    public function test_a_token_cannot_be_consumed_after_the_server_derived_project_changes(): void
    {
        $fixture = $this->readyFixture();
        $previewUrl = $this->mintPreviewUrl($fixture['admin'], $fixture['instance'], $fixture['layout']);

        config(['ispa-cms.folder_name' => 'SomeOtherProject']);

        $response = $this->get($previewUrl);

        $response->assertJsonPath('code', 'WIDGET_PREVIEW_SESSION_EXPIRED');
    }
}

/**
 * Shared recorder for the three probe middleware classes below. Co-located
 * in this test file (no production file slot for a test-only fixture);
 * bound as a container singleton in setUp() so every probe writes to the
 * SAME instance for the duration of one request.
 */
final class WidgetPreviewProbeLog
{
    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    public function record(string $boundary, \Illuminate\Http\Request $request): void
    {
        $this->entries[$boundary] = [
            'has_cookie_header' => $request->headers->has('Cookie'),
            'cookie_bag_count' => count($request->cookies->all()),
            'has_authorization_header' => $request->headers->has('Authorization'),
            'auth_check' => Auth::check(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->entries;
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}

/**
 * Probe positioned immediately after `StartSession` -- the earliest point
 * a leaked admin session could theoretically surface.
 */
final class WidgetPreviewSessionBoundaryProbe
{
    public function __construct(private readonly WidgetPreviewProbeLog $log)
    {
    }

    public function handle(\Illuminate\Http\Request $request, \Closure $next): mixed
    {
        $this->log->record('after_session', $request);

        return $next($request);
    }
}

/**
 * Probe positioned right after `SubstituteBindings` -- the position where
 * a route requiring `auth`/`auth:sanctum` middleware would resolve the
 * authenticated user, simulating the "auth resolution" boundary DoD 1112
 * names.
 */
final class WidgetPreviewAuthBoundaryProbe
{
    public function __construct(private readonly WidgetPreviewProbeLog $log)
    {
    }

    public function handle(\Illuminate\Http\Request $request, \Closure $next): mixed
    {
        $this->log->record('after_auth_position', $request);

        return $next($request);
    }
}

/**
 * Probe positioned immediately before {@see ResolveWidgetPreviewSession} --
 * simulating a personalization/experiments middleware boundary, the LAST
 * point before the module consumes the token and installs the overlay.
 */
final class WidgetPreviewPersonalizationBoundaryProbe
{
    public function __construct(private readonly WidgetPreviewProbeLog $log)
    {
    }

    public function handle(\Illuminate\Http\Request $request, \Closure $next): mixed
    {
        $this->log->record('before_resolve_preview_session', $request);

        return $next($request);
    }
}
