<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\SiteLayout;

use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\WidgetSlotRuntime;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Http\Middleware\PrepareAnonymousWidgetPreviewRequest;
use App\Models\User;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Events\WidgetLayoutPublished;
use Cms\Widget\Events\WidgetLayoutRolledBack;
use Cms\Widget\Instances\WidgetInstanceService;
use Cms\Widget\Listeners\InvalidateWidgetPlacementCache;
use Cms\Widget\Listeners\RecordWidgetLayoutTelemetry;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Observability\WidgetRuntimeTelemetry;
use Cms\Widget\Preview\AtomicWidgetPreviewStore;
use Cms\Widget\Preview\RedisAtomicWidgetPreviewStore;
use Cms\Widget\Preview\WidgetPreviewSessionService;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\WidgetRenderService;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishService;
use Cms\Widget\SiteLayout\Publishing\WidgetLayoutRollbackService;
use Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\WidgetSlotRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Predis\Client as PredisClient;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Chunk 2 Task 24: placement cache, telemetry, and integrity diagnostics
 * (plan lines 1254-1291, DoD 1277-1285). Proves the FULL cross-cutting
 * contract in one place, mirroring this module's established
 * one-integration-file-per-task convention (Tasks 19/20/21's own single
 * Feature test files).
 *
 * Runs against the coordinator-provided ephemeral Redis at 127.0.0.1:6399
 * (database 13 for the placement cache, matching WidgetPlacementCacheTest;
 * database 15 for the preview store, matching WidgetPreviewSessionTest) --
 * never the application's `.env` production Redis (REDIS_ENABLE=false).
 */
final class WidgetRuntimeCacheTelemetryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private const SLOT = 'test.integration.slot';

    private string $storageRoot;

    /**
     * Isolated storage root for GENERATED WIDGET TEMPLATES specifically
     * (distinct from $storageRoot, which binds FilesystemWidgetArtifactBlobStore
     * -- a separate storage concern). Mirrors
     * WidgetManifestRuntimeBlockRenderTest::setUp()'s identical isolation:
     * this suite is the first in this task to exercise REAL rendering
     * (LegacyWidgetRenderer -> WidgetQueryService::preRender() resolves a
     * generated `storage/app/generated_widgets/...` file), which
     * WidgetLayoutPublishTest's own fixtures never needed.
     */
    private string $isolatedStoragePath;

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

        // Dedicated ephemeral Redis connections for both stores this suite
        // touches -- set BEFORE the provider boots, same precedent
        // WidgetPreviewSessionTest::setUp() established.
        config(['database.redis.widget_placement_cache' => [
            'host' => '127.0.0.1',
            'port' => 6399,
            'database' => 13,
            'timeout' => 2.0,
        ]]);
        config(['database.redis.widget_preview' => [
            'host' => '127.0.0.1',
            'port' => 6399,
            'database' => 15,
            'timeout' => 2.0,
        ]]);
        (new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 13]))->flushdb();
        (new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 15]))->flushdb();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-runtime-cache-telemetry-' . uniqid('', true);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($this->isolatedStoragePath . '/app/generated_widgets/default');
        $this->app->useStoragePath($this->isolatedStoragePath);

        $this->storageRoot = storage_path('framework/testing/widget-runtime-cache-telemetry-' . bin2hex(random_bytes(6)));

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
            labelKey: 'test.integration.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
        ));

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

        if (isset($this->isolatedStoragePath)) {
            \Illuminate\Support\Facades\File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    private function writeGeneratedTemplate(int $widgetId, int $themeId, string $content): void
    {
        $path = storage_path("app/generated_widgets/default/widget_{$widgetId}_theme_{$themeId}.blade.php");
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($path));
        \Illuminate\Support\Facades\File::put($path, $content);
    }

    private function placementCache(): WidgetPlacementCache
    {
        return new WidgetPlacementCache(new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 13]));
    }

    private function simulateNewIncomingRequest(): void
    {
        $this->app->instance('request', Request::create('/'));
    }

    // -----------------------------------------------------------------
    // Fixtures (mirrors WidgetLayoutPublishTest's own established helpers).
    // -----------------------------------------------------------------

    private function createWidget(string $suffix = ''): Widget
    {
        $widget = Widget::query()->create(['name' => 'Integration Widget' . $suffix, 'description' => '', 'js' => '']);

        // Declares 'title' as a valid input so LegacyWidgetRenderer's
        // canonical-schema/sanitization pipeline actually substitutes the
        // {title} placeholder from an Instance Version's `values` --
        // mirrors WidgetManifestRuntimeBlockRenderTest::widgetAndTheme()'s
        // own established fixture precedent.
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);

        return $widget;
    }

    private function createTheme(Widget $widget, string $suffix = ''): WidgetTheme
    {
        $content = '<div class="integration-card' . $suffix . '">{title}</div>';

        $theme = WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Integration Theme' . $suffix,
            'content' => $content,
            'css' => '',
            'project_name' => [self::PROJECT],
        ]);

        // LegacyWidgetRenderer resolves a GENERATED template file
        // (WidgetTemplatePathResolver's first candidate); this suite is
        // the first in Task 24 to exercise REAL rendering end to end (not
        // just publish/rollback transaction mechanics), so fixtures must
        // provide one, mirroring WidgetManifestRuntimeBlockRenderTest's
        // own established helper.
        $this->writeGeneratedTemplate($widget->id, $theme->id, $content);

        return $theme;
    }

    private function liveSourceFingerprint(Widget $widget, WidgetTheme $theme): string
    {
        $fingerprint = (new LegacyWidgetExecutableSurfaceAnalyzer())->analyze(
            widgetJs: (string) $widget->js,
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: app(\App\Contracts\Widget\CanonicalWidgetSchemaResolver::class)->resolve($widget->id, $theme->id),
            policyVersion: \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: [self::PROJECT],
            artifactBytes: (new \Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher())->renderThemeContent($theme),
        );

        return $fingerprint->manifestHash;
    }

    private function userOfType(array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Integration Tester',
            'email' => 'integration-tester-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
            'type' => \App\Constants\UserType::ADMIN,
        ]);

        if ($permissionKeys === []) {
            return $user;
        }

        (new WidgetPermissionTableSeeder())->run();

        $role = \Cms\Core\Models\Role::query()->create(['name' => 'Integration Role ' . uniqid('', true), 'custom' => ['level' => 50]]);
        foreach ($permissionKeys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permission = \Cms\Core\Models\Permission::query()->where('group', $group)->where('name', $name)->firstOrFail();
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function fullAccessUser(): User
    {
        return $this->userOfType([
            'widget_instance.create',
            'widget_layout.update',
            'widget_layout.publish',
            'widget_layout.rollback',
            'widget_layout.preview',
            'widget_template.approve',
            'widget_template.emergencyDisable',
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

        app(WidgetManifestApprovalService::class)->approve(self::PROJECT, $manifestHash, 1, (int) $approver->id, 'integration test approval');

        return $manifestHash;
    }

    private function createReadyInstance(Widget $widget, WidgetTheme $theme, User $user, string $name = 'Integration Instance'): WidgetInstance
    {
        $this->approveManifestFor($widget, $theme, $user);

        $result = app(WidgetInstanceService::class)->create(
            self::PROJECT,
            (int) $user->id,
            'create-key-' . uniqid('', true),
            [
                'name' => $name,
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Integration Content'],
            ],
        );

        return $result->instance;
    }

    /**
     * @return array{layout: WidgetLayout, draft: WidgetLayoutVersion}
     */
    private function createLayoutWithDraft(string $key = 'site'): array
    {
        $layout = WidgetLayout::query()->create(['project_name' => self::PROJECT, 'key' => $key]);
        $draft = WidgetLayoutVersion::query()->create([
            'layout_id' => $layout->id,
            'project_name' => self::PROJECT,
            'version' => 1,
            'state' => WidgetLayoutVersion::STATE_DRAFT,
            'lock_version' => 0,
            'registry_contracts' => $this->realRegistryContracts(),
            'registry_contract_hash' => hash('sha256', json_encode($this->realRegistryContracts())),
        ]);
        $layout->current_draft_version_id = $draft->id;
        $layout->save();

        return ['layout' => $layout->fresh(), 'draft' => $draft->fresh()];
    }

    private function createPlacement(WidgetLayoutVersion $version, WidgetInstanceVersion $instanceVersion, string $slotKey = self::SLOT, int $position = 0): WidgetPlacement
    {
        return WidgetPlacement::query()->create([
            'project_name' => self::PROJECT,
            'layout_version_id' => $version->id,
            'widget_instance_version_id' => $instanceVersion->id,
            'slot_key' => $slotKey,
            'scope_type' => 'global',
            'scope_identity' => 'global',
            'position' => $position,
            'enabled' => true,
        ]);
    }

    private function realRegistryContracts(): array
    {
        $declaration = app(WidgetSlotRegistry::class)->declarationFor(self::SLOT);

        return ['slots' => [self::SLOT => $declaration->semanticFields()], 'template_contexts' => [], 'target_contexts' => [], 'providers' => []];
    }

    /**
     * Publishes the given Layout's current Draft, returning the new Release.
     */
    private function publish(WidgetLayout $layout, WidgetLayoutVersion $draft, User $actor): WidgetLayoutVersion
    {
        $result = app(WidgetLayoutPublishService::class)->publish(
            self::PROJECT,
            $layout->uuid,
            (int) $actor->id,
            'publish-key-' . uniqid('', true),
            $draft->version,
            $draft->lock_version,
        );

        $this->assertSame('published', $result->status, 'Fixture publish must succeed.');

        return $result->release;
    }

    // ===================================================================
    // DoD 1285: a real Slot resolution reads/writes metadata cache.
    // ===================================================================

    public function test_a_real_slot_resolution_writes_then_reads_the_placement_metadata_cache(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $instanceVersion = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->firstOrFail();

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instanceVersion);
        $release = $this->publish($layout, $draft, $user);

        $context = new WidgetRenderContext(projectName: self::PROJECT);
        $runtime = app(WidgetSlotRuntime::class);
        $this->assertInstanceOf(WidgetSlotRuntimeService::class, $runtime, 'Widget module must rebind the real Slot runtime.');

        $cacheKey = WidgetPlacementCache::buildKey(self::PROJECT, app()->getLocale(), self::SLOT, null, null, null, $release->version);
        $this->assertNull($this->placementCache()->get($cacheKey), 'Nothing cached before the first resolution.');

        $first = $runtime->render(self::SLOT, $context);

        $this->assertTrue($first->ok, 'A real published Placement must resolve and render.');
        $this->assertStringContainsString('Integration Content', $first->html);

        $cached = $this->placementCache()->get($cacheKey);
        $this->assertNotNull($cached, 'The FIRST resolution must have written the metadata cache.');
        $this->assertSame($instanceVersion->id, $cached[0]['widget_instance_version_id']);
        $this->assertArrayNotHasKey('html', $cached[0], 'Cache entries must never carry rendered HTML.');

        // Second call must be a cache HIT: no fresh widget_placements query.
        DB::enableQueryLog();
        $second = $runtime->render(self::SLOT, $context);
        $placementQueries = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'widget_placements'));
        DB::disableQueryLog();

        $this->assertTrue($second->ok);
        $this->assertSame($first->html, $second->html);
        $this->assertCount(0, $placementQueries, 'A cache hit must not re-query widget_placements.');
    }

    public function test_no_release_yet_falls_through_unchanged_to_the_declared_fallback(): void
    {
        // No Layout/Release exists at all for this project -- proves Task
        // 23's "no-Release output unchanged" contract survives Task 24's
        // new wiring (DoD 1280 must not regress the existing ceiling).
        $slots = app(WidgetSlotRegistry::class);
        $slots->register(new WidgetSlotDeclaration(
            key: 'test.no-release.slot',
            labelKey: 'test.no-release.slot.label',
            cardinality: WidgetSlotDefinition::CARDINALITY_STACK,
            fallback: 'Static Fallback',
        ));

        $result = app(WidgetSlotRuntime::class)->render('test.no-release.slot', new WidgetRenderContext(projectName: self::PROJECT));

        $this->assertTrue($result->ok);
        $this->assertSame('Static Fallback', $result->html);
    }

    // ===================================================================
    // DoD 1281/1282/1285: publish invalidates/switches keys; rollback
    // changes key; listeners run exactly once, only after commit.
    // ===================================================================

    public function test_publish_invalidates_the_previous_release_cache_key_and_a_new_release_gets_a_fresh_key(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $instanceVersion = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->firstOrFail();

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instanceVersion);
        $releaseA = $this->publish($layout, $draft, $user);

        $context = new WidgetRenderContext(projectName: self::PROJECT);
        $runtime = app(WidgetSlotRuntime::class);
        $runtime->render(self::SLOT, $context); // warm the cache for releaseA.

        $keyA = WidgetPlacementCache::buildKey(self::PROJECT, app()->getLocale(), self::SLOT, null, null, null, $releaseA->version);
        $this->assertNotNull($this->placementCache()->get($keyA), 'Precondition: releaseA key is warm.');

        // Rollback (its own commit + WidgetLayoutRolledBack event) mints a
        // NEW Release -- source_release_version = releaseA's own version.
        $freshDraft = WidgetLayoutVersion::query()->find($layout->fresh()->current_draft_version_id);
        $rollbackResult = app(WidgetLayoutRollbackService::class)->rollback(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            'rollback-key-' . uniqid('', true),
            $releaseA->version,
            $freshDraft->version,
            $freshDraft->lock_version,
        );
        $this->assertSame('rolled_back', $rollbackResult->status);
        $releaseB = $rollbackResult->release;

        $this->assertNotSame($releaseA->version, $releaseB->version, 'Rollback must mint a NEW release version -- the key must change (DoD 1285).');

        // The OLD key must have been actively invalidated by the listener.
        $this->assertNull($this->placementCache()->get($keyA), 'Rollback must invalidate the previous release\'s cache key.');

        // The new key resolves fresh and correctly.
        $keyB = WidgetPlacementCache::buildKey(self::PROJECT, app()->getLocale(), self::SLOT, null, null, null, $releaseB->version);
        $this->assertNull($this->placementCache()->get($keyB), 'The new release key must start as a genuine miss, not a stale carry-over.');

        $afterRollback = $runtime->render(self::SLOT, $context);
        $this->assertTrue($afterRollback->ok);
        $this->assertNotNull($this->placementCache()->get($keyB));
    }

    public function test_listener_invalidation_does_not_run_when_the_publish_transaction_rolls_back(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $instanceVersion = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->firstOrFail();

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instanceVersion);

        Event::fake([WidgetLayoutPublished::class]);

        // A STALE expected_draft_id forces the publish to reject BEFORE any
        // commit -- nothing must be dispatched.
        $result = app(WidgetLayoutPublishService::class)->publish(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            'publish-key-' . uniqid('', true),
            $draft->version + 99, // wrong on purpose
            $draft->lock_version,
        );

        $this->assertSame('draft_stale', $result->status);
        Event::assertNotDispatched(WidgetLayoutPublished::class);
    }

    public function test_event_service_provider_maps_both_layout_events_to_both_listeners(): void
    {
        Event::fake();

        Event::assertListening(WidgetLayoutPublished::class, InvalidateWidgetPlacementCache::class);
        Event::assertListening(WidgetLayoutPublished::class, RecordWidgetLayoutTelemetry::class);
        Event::assertListening(WidgetLayoutRolledBack::class, InvalidateWidgetPlacementCache::class);
        Event::assertListening(WidgetLayoutRolledBack::class, RecordWidgetLayoutTelemetry::class);
    }

    public function test_publish_dispatches_the_event_exactly_once_and_the_listener_fires_exactly_once(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $instanceVersion = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->firstOrFail();

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instanceVersion);

        $calls = 0;
        Event::listen(WidgetLayoutPublished::class, function () use (&$calls): void {
            $calls++;
        });

        $this->publish($layout, $draft, $user);

        $this->assertSame(1, $calls, 'Exactly one WidgetLayoutPublished dispatch for one fresh commit.');
    }

    public function test_a_throwing_invalidation_listener_never_surfaces_through_the_publish_response(): void
    {
        // Simulates a broken cache backend during invalidation -- the
        // publish call itself (already committed) must still report
        // success; the listener failure must be swallowed and observable
        // only via report()/logs (DoD 1282).
        $this->app->bind(InvalidateWidgetPlacementCache::class, fn () => new class extends InvalidateWidgetPlacementCache {
            public function handle(WidgetLayoutPublished|WidgetLayoutRolledBack $event): void
            {
                throw new \RuntimeException('Simulated cache backend outage during invalidation.');
            }
        });

        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $instanceVersion = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->firstOrFail();

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instanceVersion);

        // Must not throw.
        $release = $this->publish($layout, $draft, $user);

        $this->assertNotNull($release);
    }

    // ===================================================================
    // DoD 1283: emergency control stays a separate axis from the Release
    // metadata cache; block/clear visible cross-node on next request.
    // ===================================================================

    public function test_emergency_block_is_visible_on_the_next_request_without_invalidating_the_placement_cache(): void
    {
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $instanceVersion = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->firstOrFail();
        $manifest = WidgetArtifactManifest::query()->findOrFail($instanceVersion->artifact_manifest_id);

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instanceVersion);
        $release = $this->publish($layout, $draft, $user);

        $context = new WidgetRenderContext(projectName: self::PROJECT);
        $runtime = app(WidgetSlotRuntime::class);

        $before = $runtime->render(self::SLOT, $context);
        $this->assertTrue($before->ok, 'Must render normally before any emergency block.');

        $cacheKey = WidgetPlacementCache::buildKey(self::PROJECT, app()->getLocale(), self::SLOT, null, null, null, $release->version);
        $cachedBeforeBlock = $this->placementCache()->get($cacheKey);
        $this->assertNotNull($cachedBeforeBlock);

        // A DIFFERENT process (the admin emergency block endpoint) writes
        // directly to the shared row.
        $manifest->forceFill(['blocked' => true, 'control_version' => (int) $manifest->control_version + 1])->save();

        $this->simulateNewIncomingRequest();
        $after = $runtime->render(self::SLOT, $context);

        $this->assertFalse($after->ok, 'The very next request must observe the freshly written emergency block -- shared read-after-write visibility.');

        // The metadata cache itself is UNTOUCHED by the emergency block --
        // separate axis (DoD 1283).
        $this->assertSame($cachedBeforeBlock, $this->placementCache()->get($cacheKey), 'Emergency control must never invalidate the Release-only metadata cache.');

        // Clearing the block restores rendering on the NEXT request.
        $manifest->forceFill(['blocked' => false, 'control_version' => (int) $manifest->control_version + 1])->save();
        $this->simulateNewIncomingRequest();
        $afterClear = $runtime->render(self::SLOT, $context);
        $this->assertTrue($afterClear->ok, 'Clearing the block must be visible on the next request too.');
    }

    // ===================================================================
    // DoD 1285: draft/preview overlays never populate the public cache.
    // ===================================================================

    public function test_preview_mint_and_consume_never_populate_the_public_placement_cache(): void
    {
        config(['database.redis.widget_preview' => [
            'host' => '127.0.0.1',
            'port' => 6399,
            'database' => 15,
        ]]);
        $this->app->singleton(AtomicWidgetPreviewStore::class, fn () => new RedisAtomicWidgetPreviewStore(
            new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 15])
        ));

        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $instanceVersion = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->firstOrFail();

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instanceVersion);

        $this->assertSame(0, $this->rawPlacementCacheKeyCount(), 'Precondition: nothing cached yet.');

        app(WidgetPreviewSessionService::class)->mint(
            self::PROJECT,
            $layout->uuid,
            (int) $user->id,
            [],
            'vi',
            'test.preview.template',
            'test.preview.target',
            'target-1',
            null,
        );

        $this->assertSame(0, $this->rawPlacementCacheKeyCount(), 'Minting a preview session must never write the public placement cache.');
    }

    private function rawPlacementCacheKeyCount(): int
    {
        $client = new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 13]);
        $keys = $client->keys('widget_placement_cache:*');

        return is_array($keys) ? count($keys) : 0;
    }

    // ===================================================================
    // DoD 1279: render/provider/cache telemetry.
    // ===================================================================

    public function test_render_and_cache_telemetry_are_emitted_with_safe_context_only(): void
    {
        $spy = new SpyLogger();
        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $instanceVersion = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->firstOrFail();

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instanceVersion);
        $this->publish($layout, $draft, $user);

        $telemetry = new WidgetRuntimeTelemetry($spy);
        $runtime = new WidgetSlotRuntimeService(app(WidgetSlotRegistry::class), null, null, $telemetry, null);

        $runtime->render(self::SLOT, new WidgetRenderContext(projectName: self::PROJECT));

        $events = array_column($spy->entries, 'message');
        $this->assertContains('widget.runtime_telemetry.cache', $events);
        $this->assertContains('widget.runtime_telemetry.render', $events);

        foreach ($spy->entries as $entry) {
            $this->assertArrayNotHasKey('values', $entry['context']);
            $this->assertArrayNotHasKey('html', $entry['context']);
            $this->assertArrayNotHasKey('token', $entry['context']);
        }
    }

    public function test_publish_telemetry_records_project_layout_release_and_correlation(): void
    {
        $spy = new SpyLogger();
        $this->app->instance(RecordWidgetLayoutTelemetry::class, new RecordWidgetLayoutTelemetry(new WidgetRuntimeTelemetry($spy)));

        $user = $this->fullAccessUser();
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instance = $this->createReadyInstance($widget, $theme, $user);
        $instanceVersion = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->firstOrFail();

        ['layout' => $layout, 'draft' => $draft] = $this->createLayoutWithDraft();
        $this->createPlacement($draft, $instanceVersion);

        $release = $this->publish($layout, $draft, $user);

        $publishEntries = array_values(array_filter($spy->entries, static fn (array $e): bool => $e['message'] === 'widget.runtime_telemetry.publish'));
        $this->assertCount(1, $publishEntries);
        $this->assertSame(self::PROJECT, $publishEntries[0]['context']['project']);
        $this->assertSame($layout->uuid, $publishEntries[0]['context']['layout_uuid']);
        $this->assertSame($release->version, $publishEntries[0]['context']['release_version']);
        $this->assertNotSame('', $publishEntries[0]['context']['correlation_id']);
    }

    // ===================================================================
    // DoD 1284: the root Task-5a-named integration point marks the
    // permanent Site Layout cache bypass BEFORE any cache lookup, and an
    // already-warmed entry cannot bypass it.
    // ===================================================================

    public function test_the_root_middleware_marks_the_bypass_attribute_before_a_downstream_cache_lookup_can_run(): void
    {
        $middleware = new PrepareAnonymousWidgetPreviewRequest();
        $request = Request::create('/any-public-route');

        // A minimal simulated "full-page cache" stand-in: if it runs
        // WITHOUT observing the bypass mark already set, it would serve a
        // stale cached response instead of the real one -- exactly the
        // failure mode DoD 1284 forbids ("a late Cache-Control header
        // alone does not pass"). Positioned as the innermost `$next`, i.e.
        // it runs strictly AFTER the middleware under test, proving the
        // mark is visible in time for a downstream lookup to honor it.
        $sawBypassMark = false;
        $response = $middleware->handle($request, function (Request $req) use (&$sawBypassMark): Response {
            $sawBypassMark = $req->attributes->get(PrepareAnonymousWidgetPreviewRequest::SITE_LAYOUT_CACHE_BYPASS_ATTRIBUTE) === true;

            if (!$sawBypassMark) {
                return new Response('STALE-CACHED-CONTENT', 200);
            }

            return new Response('FRESH-CONTENT', 200);
        });

        $this->assertTrue($sawBypassMark, 'The bypass attribute must be set before the downstream cache-lookup stand-in runs.');
        $this->assertSame('FRESH-CONTENT', $response->getContent());
    }

    public function test_the_root_middleware_bypasses_an_already_warmed_stale_entry(): void
    {
        $middleware = new PrepareAnonymousWidgetPreviewRequest();
        $request = Request::create('/any-public-route');

        // Simulated pre-warmed full-page/CDN entry: a naive cache-lookup
        // stand-in that does NOT consult the bypass mark would return this
        // stale content unconditionally.
        $staleCache = ['/any-public-route' => 'STALE-CACHED-CONTENT'];

        $response = $middleware->handle($request, function (Request $req) use ($staleCache): Response {
            $bypassed = $req->attributes->get(PrepareAnonymousWidgetPreviewRequest::SITE_LAYOUT_CACHE_BYPASS_ATTRIBUTE) === true;

            if (!$bypassed && isset($staleCache[$req->getPathInfo()])) {
                return new Response($staleCache[$req->getPathInfo()], 200);
            }

            return new Response('REAL-FRESH-RESPONSE', 200);
        });

        $this->assertSame('REAL-FRESH-RESPONSE', $response->getContent(), 'An already-warmed stale entry must never be served once the bypass mark is honored.');
    }

    public function test_the_root_middleware_emits_a_no_store_cache_control_header_on_every_response(): void
    {
        $middleware = new PrepareAnonymousWidgetPreviewRequest();
        $request = Request::create('/any-public-route');

        $response = $middleware->handle($request, static fn (Request $req): Response => new Response('ok', 200));

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ===================================================================
    // DoD 1284: production topology guard stays unavailable.
    // ===================================================================

    public function test_the_production_topology_guard_stays_hardcoded_unavailable(): void
    {
        $guard = new \Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlTopologyGuard();

        $this->assertFalse($guard->isAvailable(), 'Task 24 alone must never flip this to available -- Task 24b owns the Cloudflare-evidence-backed enablement.');
    }

    public function test_the_topology_guard_can_prove_a_real_cross_node_shared_store_probe_without_flipping_availability(): void
    {
        $writer = new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 13]);
        $reader = new PredisClient(['host' => '127.0.0.1', 'port' => 6399, 'database' => 13]);

        $guard = new \Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlTopologyGuard();

        $this->assertTrue($guard->isCrossNodeSharedStoreConsistent($writer, $reader), 'A value written by one node must be immediately visible from a different node instance against the real ephemeral Redis.');
        $this->assertFalse($guard->isAvailable(), 'A passing cross-node probe alone must still not flip availability -- the Cloudflare precondition cannot be proven from this repository.');
    }
}

/**
 * Minimal in-memory PSR-3 logger test double shared by this file's
 * telemetry-observability tests (co-located: no separate file slot in
 * this task's allowed_paths, mirroring the module's established
 * co-location precedent for small test-only support types).
 */
final class SpyLogger implements \Psr\Log\LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    public function emergency($message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert($message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical($message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error($message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning($message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice($message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info($message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug($message, array $context = []): void { $this->log('debug', $message, $context); }

    public function log($level, $message, array $context = []): void
    {
        $this->entries[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
