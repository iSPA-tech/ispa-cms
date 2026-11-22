<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Preview;

use App\Constants\UserType;
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
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Preview\AtomicWidgetPreviewStore;
use Cms\Widget\Preview\RedisAtomicWidgetPreviewStore;
use Cms\Widget\Preview\WidgetPreviewConsumeResult;
use Cms\Widget\Preview\WidgetPreviewMintResult;
use Cms\Widget\Preview\WidgetPreviewSessionService;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Predis\Client as PredisClient;
use Tests\TestCase;

/**
 * Chunk 2 Task 19: signed full-page preview sessions (plan lines 1082-1124;
 * architecture.md "Signed Full-Page Preview"; docs/architecture/adr-widget-
 * preview-session.md).
 *
 * Exercises {@see WidgetPreviewSessionService} DIRECTLY for the mint/consume
 * algorithmic surface (mirrors this module's established Feature/SiteLayout
 * convention -- {@see \Cms\Widget\Tests\Feature\SiteLayout\WidgetLayoutPublishTest}),
 * plus the HTTP mint-endpoint wiring section at the end. Full round-trip
 * middleware/canonical-route/infrastructure proofs live in the other three
 * Preview test files per the plan's own file split.
 *
 * Runs against the coordinator-provided ephemeral Redis at 127.0.0.1:6399
 * (database 15) -- never the application's `.env` production Redis.
 */
final class WidgetPreviewSessionTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.preview.slot';

    private const TEMPLATE_CONTEXT = 'test.preview.context';

    private const TARGET_TYPE = 'test.preview.target';

    private const ROUTE_NAME = 'test.widget.preview.session-canonical';

    private string $storageRoot;

    /** @var array<string, string|null> class-level snapshot: see WidgetLayoutPublishTest's identical precedent. */
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

        // Dedicated ephemeral Redis connection for the atomic preview store
        // -- set BEFORE the provider boots so its singleton factory closure
        // captures these values, not the (disabled/absent) production
        // `.env` redis config.
        config(['database.redis.widget_preview' => [
            'host' => '127.0.0.1',
            'port' => 6399,
            'database' => 15,
            'timeout' => 2.0,
        ]]);
        (new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 15]))->flushdb();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = storage_path('framework/testing/widget-preview-' . bin2hex(random_bytes(6)));

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
            labelKey: 'test.preview.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

        $templateContexts = $this->app->make(WidgetTemplateContextRegistry::class);
        $templateContexts->registerTemplateContext(self::TEMPLATE_CONTEXT, [self::SLOT]);

        $fakeResolver = new FakeWidgetTargetContextResolver();
        $fakeResolver->withTarget(
            targetId: 'preview-target-1',
            targetType: self::TARGET_TYPE,
            projectName: self::PROJECT,
            label: 'Preview Target One',
            routeName: self::ROUTE_NAME,
            routeParameters: ['slug' => 'preview-target-1'],
        );
        $fakeResolver->withTarget(
            targetId: 'preview-target-denied',
            targetType: self::TARGET_TYPE,
            projectName: self::PROJECT,
            label: 'Denied Target',
            routeName: self::ROUTE_NAME,
            routeParameters: ['slug' => 'preview-target-denied'],
            readable: false,
        );
        $this->app->instance(FakeWidgetTargetContextResolver::class, $fakeResolver);

        $targetContexts = $this->app->make(WidgetTargetContextRegistry::class);
        $targetContexts->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: self::TARGET_TYPE,
            labelKey: 'test.preview.target.label',
            templateContextKey: self::TEMPLATE_CONTEXT,
            resolverService: FakeWidgetTargetContextResolver::class,
        ));

        // The signed preview_url must reference an actually-registered named
        // route (Laravel signed URLs always target a named route) -- a
        // test-only fixture, per DoD 1109, standing in for the real ExampleProject
        // route Task 23 wires later.
        if (!Route::has(self::ROUTE_NAME)) {
            Route::middleware(['web'])
                ->get('/__test/widget-preview-session/{slug}', static fn (string $slug) => response('preview-canonical:' . $slug))
                ->name(self::ROUTE_NAME);
            // Laravel only refreshes RouteCollection's name-lookup index
            // automatically ONCE, in an `app()->booted()` callback fired
            // right after the application's OWN route files finish loading
            // (Illuminate\Foundation\Support\Providers\RouteServiceProvider::register()).
            // A route registered afterward (like this test-only fixture)
            // needs this call explicitly, or `Route::has()`/
            // `URL::temporarySignedRoute()` cannot find it by name.
            app('router')->getRoutes()->refreshNameLookups();
        }

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        // PHPUnit runs under the cli SAPI: WidgetInstanceService's own
        // barrier-gated Version-creating transaction (used only by this
        // test's createReadyInstance() FIXTURE helper, never by
        // WidgetPreviewSessionService itself, which consults no barrier
        // per the coordinator brief) therefore routes through
        // $this->barrier->transition() rather than the live HTTP
        // complementary window -- mirrors WidgetLayoutPublishTest::setUp()'s
        // identical precedent.
        $this->app->singleton(\App\Contracts\Modules\ModuleLifecycleBarrier::class, fn () => new class implements \App\Contracts\Modules\ModuleLifecycleBarrier {
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
    // Fixtures (mirrors WidgetLayoutPublishTest's established shape)
    // -----------------------------------------------------------------

    private function createWidget(): Widget
    {
        return Widget::query()->create(['name' => 'Test Widget', 'description' => '', 'js' => '']);
    }

    private function createTheme(Widget $widget, string $suffix = ''): WidgetTheme
    {
        return WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Test Theme' . $suffix,
            'content' => '<div class="card' . $suffix . '">{title}</div>',
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
            'name' => 'Preview Tester',
            'email' => 'preview-tester-' . uniqid('', true) . '@test.local',
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

    private function previewUser(): User
    {
        return $this->userOfType(UserType::ADMIN, ['widget_layout.preview']);
    }

    /**
     * Fixture-setup actor (materialize/approve/instance-create) -- deliberately
     * DISTINCT from {@see self::previewUser()}, which carries ONLY
     * `widget_layout.preview` so the permission tests exercise exactly the
     * one permission under test.
     */
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

    /**
     * @return array{instance: WidgetInstance, manifestHash: string}
     */
    private function createReadyInstance(Widget $widget, WidgetTheme $theme, User $user): array
    {
        $manifestHash = $this->approveManifestFor($widget, $theme, $user);

        $result = app(WidgetInstanceService::class)->create(
            self::PROJECT,
            (int) $user->id,
            'create-key-' . uniqid('', true),
            [
                'name' => 'Preview Instance',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Original'],
            ],
        );

        return ['instance' => $result->instance, 'manifestHash' => $manifestHash];
    }

    /**
     * A pending (never-approved) Manifest via the REAL materialize endpoint,
     * with a directly-constructed WidgetInstanceVersion pinned to it --
     * Instance-Version creation through the real service ALWAYS requires an
     * approved Manifest (this class's own gateManifests() precedent, mirrored
     * from WidgetLayoutPublishService), so this is the only way to reach "an
     * overlay row pins a still-pending Manifest" at all. Mirrors
     * WidgetLayoutPublishTest::pendingManifestInstanceVersion() exactly.
     */
    private function pendingManifestInstanceVersion(WidgetInstance $instance, int $nextVersion, User $user): WidgetInstanceVersion
    {
        $widget = $this->createWidget();
        // Content must differ from createReadyInstance()'s theme, or
        // materialize resolves the SAME already-approved manifest_hash via
        // its own "200 exact reuse" behavior (fingerprint is content-based,
        // not row-id-based) -- mirrors WidgetLayoutPublishTest's identical
        // precedent.
        $theme = $this->createTheme($widget, '-pending-' . uniqid('', true));

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
        ]);
        $response->assertSuccessful();

        $manifest = WidgetArtifactManifest::query()
            ->where('project_name', self::PROJECT)
            ->where('manifest_hash', $response->json('data.manifest_hash'))
            ->firstOrFail();

        return WidgetInstanceVersion::query()->create([
            'instance_id' => $instance->id,
            'project_name' => self::PROJECT,
            'version' => $nextVersion,
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'renderer_key' => \Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer::RENDERER_KEY,
            'renderer_version' => \Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer::RENDERER_VERSION,
            'trust_classification' => (string) $manifest->trust_lane,
            'schema' => [],
            'schema_hash' => hash('sha256', '[]'),
            'values' => [],
            'provider_refs' => [],
            'artifact_manifest_id' => $manifest->id,
        ]);
    }

    private function createLayout(string $key = 'site'): WidgetLayout
    {
        return WidgetLayout::query()->create(['project_name' => self::PROJECT, 'key' => $key]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function overlayFor(WidgetInstance $instance, int $version = 1, string $targetId = 'preview-target-1'): array
    {
        return [[
            'slot_key' => self::SLOT,
            'scope_type' => 'target',
            'target_type' => self::TARGET_TYPE,
            'target_id' => $targetId,
            'widget_instance_uuid' => $instance->uuid,
            'widget_instance_version' => $version,
            'position' => 0,
            'enabled' => true,
            'rules' => [],
        ]];
    }

    private function service(): WidgetPreviewSessionService
    {
        return app(WidgetPreviewSessionService::class);
    }

    // -----------------------------------------------------------------
    // mint()
    // -----------------------------------------------------------------

    public function test_mint_succeeds_and_returns_a_signed_preview_url_with_expiry_and_fingerprint(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            $this->overlayFor($instance),
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-1',
            'desktop',
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_SUCCESS, $result->status);
        $this->assertNotNull($result->previewUrl);
        $this->assertStringContainsString('signature=', $result->previewUrl);
        $this->assertStringContainsString(PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER . '=', $result->previewUrl);
        $this->assertNotNull($result->expiresAt);
        $this->assertNotEmpty($result->fingerprint);
        $this->assertNotNull($result->context);
        $this->assertSame(self::ROUTE_NAME, $result->context->canonicalRouteName);
        $this->assertSame(['slug' => 'preview-target-1'], $result->context->canonicalRouteParameters);
    }

    public function test_mint_fails_when_layout_does_not_exist(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);

        $result = $this->service()->mint(
            self::PROJECT,
            (string) \Illuminate\Support\Str::uuid(),
            (int) $user->id,
            $this->overlayFor($instance),
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-1',
            null,
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_LAYOUT_NOT_FOUND, $result->status);
        $this->assertNull($result->previewUrl);
    }

    public function test_mint_fails_domain_validation_for_an_unregistered_slot_key(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $overlay = $this->overlayFor($instance);
        $overlay[0]['slot_key'] = 'unknown.slot.key';

        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            $overlay,
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-1',
            null,
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_VALIDATION_FAILED, $result->status);
    }

    /**
     * Proves the overlay contract's "instance_version_references:
     * persisted_only" / "ephemeral_instance_values: forbidden" constraint
     * structurally: a widget_instance_version number that was never
     * actually created has no code path to succeed -- it always fails
     * exactly like Draft Save's own "unknown pairing" rejection (fixture
     * overlay_constraints; Task 18's own disclosed "not testable until Task
     * 19" risk, closed by this test).
     */
    public function test_mint_rejects_an_unsaved_ephemeral_instance_version_reference(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $overlay = $this->overlayFor($instance, version: 999);

        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            $overlay,
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-1',
            null,
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_VALIDATION_FAILED, $result->status);
    }

    public function test_mint_fails_with_target_denied_for_an_unreadable_target(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            $this->overlayFor($instance, targetId: 'preview-target-denied'),
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-denied',
            null,
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_TARGET_DENIED, $result->status);
    }

    public function test_mint_fails_with_target_not_found_for_an_unknown_target_id(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            $this->overlayFor($instance, targetId: 'does-not-exist'),
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'does-not-exist',
            null,
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_TARGET_NOT_FOUND, $result->status);
    }

    public function test_mint_approves_a_still_pending_referenced_manifest_automatically(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $pendingVersion = $this->pendingManifestInstanceVersion($instance, 2, $admin);

        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            $this->overlayFor($instance, version: $pendingVersion->version),
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-1',
            null,
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_SUCCESS, $result->status);
        $this->assertSame(
            WidgetManifestApprovalService::STATE_APPROVED,
            WidgetArtifactManifest::query()->findOrFail($pendingVersion->artifact_manifest_id)->eligibility_state,
        );
    }

    public function test_mint_without_an_acting_user_fails_when_the_referenced_manifest_is_revoked(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance, 'manifestHash' => $manifestHash] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        app(WidgetManifestApprovalService::class)->revoke(self::PROJECT, $manifestHash, 2, (int) $user->id, 'test revoke');

        // No acting user: nothing is re-approved on anyone's behalf, so the gate still refuses.
        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            null,
            $this->overlayFor($instance),
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-1',
            null,
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_MANIFEST_REVOKED, $result->status);
    }

    public function test_mint_never_mutates_any_layout_draft_or_placement_row(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $layoutBefore = $layout->fresh();

        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            $this->overlayFor($instance),
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-1',
            null,
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_SUCCESS, $result->status);

        $layoutAfter = $layout->fresh();
        $this->assertSame($layoutBefore->current_draft_version_id, $layoutAfter->current_draft_version_id);
        $this->assertSame($layoutBefore->current_published_version_id, $layoutAfter->current_published_version_id);
        $this->assertSame(0, \Cms\Widget\Models\WidgetPlacement::query()->count());
        $this->assertSame(0, \Cms\Widget\Models\WidgetLayoutVersion::query()->count());
    }

    // -----------------------------------------------------------------
    // consume()
    // -----------------------------------------------------------------

    private function mintSuccessfully(User $user, WidgetInstance $instance, WidgetLayout $layout): WidgetPreviewMintResult
    {
        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            $this->overlayFor($instance),
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-1',
            null,
        );
        $this->assertSame(WidgetPreviewMintResult::STATUS_SUCCESS, $result->status);

        return $result;
    }

    private function tokenFromPreviewUrl(string $previewUrl): string
    {
        $query = [];
        parse_str((string) parse_url($previewUrl, PHP_URL_QUERY), $query);

        return (string) $query[PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER];
    }

    public function test_consume_round_trip_returns_the_bound_overlay_and_context(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $mint = $this->mintSuccessfully($user, $instance, $layout);
        $token = $this->tokenFromPreviewUrl((string) $mint->previewUrl);

        $consumeResult = $this->service()->consume($token, self::PROJECT, self::ROUTE_NAME, ['slug' => 'preview-target-1']);

        $this->assertTrue($consumeResult->isSuccess());
        $this->assertSame(self::PROJECT, $consumeResult->context->projectName);
        $this->assertSame($layout->uuid, $consumeResult->context->layoutUuid);
        $this->assertCount(1, $consumeResult->context->overlayPlacements);
        $this->assertSame(self::SLOT, $consumeResult->context->overlayPlacements[0]['slot_key']);
    }

    public function test_consume_fails_on_replay_after_the_token_was_already_consumed(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $mint = $this->mintSuccessfully($user, $instance, $layout);
        $token = $this->tokenFromPreviewUrl((string) $mint->previewUrl);

        $first = $this->service()->consume($token, self::PROJECT, self::ROUTE_NAME, ['slug' => 'preview-target-1']);
        $second = $this->service()->consume($token, self::PROJECT, self::ROUTE_NAME, ['slug' => 'preview-target-1']);

        $this->assertTrue($first->isSuccess());
        $this->assertSame(WidgetPreviewConsumeResult::STATUS_INVALID_OR_EXPIRED, $second->status);
    }

    public function test_consume_fails_for_an_unknown_token(): void
    {
        $result = $this->service()->consume('does-not-exist', self::PROJECT, self::ROUTE_NAME, ['slug' => 'preview-target-1']);

        $this->assertSame(WidgetPreviewConsumeResult::STATUS_INVALID_OR_EXPIRED, $result->status);
    }

    public function test_consume_fails_when_the_current_route_name_does_not_match_the_bound_canonical_route(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $mint = $this->mintSuccessfully($user, $instance, $layout);
        $token = $this->tokenFromPreviewUrl((string) $mint->previewUrl);

        $result = $this->service()->consume($token, self::PROJECT, 'some.other.route', ['slug' => 'preview-target-1']);

        $this->assertSame(WidgetPreviewConsumeResult::STATUS_INVALID_OR_EXPIRED, $result->status);
    }

    public function test_consume_fails_when_the_current_route_parameters_do_not_match_the_bound_target(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $mint = $this->mintSuccessfully($user, $instance, $layout);
        $token = $this->tokenFromPreviewUrl((string) $mint->previewUrl);

        $result = $this->service()->consume($token, self::PROJECT, self::ROUTE_NAME, ['slug' => 'a-different-target']);

        $this->assertSame(WidgetPreviewConsumeResult::STATUS_INVALID_OR_EXPIRED, $result->status);
    }

    public function test_consume_fails_for_a_different_project_than_the_one_the_token_was_minted_for(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $mint = $this->mintSuccessfully($user, $instance, $layout);
        $token = $this->tokenFromPreviewUrl((string) $mint->previewUrl);

        $result = $this->service()->consume($token, 'SomeOtherProject', self::ROUTE_NAME, ['slug' => 'preview-target-1']);

        $this->assertSame(WidgetPreviewConsumeResult::STATUS_INVALID_OR_EXPIRED, $result->status);
    }

    /**
     * DoD 1106: "At token mint and again after atomic consume, require
     * every referenced exact Manifest to remain approved, non-revoked...
     * Test ... revocation between mint and consume; failure renders
     * nothing and mutates no pointer."
     */
    public function test_consume_fails_when_the_manifest_is_revoked_between_mint_and_consume(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance, 'manifestHash' => $manifestHash] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $mint = $this->mintSuccessfully($user, $instance, $layout);
        $token = $this->tokenFromPreviewUrl((string) $mint->previewUrl);

        app(WidgetManifestApprovalService::class)->revoke(self::PROJECT, $manifestHash, 2, (int) $user->id, 'revoked between mint and consume');

        $layoutBefore = $layout->fresh();
        $manifestRowCountBefore = WidgetArtifactManifest::query()->count();

        $result = $this->service()->consume($token, self::PROJECT, self::ROUTE_NAME, ['slug' => 'preview-target-1']);

        $this->assertSame(WidgetPreviewConsumeResult::STATUS_MANIFEST_REVOKED, $result->status);

        $layoutAfter = $layout->fresh();
        $this->assertSame($layoutBefore->current_draft_version_id, $layoutAfter->current_draft_version_id);
        $this->assertSame($layoutBefore->current_published_version_id, $layoutAfter->current_published_version_id);
        $this->assertSame($manifestRowCountBefore, WidgetArtifactManifest::query()->count());
        $this->assertSame(0, \Cms\Widget\Models\WidgetPlacement::query()->count());
    }

    // -----------------------------------------------------------------
    // HTTP mint endpoint wiring
    // -----------------------------------------------------------------

    public function test_http_mint_endpoint_returns_201_with_preview_url_for_an_authorized_user(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/preview-sessions", [
                'overlay' => $this->overlayFor($instance),
                'locale' => 'vi',
                'template_context' => self::TEMPLATE_CONTEXT,
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'preview-target-1',
                'viewport' => 'desktop',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['preview_url', 'expires_at', 'fingerprint', 'diagnostics']);
    }

    public function test_http_mint_endpoint_denies_a_user_without_the_preview_permission(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->userOfType(UserType::ADMIN);
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/preview-sessions", [
                'overlay' => $this->overlayFor($instance),
                'locale' => 'vi',
                'template_context' => self::TEMPLATE_CONTEXT,
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'preview-target-1',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_http_mint_endpoint_returns_404_for_an_unknown_layout(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v2/admin/widget/layouts/' . \Illuminate\Support\Str::uuid() . '/preview-sessions', [
                'overlay' => $this->overlayFor($instance),
                'locale' => 'vi',
                'template_context' => self::TEMPLATE_CONTEXT,
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'preview-target-1',
            ]);

        $response->assertStatus(404);
        $response->assertJsonMissingPath('code');
    }

    public function test_http_mint_endpoint_returns_422_for_a_malformed_request_shape(): void
    {
        $user = $this->previewUser();
        $layout = $this->createLayout();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/preview-sessions", [
                'locale' => 'vi',
            ]);

        $response->assertStatus(422);
    }

    /**
     * Coordinator conformance rule (supersedes the earlier Task-20 precedent):
     * the frozen fixture declares this route's statuses as [201,403,404,422]
     * ONLY. Every manifest-ineligibility branch at mint (runtime-blocked; and
     * revoked/pending when no user acts -- with a user they are approved
     * automatically) maps to 422 WIDGET_MANIFEST_APPROVAL_REQUIRED -- the
     * enum's 422 "manifest not in an approved/eligible state" code -- never a
     * 409. The precise sub-reason rides in the envelope message.
     */
    public function test_http_mint_endpoint_re_approves_a_revoked_manifest_automatically(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance, 'manifestHash' => $manifestHash] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        app(WidgetManifestApprovalService::class)->revoke(self::PROJECT, $manifestHash, 2, (int) $admin->id, 'test revoke');

        // Unified Widget Catalog Part 2: approval is automatic; only the emergency block stops a widget.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/preview-sessions", [
                'overlay' => $this->overlayFor($instance),
                'locale' => 'vi',
                'template_context' => self::TEMPLATE_CONTEXT,
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'preview-target-1',
            ]);

        $response->assertStatus(201);
        $this->assertSame(
            WidgetManifestApprovalService::STATE_APPROVED,
            WidgetArtifactManifest::query()->where('project_name', self::PROJECT)->where('manifest_hash', $manifestHash)->value('eligibility_state'),
        );
    }

    public function test_mint_succeeds_when_a_pinned_widget_is_blocked_in_the_catalog(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        // Core emergency block: the widget renders nothing, but the preview of the rest still mints.
        $widget->forceFill(['blocked_at' => now(), 'blocked_reason' => 'test block'])->save();

        $result = $this->service()->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            $this->overlayFor($instance),
            'vi',
            self::TEMPLATE_CONTEXT,
            self::TARGET_TYPE,
            'preview-target-1',
            null,
        );

        $this->assertSame(WidgetPreviewMintResult::STATUS_SUCCESS, $result->status);
    }

    public function test_http_mint_endpoint_returns_422_approval_required_for_a_blocked_manifest(): void
    {
        $admin = $this->fullAccessUser();
        $user = $this->previewUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        ['instance' => $instance, 'manifestHash' => $manifestHash] = $this->createReadyInstance($widget, $theme, $admin);
        $layout = $this->createLayout();

        // Approved AND emergency-blocked: the gate checks revoked -> not-
        // approved -> blocked in order, so this row must stay `approved`
        // with only the `blocked` column flipped to reach the blocked branch.
        WidgetArtifactManifest::query()
            ->where('project_name', self::PROJECT)
            ->where('manifest_hash', $manifestHash)
            ->update(['blocked' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v2/admin/widget/layouts/{$layout->uuid}/preview-sessions", [
                'overlay' => $this->overlayFor($instance),
                'locale' => 'vi',
                'template_context' => self::TEMPLATE_CONTEXT,
                'target_type' => self::TARGET_TYPE,
                'target_id' => 'preview-target-1',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'WIDGET_MANIFEST_APPROVAL_REQUIRED');
    }
}
