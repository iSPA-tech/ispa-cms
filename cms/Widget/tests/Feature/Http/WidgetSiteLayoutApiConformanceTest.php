<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Http;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Nwidart\Modules\Contracts\ActivatorInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Chunk 2 Task 24b: the LIVE HTTP counterpart to
 * {@see \Cms\Widget\Tests\Unit\Http\WidgetSiteLayoutContractDefinitionTest}
 * (plan lines 1293-1317, DoD 1307-1308).
 *
 * Seeds one real, complete Site Layout lifecycle through
 * `cms:widget-site-layout-fixture --reset --format=json`
 * ({@see \Cms\Widget\Console\Commands\WidgetSiteLayoutE2EFixtureCommand}) in
 * setUp(), then exercises every implemented route in the FROZEN contract
 * (cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json) with a
 * freshly seeded Webmaster, editor, publisher, approver, emergency
 * responder, and denied user -- cross-checking LIVE behavior against the
 * fixture's own declared statuses/permissions/resources/error codes. The
 * fixture itself is NEVER edited by this test; a live/frozen mismatch is a
 * CODE bug to fix, disclosed in the commit, never a fixture edit.
 *
 * "Project-limited user" is deliberately NOT fabricated -- see
 * cms/Widget/tests/Fixtures/widget-site-layout-conformance-cases.json's own
 * `project_limited_role` section and
 * {@see \Cms\Widget\Tests\Feature\Security\WidgetPilotAuthorizationContractTest}'s
 * identical precedent: single active-project mode has no project-scoped
 * RBAC model to exercise.
 */
final class WidgetSiteLayoutApiConformanceTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'WidgetFixtureE2E';

    private const TARGET_TYPE = 'page.static';

    private const TEMPLATE_CONTEXT = 'page_static';

    private const FAKE_TARGET_ID = 'conformance-target-1';

    private const ROUTE_NAME = 'widget_conformance_test_reader';

    private string $storageRoot;

    /** @var array<string, mixed> decoded output of the fixture command. */
    private array $fx = [];

    /** @var array<string, string|null> class-level snapshot: see setUpBeforeClass()'s docblock. */
    private static array $sharedFileSnapshots = [];

    /** @var array<string, string> mutable state threaded through the broad permission-matrix loop. */
    private array $matrixState = [];

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

        $repoRoot = dirname(__DIR__, 5);
        foreach ([
            $repoRoot . '/modules_statuses.json',
            $repoRoot . '/database/seeders/exported/widgets.php',
            $repoRoot . '/database/seeders/exported/widget_themes.php',
            $repoRoot . '/database/seeders/exported/widget_inputs.php',
        ] as $path) {
            self::$sharedFileSnapshots[$path] = file_exists($path) ? file_get_contents($path) : null;
        }

        register_shutdown_function(static function (): void {
            self::restoreSharedFiles();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreSharedFiles();

        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private static function restoreSharedFiles(): void
    {
        foreach (self::$sharedFileSnapshots as $path => $original) {
            if ($original !== null) {
                file_put_contents($path, $original);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        self::$sharedFileSnapshots = [];
    }

    /**
     * Whether the ephemeral test Redis (127.0.0.1:6399) accepts TCP connections.
     * Probed once per PHP process; only the preview-session test needs it, so
     * that test skips cheaply instead of building huge Predis error traces.
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
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = sys_get_temp_dir() . '/ispa-widget-conformance-' . uniqid('', true);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($this->storageRoot . '/app/generated_widgets');
        $this->app->useStoragePath($this->storageRoot);

        // Dedicated ephemeral Redis connection for the atomic preview store
        // (POST .../preview-sessions uses it) -- set BEFORE the provider
        // boots so its singleton factory closure captures these values, not
        // the (unreachable in this environment) default 127.0.0.1:6379.
        // Same coordinator-provided ephemeral instance and short-timeout
        // discipline as WidgetPreviewSessionTest's own established setUp().
        config(['database.redis.widget_preview' => [
            'host' => '127.0.0.1',
            'port' => 6399,
            'database' => 15,
            'timeout' => 2.0,
        ]]);

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot . '/widget-artifact-blobs')
        );

        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return $onAcquired();
            }
        });

        app(ActivatorInterface::class)->setActiveByName('Widget', true);

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);

        $this->registerFakeTargetAndRoute();

        $exitCode = Artisan::call('cms:widget-site-layout-fixture', ['--reset' => true, '--format' => 'json']);
        $output = Artisan::output();
        if ($exitCode !== 0) {
            $this->fail('Fixture command failed during conformance test setUp: ' . $output);
        }
        $this->fx = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            \Illuminate\Support\Facades\File::deleteDirectory($this->storageRoot);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture loaders
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = base_path('cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function conformanceCases(): array
    {
        $path = base_path('cms/Widget/tests/Fixtures/widget-site-layout-conformance-cases.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function findRoute(string $method, string $path): array
    {
        foreach ($this->fixture()['routes'] as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                return $route;
            }
        }

        $this->fail("Route {$method} {$path} not found in the frozen fixture.");
    }

    // -----------------------------------------------------------------
    // Role builders (permission subsets copied verbatim from
    // widget-site-layout-conformance-cases.json's own roles{} section).
    // -----------------------------------------------------------------

    /**
     * @param list<string> $permissionKeys
     */
    private function userWithPermissions(string $label, array $permissionKeys): User
    {
        $user = User::forceCreate([
            'name' => $label,
            'email' => strtolower(str_replace(' ', '-', $label)) . '-' . uniqid('', true) . '@test.local',
            'password' => bcrypt(\Illuminate\Support\Str::random(40)),
            'type' => UserType::ADMIN,
        ]);

        if ($permissionKeys === []) {
            return $user;
        }

        (new WidgetPermissionTableSeeder())->run();

        $role = Role::query()->create(['name' => $label . ' ' . uniqid('', true), 'custom' => ['level' => 50]]);
        foreach ($permissionKeys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permission = Permission::query()->where('group', $group)->where('name', $name)->firstOrFail();
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function roleUser(string $roleKey): User
    {
        $roles = $this->conformanceCases()['roles'];
        $this->assertArrayHasKey($roleKey, $roles, "Unknown conformance role '{$roleKey}'.");

        return $this->userWithPermissions(ucfirst(str_replace('_', ' ', $roleKey)), $roles[$roleKey]);
    }

    // -----------------------------------------------------------------
    // Fake Target Context + a test-only named route (signed preview_url
    // must reference a real named route -- same established pattern as
    // WidgetPreviewSessionTest::TARGET_TYPE/ROUTE_NAME fixtures).
    // -----------------------------------------------------------------

    private function registerFakeTargetAndRoute(): void
    {
        $fake = new FakeWidgetTargetContextResolver();
        $fake->withTarget(self::FAKE_TARGET_ID, self::TARGET_TYPE, self::PROJECT, 'Conformance Target', self::ROUTE_NAME, ['slug' => 'conformance-target']);
        $this->app->instance(FakeWidgetTargetContextResolver::class, $fake);

        app(WidgetTemplateContextRegistry::class)->registerTemplateContext(self::TEMPLATE_CONTEXT, []);
        app(WidgetTargetContextRegistry::class)->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: self::TARGET_TYPE,
            labelKey: 'widget_site_layout.template_context.page_static',
            templateContextKey: self::TEMPLATE_CONTEXT,
            resolverService: FakeWidgetTargetContextResolver::class,
        ));

        if (!Route::has(self::ROUTE_NAME)) {
            Route::middleware(['web'])
                ->get('/__test/widget-conformance/{slug}', static fn (string $slug) => response('conformance:' . $slug))
                ->name(self::ROUTE_NAME);
            app('router')->getRoutes()->refreshNameLookups();
        }
    }

    // -----------------------------------------------------------------
    // Helpers reused across scenarios
    // -----------------------------------------------------------------

    private function currentLayout(): WidgetLayout
    {
        return WidgetLayout::query()->where('uuid', $this->fx['layout_uuid'])->firstOrFail();
    }

    private function currentDraft(): WidgetLayoutVersion
    {
        return WidgetLayoutVersion::query()->findOrFail($this->currentLayout()->current_draft_version_id);
    }

    private function currentInstance(): WidgetInstance
    {
        return WidgetInstance::query()->where('uuid', $this->fx['instance_uuid'])->firstOrFail();
    }

    /**
     * DELIBERATE, DISCLOSED duplicate of UpdateInstanceAndRepinDraft's own
     * private fingerprint formula -- identical precedent already
     * established by UpdateInstanceAndRepinDraftTest's own class docblock.
     *
     * @param list<string> $matchingPlacementUuids
     */
    private function usageFingerprintFor(WidgetLayoutVersion $draft, ?int $instanceCurrentVersion, array $matchingPlacementUuids): string
    {
        sort($matchingPlacementUuids);

        return CanonicalJson::hash([
            'draft_version' => $draft->version,
            'draft_lock_version' => $draft->lock_version,
            'instance_current_version' => $instanceCurrentVersion,
            'matching_placement_uuids' => $matchingPlacementUuids,
        ]);
    }

    /**
     * @return list<string>
     */
    private function matchingPlacementUuidsFor(WidgetLayoutVersion $draft, WidgetInstance $instance): array
    {
        $versionIds = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->pluck('id');

        return WidgetPlacement::query()
            ->where('layout_version_id', $draft->id)
            ->whereIn('widget_instance_version_id', $versionIds)
            ->pluck('uuid')
            ->all();
    }

    private function currentPlacement(): WidgetPlacement
    {
        return WidgetPlacement::query()->where('layout_version_id', $this->currentDraft()->id)->firstOrFail();
    }

    // -----------------------------------------------------------------
    // GROUP 1: the broad 30-route x {Webmaster, denied} permission matrix.
    // "No unexpected 400/403 for Webmaster; no privilege inheritance for
    // others" (task brief) starts here, generalized over every route.
    // -----------------------------------------------------------------

    public function test_every_route_grants_webmaster_and_denies_a_permissionless_user(): void
    {
        $fixture = $this->fixture();
        $webmaster = $this->roleUser('webmaster');
        $denied = $this->roleUser('denied');
        $redisSkippedRoutes = [];

        foreach ($fixture['routes'] as $route) {
            $label = $route['method'] . ' ' . $route['path'];
            [$url, $body, $headers] = $this->requestFor($route['method'], $route['path']);

            $deniedResponse = $this->actingAs($denied, 'sanctum')->withHeaders($headers)->json($route['method'], $url, []);
            $this->assertSame(403, $deniedResponse->getStatusCode(), "{$label}: a permissionless user must be denied (got {$deniedResponse->getStatusCode()}).");
            $this->assertSame('WIDGET_PERMISSION_DENIED', $deniedResponse->json('code'), "{$label}: denial must use the frozen WIDGET_PERMISSION_DENIED code.");

            // The preview-session store needs the ephemeral Redis; without it
            // only the Webmaster half of this route is skipped (reported below).
            if (str_ends_with($route['path'], '/preview-sessions') && ! self::ephemeralRedisReachable()) {
                $redisSkippedRoutes[] = $label;

                continue;
            }

            $webmasterResponse = $this->actingAs($webmaster, 'sanctum')->withHeaders($headers)->json($route['method'], $url, $body);
            $this->assertNotSame(403, $webmasterResponse->getStatusCode(), "{$label}: Webmaster must never be denied (got 403: " . $webmasterResponse->getContent() . ').');
            $this->assertContains(
                $webmasterResponse->getStatusCode(),
                $route['statuses'],
                "{$label}: Webmaster's live status {$webmasterResponse->getStatusCode()} must be declared in the frozen fixture's statuses for this route."
            );

            $this->captureMatrixState($route['method'], $route['path'], $webmasterResponse);
        }

        if ($redisSkippedRoutes !== []) {
            $this->markTestSkipped('Redis 127.0.0.1:6399 not reachable (Webmaster call skipped for ' . implode(', ', $redisSkippedRoutes) . ')');
        }
    }

    /**
     * Threads identities created mid-loop (POST /instances, POST
     * .../clone) into later cases (DELETE /instances/{uuid}, POST
     * .../archive) that need a target instance WITHOUT disturbing the
     * fixture's own primary Instance, which every later Layout/Draft/
     * repin/publish/rollback route in the SAME loop still depends on.
     */
    private function captureMatrixState(string $method, string $path, TestResponse $response): void
    {
        if ($method === 'POST' && $path === '/instances' && $response->getStatusCode() === 201) {
            $this->matrixState['extra_instance_uuid'] = $response->json('data.uuid');
        }
        if ($method === 'POST' && $path === '/instances/{uuid}/clone' && $response->getStatusCode() === 201) {
            $this->matrixState['clone_instance_uuid'] = $response->json('data.uuid');
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function requestFor(string $method, string $path): array
    {
        $base = '/api/v2/admin/widget';
        $fx = $this->fx;
        $idem = fn (string $suffix): array => ['Idempotency-Key' => 'conformance:' . $suffix . ':' . uniqid('', true)];

        return match ($method . ' ' . $path) {
            'GET /slots' => ["{$base}/slots", [], []],
            'GET /target-contexts' => ["{$base}/target-contexts", [], []],
            'GET /target-contexts/{type}/targets' => ["{$base}/target-contexts/" . self::TARGET_TYPE . '/targets', [], []],
            'GET /target-contexts/{type}/targets/{target_id}' => ["{$base}/target-contexts/" . self::TARGET_TYPE . '/targets/' . self::FAKE_TARGET_ID, [], []],
            'GET /health' => ["{$base}/health", [], []],
            'GET /catalog/definitions' => ["{$base}/catalog/definitions", [], []],
            'GET /catalog/definitions/{widget}' => ["{$base}/catalog/definitions/{$fx['widget_id']}", [], []],
            'GET /catalog/manifests' => ["{$base}/catalog/manifests", [], []],
            'GET /catalog/manifests/source-fingerprint' => ["{$base}/catalog/manifests/source-fingerprint?widget_id={$fx['widget_id']}&widget_theme_id={$fx['widget_theme_id']}", [], []],
            'POST /catalog/manifests/materialize' => ["{$base}/catalog/manifests/materialize", [
                'widget_id' => $fx['widget_id'],
                'widget_theme_id' => $fx['widget_theme_id'],
                'expected_source_fingerprint' => str_repeat('0', 64),
            ], []],
            'POST /catalog/manifests/{manifest_hash}/approval' => ["{$base}/catalog/manifests/{$fx['manifest_hash']}/approval", [
                'expected_attestation_version' => $this->freshManifest()->attestation_version,
                'reason' => 'conformance matrix check',
            ], []],
            'DELETE /catalog/manifests/{manifest_hash}/approval' => ["{$base}/catalog/manifests/{$fx['manifest_hash']}/approval", [
                'expected_attestation_version' => $this->freshManifest()->attestation_version,
                'reason' => 'conformance matrix check',
            ], []],
            'POST /catalog/manifests/{manifest_hash}/runtime-block' => ["{$base}/catalog/manifests/{$fx['manifest_hash']}/runtime-block", [
                'expected_control_version' => $this->freshManifest()->control_version,
                'reason' => 'conformance matrix check',
            ], []],
            'DELETE /catalog/manifests/{manifest_hash}/runtime-block' => ["{$base}/catalog/manifests/{$fx['manifest_hash']}/runtime-block", [
                'expected_control_version' => $this->freshManifest()->control_version,
                'reason' => 'conformance matrix check',
            ], []],
            'GET /instances' => ["{$base}/instances", [], []],
            'POST /instances' => ["{$base}/instances", [
                'name' => 'Conformance Extra Instance ' . uniqid('', true),
                'widget_id' => $fx['widget_id'],
                'widget_theme_id' => $fx['widget_theme_id'],
                'values' => [],
                'provider_contract' => [],
            ], $idem('instance-create')],
            'GET /instances/{uuid}' => ["{$base}/instances/{$fx['instance_uuid']}", [], []],
            'PATCH /instances/{uuid}' => ["{$base}/instances/{$fx['instance_uuid']}", [
                'expected_current_version' => $this->currentInstance()->currentVersion->version,
                'widget_id' => $fx['widget_id'],
                'widget_theme_id' => $fx['widget_theme_id'],
                'values' => ['title' => 'Conformance Updated'],
            ], $idem('instance-update')],
            'DELETE /instances/{uuid}' => ["{$base}/instances/" . ($this->matrixState['extra_instance_uuid'] ?? $fx['instance_uuid']), [], []],
            'GET /instances/{uuid}/usage' => ["{$base}/instances/{$fx['instance_uuid']}/usage", [], []],
            'POST /instances/{uuid}/clone' => ["{$base}/instances/{$fx['instance_uuid']}/clone", [
                'name' => 'Conformance Clone ' . uniqid('', true),
            ], $idem('instance-clone')],
            'POST /instances/{uuid}/archive' => ["{$base}/instances/" . ($this->matrixState['clone_instance_uuid'] ?? $fx['instance_uuid']) . '/archive', [], []],
            'GET /layouts' => ["{$base}/layouts", [], []],
            'PUT /layouts/by-key/{layout_key}' => ["{$base}/layouts/by-key/site", [], []],
            'GET /layouts/{uuid}' => ["{$base}/layouts/{$fx['layout_uuid']}", [], []],
            'PUT /layouts/{uuid}/draft' => ["{$base}/layouts/{$fx['layout_uuid']}/draft", $this->currentDraftSaveBody(), []],
            'GET /layouts/{uuid}/draft' => ["{$base}/layouts/{$fx['layout_uuid']}/draft", [], []],
            'GET /layouts/{uuid}/draft/changes' => ["{$base}/layouts/{$fx['layout_uuid']}/draft/changes", [], []],
            'POST /layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin' => [
                "{$base}/layouts/{$fx['layout_uuid']}/draft/instances/{$fx['instance_uuid']}/versions-and-repin",
                $this->currentRepinBody(),
                $idem('repin'),
            ],
            'POST /layouts/{uuid}/draft/placements' => ["{$base}/layouts/{$fx['layout_uuid']}/draft/placements", [
                'slot_key' => $this->currentPlacement()->slot_key,
                'scope_type' => $this->currentPlacement()->scope_type,
                'scope_key' => $this->currentPlacement()->scope_key,
                'target_type' => $this->currentPlacement()->target_type,
                'target_id' => $this->currentPlacement()->target_id,
                'widget_id' => $fx['widget_id'],
                'widget_theme_id' => $fx['widget_theme_id'],
                'values' => [],
                'expected_draft_revision' => $this->currentDraft()->lock_version,
            ], $idem('placement-create')],
            'PATCH /layouts/{uuid}/draft/placements/{placement_uuid}/content' => ["{$base}/layouts/{$fx['layout_uuid']}/draft/placements/{$this->currentPlacement()->uuid}/content", [
                'values' => ['title' => 'Conformance Placement'],
                'expected_draft_revision' => $this->currentDraft()->lock_version,
            ], $idem('placement-content')],
            'POST /layouts/{uuid}/draft/placements/{placement_uuid}/duplicate' => ["{$base}/layouts/{$fx['layout_uuid']}/draft/placements/{$this->currentPlacement()->uuid}/duplicate", [
                'expected_draft_revision' => $this->currentDraft()->lock_version,
            ], $idem('placement-duplicate')],
            'GET /layouts/{uuid}/releases' => ["{$base}/layouts/{$fx['layout_uuid']}/releases", [], []],
            'POST /layouts/{uuid}/preview-sessions' => ["{$base}/layouts/{$fx['layout_uuid']}/preview-sessions", [
                'overlay' => [],
                'locale' => 'vi',
                'template_context' => self::TEMPLATE_CONTEXT,
                'target_type' => self::TARGET_TYPE,
                'target_id' => self::FAKE_TARGET_ID,
                'viewport' => 'desktop',
            ], []],
            'POST /layouts/{uuid}/publish' => ["{$base}/layouts/{$fx['layout_uuid']}/publish", [
                'expected_draft_id' => $this->currentDraft()->version,
                'expected_lock_version' => $this->currentDraft()->lock_version,
            ], $idem('publish')],
            'POST /layouts/{uuid}/rollback' => ["{$base}/layouts/{$fx['layout_uuid']}/rollback", [
                'source_release_version' => $fx['release_version'],
                'expected_draft_id' => $this->currentDraft()->version,
                'expected_lock_version' => $this->currentDraft()->lock_version,
            ], $idem('rollback')],
            default => $this->fail("No request builder registered for {$method} {$path}."),
        };
    }

    private function freshManifest(): WidgetArtifactManifestModel
    {
        return WidgetArtifactManifestModel::query()->where('manifest_hash', $this->fx['manifest_hash'])->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function currentDraftSaveBody(): array
    {
        $draft = $this->currentDraft();
        $placement = $this->currentPlacement();
        $instance = $this->currentInstance();

        return [
            'expected_current_draft_id' => $draft->version,
            'expected_lock_version' => $draft->lock_version,
            'placements' => [[
                'slot_key' => $placement->slot_key,
                'scope_type' => $placement->scope_type,
                'scope_key' => $placement->scope_key,
                'target_type' => $placement->target_type,
                'target_id' => $placement->target_id,
                'widget_instance_uuid' => $instance->uuid,
                'widget_instance_version' => $instance->currentVersion->version,
                'position' => 0,
                'enabled' => true,
                'rules' => [],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentRepinBody(): array
    {
        $draft = $this->currentDraft();
        $instance = $this->currentInstance();
        $matching = $this->matchingPlacementUuidsFor($draft, $instance);

        return [
            'expected_current_instance_version' => $instance->currentVersion->version,
            'expected_draft_id' => $draft->version,
            'expected_draft_lock_version' => $draft->lock_version,
            'usage_fingerprint' => $this->usageFingerprintFor($draft, $instance->currentVersion->version, $matching),
            'widget_id' => $this->fx['widget_id'],
            'widget_theme_id' => $this->fx['widget_theme_id'],
            'values' => [],
            'provider_contract' => [],
        ];
    }

    // -----------------------------------------------------------------
    // Privilege-inheritance boundaries (data-driven from the conformance
    // case list).
    // -----------------------------------------------------------------

    public function test_privilege_inheritance_boundaries_from_conformance_cases(): void
    {
        foreach ($this->conformanceCases()['privilege_inheritance_checks'] as $case) {
            $user = $this->roleUser($case['role']);
            [$url, $body, $headers] = $this->requestFor($case['method'], $case['path']);

            $response = $this->actingAs($user, 'sanctum')->withHeaders($headers)->json($case['method'], $url, $body);

            $label = "{$case['role']} {$case['method']} {$case['path']}: {$case['reason']}";
            if ($case['expect'] === 'denied') {
                $this->assertSame(403, $response->getStatusCode(), $label);
                $this->assertSame('WIDGET_PERMISSION_DENIED', $response->json('code'), $label);
            } else {
                $this->assertNotSame(403, $response->getStatusCode(), $label);
            }
        }
    }

    // -----------------------------------------------------------------
    // GROUP 2: named scenarios (DoD 1308).
    // -----------------------------------------------------------------

    public function test_resource_field_projection_matches_frozen_resource_definitions(): void
    {
        $webmaster = $this->roleUser('webmaster');
        $fixture = $this->fixture();

        $response = $this->actingAs($webmaster, 'sanctum')->getJson("/api/v2/admin/widget/instances/{$this->fx['instance_uuid']}");
        $response->assertOk();

        $expectedFields = $fixture['resources']['WidgetInstanceResource']['fields'];
        $actualFields = array_keys($response->json('data'));
        sort($expectedFields);
        sort($actualFields);
        $this->assertSame($expectedFields, $actualFields, 'Live WidgetInstanceResource projection must match the frozen field set exactly.');

        $layoutResponse = $this->actingAs($webmaster, 'sanctum')->getJson("/api/v2/admin/widget/layouts/{$this->fx['layout_uuid']}");
        $layoutResponse->assertOk();
        $expectedLayoutFields = $fixture['resources']['WidgetLayoutResource']['fields'];
        $actualLayoutFields = array_keys($layoutResponse->json('data'));
        sort($expectedLayoutFields);
        sort($actualLayoutFields);
        $this->assertSame($expectedLayoutFields, $actualLayoutFields, 'Live WidgetLayoutResource projection must match the frozen field set exactly.');
    }

    public function test_layout_bootstrap_201_then_200_and_get_never_creates(): void
    {
        // The shared fixture already bootstrapped 'site' -- tear it down for
        // THIS test only so the 201-then-200 sequence is genuinely observed.
        $layout = $this->currentLayout();
        $versionIds = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->pluck('id');
        WidgetPlacement::query()->whereIn('layout_version_id', $versionIds)->delete();
        $layout->forceFill(['current_draft_version_id' => null, 'current_published_version_id' => null])->save();
        WidgetLayoutVersion::query()->whereIn('id', $versionIds)->update(['based_on_version_id' => null, 'rollback_of_version_id' => null]);
        WidgetLayoutVersion::query()->whereIn('id', $versionIds)->delete();
        $layout->delete();

        $webmaster = $this->roleUser('webmaster');

        $this->assertSame(0, WidgetLayout::query()->count(), 'Precondition: no Layout must exist before bootstrap.');
        $this->actingAs($webmaster, 'sanctum')->getJson('/api/v2/admin/widget/layouts')->assertOk();
        $this->assertSame(0, WidgetLayout::query()->count(), 'GET /layouts must never create a Layout as a side effect.');

        $first = $this->actingAs($webmaster, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site', []);
        $first->assertStatus(201);
        $this->assertSame(1, WidgetLayout::query()->count());

        // by-key is PUT-only in the frozen contract; GET is a different route. A Default site has no web catch-all
        // route, so the method mismatch answers 405; a project site with its own catch-all answers 404.
        $this->assertContains(
            $this->actingAs($webmaster, 'sanctum')->getJson('/api/v2/admin/widget/layouts/by-key/site')->getStatusCode(),
            [404, 405]
        );

        $second = $this->actingAs($webmaster, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site', []);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'), 'The second bootstrap call must return the SAME identity, not a duplicate.');
        $this->assertSame(1, WidgetLayout::query()->count(), 'Bootstrap must never duplicate the Layout.');
    }

    public function test_generic_target_search_and_detail_round_trip(): void
    {
        $webmaster = $this->roleUser('webmaster');

        $search = $this->actingAs($webmaster, 'sanctum')->getJson('/api/v2/admin/widget/target-contexts/' . self::TARGET_TYPE . '/targets');
        $search->assertOk();
        $this->assertSame(self::FAKE_TARGET_ID, $search->json('data.0.target_id'));

        $detail = $this->actingAs($webmaster, 'sanctum')->getJson('/api/v2/admin/widget/target-contexts/' . self::TARGET_TYPE . '/targets/' . self::FAKE_TARGET_ID);
        $detail->assertOk()->assertJsonPath('data.target_id', self::FAKE_TARGET_ID)->assertJsonPath('data.label', 'Conformance Target');

        $missing = $this->actingAs($webmaster, 'sanctum')->getJson('/api/v2/admin/widget/target-contexts/' . self::TARGET_TYPE . '/targets/does-not-exist');
        $missing->assertStatus(404);
    }

    public function test_instance_create_idempotency_replay_is_complete(): void
    {
        $webmaster = $this->roleUser('webmaster');
        $payload = [
            'name' => 'Conformance Idempotent Instance',
            'widget_id' => $this->fx['widget_id'],
            'widget_theme_id' => $this->fx['widget_theme_id'],
            'values' => [],
            'provider_contract' => [],
        ];

        $first = $this->actingAs($webmaster, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'conformance-replay-key'])
            ->postJson('/api/v2/admin/widget/instances', $payload);
        $first->assertCreated();

        $second = $this->actingAs($webmaster, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'conformance-replay-key'])
            ->postJson('/api/v2/admin/widget/instances', $payload);
        $second->assertCreated()->assertJsonPath('data.uuid', $first->json('data.uuid'));

        $mismatched = $this->actingAs($webmaster, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'conformance-replay-key'])
            ->postJson('/api/v2/admin/widget/instances', [...$payload, 'name' => 'Different Name']);
        $mismatched->assertStatus(409)->assertJsonPath('code', 'WIDGET_IDEMPOTENCY_MISMATCH');
    }

    public function test_materialize_approve_revoke_and_emergency_controls_chain(): void
    {
        $webmaster = $this->roleUser('webmaster');

        $widget = Widget::query()->create(['name' => 'Conformance Chain Widget', 'description' => '', 'js' => '']);
        $theme = WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Conformance Chain Theme',
            'content' => '<div>{title}</div>',
            'css' => '',
            'project_name' => [self::PROJECT],
        ]);

        $fingerprint = (new \Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer())->analyze(
            widgetJs: (string) $widget->js,
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: (new \App\Support\Widget\CanonicalWidgetSchema())->resolve($widget->id, $theme->id),
            policyVersion: \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: [self::PROJECT],
            artifactBytes: (new \Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher())->renderThemeContent($theme),
        )->manifestHash;

        $materialize = $this->actingAs($webmaster, 'sanctum')->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'expected_source_fingerprint' => $fingerprint,
        ]);
        $materialize->assertCreated();
        $hash = $materialize->json('data.manifest_hash');

        $approve = $this->actingAs($webmaster, 'sanctum')->postJson("/api/v2/admin/widget/catalog/manifests/{$hash}/approval", [
            'expected_attestation_version' => 1,
            'reason' => 'conformance chain',
        ]);
        $approve->assertOk()->assertJsonPath('data.eligibility_state', WidgetManifestApprovalService::STATE_APPROVED);

        $revoke = $this->actingAs($webmaster, 'sanctum')->deleteJson("/api/v2/admin/widget/catalog/manifests/{$hash}/approval", [
            'expected_attestation_version' => $approve->json('data.attestation_version'),
            'reason' => 'conformance chain revoke',
        ]);
        $revoke->assertOk()->assertJsonPath('data.eligibility_state', WidgetManifestApprovalService::STATE_REVOKED);

        // Emergency block/clear stay INERT by default (see the dedicated
        // Group 3 test for the full precondition proof) -- re-confirmed
        // here as part of the named chain per DoD 1308's own wording.
        $block = $this->actingAs($webmaster, 'sanctum')->postJson("/api/v2/admin/widget/catalog/manifests/{$hash}/runtime-block", [
            'expected_control_version' => 1,
            'reason' => 'conformance chain block',
        ]);
        $block->assertStatus(503)->assertJsonPath('code', 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE');
    }

    public function test_repin_returns_409_and_inserts_nothing_when_expected_instance_version_is_stale(): void
    {
        $webmaster = $this->roleUser('webmaster');
        $draft = $this->currentDraft();
        $instance = $this->currentInstance();
        $matching = $this->matchingPlacementUuidsFor($draft, $instance);
        $beforeVersionCount = WidgetInstanceVersion::query()->count();
        $beforeLayoutVersionCount = WidgetLayoutVersion::query()->count();

        $response = $this->actingAs($webmaster, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'conformance-repin-stale'])
            ->postJson("/api/v2/admin/widget/layouts/{$this->fx['layout_uuid']}/draft/instances/{$this->fx['instance_uuid']}/versions-and-repin", [
                'expected_current_instance_version' => 999999,
                'expected_draft_id' => $draft->version,
                'expected_draft_lock_version' => $draft->lock_version,
                'usage_fingerprint' => $this->usageFingerprintFor($draft, 999999, $matching),
                'widget_id' => $this->fx['widget_id'],
                'widget_theme_id' => $this->fx['widget_theme_id'],
                'values' => [],
                'provider_contract' => [],
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_USAGE_CHANGED');
        $this->assertSame($beforeVersionCount, WidgetInstanceVersion::query()->count(), 'A stale repin must insert zero new Instance Versions (all-or-nothing).');
        $this->assertSame($beforeLayoutVersionCount, WidgetLayoutVersion::query()->count(), 'A stale repin must insert zero new Layout Versions (all-or-nothing).');
    }

    public function test_draft_save_is_placement_only_and_never_touches_instance(): void
    {
        $webmaster = $this->roleUser('webmaster');
        $instanceBefore = $this->currentInstance();
        $versionCountBefore = WidgetInstanceVersion::query()->where('instance_id', $instanceBefore->id)->count();

        $response = $this->actingAs($webmaster, 'sanctum')
            ->putJson("/api/v2/admin/widget/layouts/{$this->fx['layout_uuid']}/draft", $this->currentDraftSaveBody());

        $response->assertOk();
        $instanceAfter = $this->currentInstance();
        $this->assertSame($instanceBefore->current_version_id, $instanceAfter->current_version_id, 'Draft Save must never repin/move the Instance current-version pointer.');
        $this->assertSame($versionCountBefore, WidgetInstanceVersion::query()->where('instance_id', $instanceBefore->id)->count(), 'Draft Save must never create an Instance Version.');
    }

    /**
     * Backend addendum (disclosed contract evolution, routes 30->31):
     * `GET /layouts/{uuid}/draft` is the pure-read companion to `PUT
     * /layouts/{uuid}/draft` this test file already exercises above. Proves
     * the four DoD points in one place: Webmaster 200 with a placements[]
     * shape identical to WidgetPlacementResource's own frozen fields (the
     * same shape the write path returns), a denied user gets 403, an
     * unknown uuid gets 404, and the GET performs no observable side
     * effect (no new Layout Version, no Placement mutation, lock_version
     * unchanged).
     */
    public function test_get_layout_draft_mirrors_the_write_shape_with_no_side_effect_and_permission_handling(): void
    {
        $fixture = $this->fixture();
        $webmaster = $this->roleUser('webmaster');
        $denied = $this->roleUser('denied');

        $draftBefore = $this->currentDraft();
        $placement = $this->currentPlacement();
        $placementCountBefore = WidgetPlacement::query()->where('layout_version_id', $draftBefore->id)->count();
        $layoutVersionCountBefore = WidgetLayoutVersion::query()->count();

        $deniedResponse = $this->actingAs($denied, 'sanctum')
            ->getJson("/api/v2/admin/widget/layouts/{$this->fx['layout_uuid']}/draft");
        $deniedResponse->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');

        $missingResponse = $this->actingAs($webmaster, 'sanctum')
            ->getJson('/api/v2/admin/widget/layouts/' . \Illuminate\Support\Str::uuid() . '/draft');
        $missingResponse->assertStatus(404);

        $response = $this->actingAs($webmaster, 'sanctum')
            ->getJson("/api/v2/admin/widget/layouts/{$this->fx['layout_uuid']}/draft");
        $response->assertOk();

        $expectedFields = $fixture['resources']['WidgetLayoutDraftResource']['fields'];
        $actualFields = array_keys($response->json('data'));
        sort($expectedFields);
        sort($actualFields);
        $this->assertSame($expectedFields, $actualFields, 'Live WidgetLayoutDraftResource projection must match the frozen field set exactly.');

        $this->assertSame($draftBefore->version, $response->json('data.version'));
        $this->assertSame($draftBefore->lock_version, $response->json('data.lock_version'));

        $expectedPlacementFields = $fixture['resources']['WidgetPlacementResource']['fields'];
        $actualPlacementFields = array_keys($response->json('data.placements.0'));
        sort($expectedPlacementFields);
        sort($actualPlacementFields);
        $this->assertSame($expectedPlacementFields, $actualPlacementFields, 'Each placements[] entry must mirror WidgetPlacementResource\'s frozen field set exactly -- the same shape PUT /layouts/{uuid}/draft already returns.');
        $this->assertSame($placement->uuid, $response->json('data.placements.0.uuid'));
        $this->assertSame($placement->slot_key, $response->json('data.placements.0.slot_key'));
        $this->assertSame($placement->scope_type, $response->json('data.placements.0.scope_type'));
        $this->assertSame($placement->enabled, $response->json('data.placements.0.enabled'));

        // instance_fingerprints exposes exactly the per-Instance
        // usage_fingerprint POST .../versions-and-repin validates, so the
        // admin canvas echoes it back on repin instead of recomputing a
        // server-only formula. This test independently recomputes it via
        // usageFingerprintFor() (its own disclosed duplicate) and asserts the
        // exposed value is byte-identical -- proving the read path and the
        // repin validator cannot drift (both use one shared primitive).
        $instance = $this->currentInstance();
        $matching = $this->matchingPlacementUuidsFor($draftBefore, $instance);
        $this->assertSame(
            $this->usageFingerprintFor($draftBefore, $instance->currentVersion->version, $matching),
            $response->json("data.instance_fingerprints.{$instance->uuid}"),
            'GET /layouts/{uuid}/draft must expose each referenced Instance\'s repin usage_fingerprint under its uuid, byte-identical to what versions-and-repin validates.'
        );

        $this->assertSame($layoutVersionCountBefore, WidgetLayoutVersion::query()->count(), 'GET must never create a Layout Version.');
        $this->assertSame($placementCountBefore, WidgetPlacement::query()->where('layout_version_id', $draftBefore->id)->count(), 'GET must never mutate Placements.');
        $draftAfter = $this->currentDraft();
        $this->assertSame($draftBefore->version, $draftAfter->version, 'GET must never change the current Draft identity.');
        $this->assertSame($draftBefore->lock_version, $draftAfter->lock_version, 'GET must never increment lock_version.');
    }

    public function test_preview_session_returns_a_url_not_a_persisted_resource(): void
    {
        if (! self::ephemeralRedisReachable()) {
            $this->markTestSkipped('Redis 127.0.0.1:6399 not reachable');
        }

        $webmaster = $this->roleUser('webmaster');

        $response = $this->actingAs($webmaster, 'sanctum')->postJson("/api/v2/admin/widget/layouts/{$this->fx['layout_uuid']}/preview-sessions", [
            'overlay' => [],
            'locale' => 'vi',
            'template_context' => self::TEMPLATE_CONTEXT,
            'target_type' => self::TARGET_TYPE,
            'target_id' => self::FAKE_TARGET_ID,
            'viewport' => 'desktop',
        ]);

        // response_resource is null for this route in the frozen fixture --
        // no JsonResource envelope, a flat payload (WidgetLayoutPreviewController::successPayload()).
        $response->assertCreated();
        $this->assertIsString($response->json('preview_url'));
        $this->assertNotSame('', $response->json('preview_url'));
        $this->assertArrayHasKey('expires_at', $response->json());
        $this->assertArrayHasKey('fingerprint', $response->json());
    }

    public function test_publish_and_rollback_both_return_two_identities(): void
    {
        $webmaster = $this->roleUser('webmaster');
        $draft = $this->currentDraft();

        $publish = $this->actingAs($webmaster, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'conformance-publish-two-identity'])
            ->postJson("/api/v2/admin/widget/layouts/{$this->fx['layout_uuid']}/publish", [
                'expected_draft_id' => $draft->version,
                'expected_lock_version' => $draft->lock_version,
            ]);
        // No JsonResource envelope here either -- the controller builds a
        // composite {release, draft, sync_status} payload directly
        // (WidgetLayoutPublishController::payload()), release/draft each
        // carrying their own "identity" (both frozen fixture note: "response
        // includes new Release identity and fresh Draft identity/lock").
        $publish->assertSuccessful();
        $this->assertIsInt($publish->json('release.version'), 'Publish response must carry the new Release identity.');
        $this->assertIsInt($publish->json('draft.version'), 'Publish response must also carry a fresh Draft identity.');
        $newDraft = $this->currentDraft();
        $this->assertSame($publish->json('release.version') + 1, $newDraft->version, 'Publish must leave a fresh Draft identity (release + 1).');
        $this->assertSame($publish->json('draft.version'), $newDraft->version);

        $rollback = $this->actingAs($webmaster, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'conformance-rollback-two-identity'])
            ->postJson("/api/v2/admin/widget/layouts/{$this->fx['layout_uuid']}/rollback", [
                'source_release_version' => $this->fx['release_version'],
                'expected_draft_id' => $newDraft->version,
                'expected_lock_version' => $newDraft->lock_version,
            ]);
        $rollback->assertSuccessful();
        $this->assertIsInt($rollback->json('release.version'), 'Rollback response must carry the new Release identity.');
        $this->assertIsInt($rollback->json('draft.version'), 'Rollback response must also carry a fresh Draft identity.');
        $rolledBackDraft = $this->currentDraft();
        $this->assertSame($rollback->json('release.version') + 1, $rolledBackDraft->version, 'Rollback must leave a fresh Draft identity (release + 1); it never reactivates the historical Release.');
    }

    public function test_registry_preflight_passes_for_the_seeded_fixture(): void
    {
        $exitCode = Artisan::call('cms:widget-registry-preflight');
        $this->assertSame(0, $exitCode, 'Registry preflight must pass for the deterministic fixture (no retained semantic drift): ' . Artisan::output());
    }

    public function test_catalog_definitions_lists_unapproved_pairs_and_hides_blocked_widgets(): void
    {
        $webmaster = $this->roleUser('webmaster');

        // Unified Widget Catalog Part 2: no approval step -- a never-materialized pair is placeable
        // (approved on placement); only a Core-blocked widget is hidden.
        $pendingWidget = Widget::query()->create(['name' => 'Conformance Pending Widget', 'description' => '', 'js' => '']);
        WidgetTheme::query()->create([
            'widget_id' => $pendingWidget->id,
            'name' => 'Conformance Pending Theme',
            'content' => '<div></div>',
            'css' => '',
            'project_name' => [self::PROJECT],
        ]);
        $blockedWidget = Widget::query()->create(['name' => 'Conformance Blocked Widget', 'description' => '', 'js' => '']);
        WidgetTheme::query()->create([
            'widget_id' => $blockedWidget->id,
            'name' => 'Conformance Blocked Theme',
            'content' => '<span></span>',
            'css' => '',
            'project_name' => [self::PROJECT],
        ]);
        $blockedWidget->forceFill(['blocked_at' => now(), 'blocked_reason' => 'conformance'])->save();

        $response = $this->actingAs($webmaster, 'sanctum')->getJson('/api/v2/admin/widget/catalog/definitions');
        $response->assertOk();

        $widgetIds = array_column($response->json('data'), 'widget_id');
        $this->assertContains($this->fx['widget_id'], $widgetIds, 'The approved fixture pair must appear in the catalog.');
        $this->assertContains($pendingWidget->id, $widgetIds, 'A not yet approved pair must be placeable (approved on placement).');
        $this->assertNotContains($blockedWidget->id, $widgetIds, 'A Core-blocked widget must never be offered for placing.');
    }

    public function test_health_payload_shape(): void
    {
        $webmaster = $this->roleUser('webmaster');

        $response = $this->actingAs($webmaster, 'sanctum')->getJson('/api/v2/admin/widget/health');

        $response->assertOk();
        $this->assertIsArray($response->json('data') ?? $response->json());
    }

    // -----------------------------------------------------------------
    // GROUP 3: emergency INERT (topology guard unavailable by default).
    // -----------------------------------------------------------------

    public function test_emergency_runtime_block_and_clear_are_inert_by_default(): void
    {
        $emergencyResponder = $this->roleUser('emergency_responder');
        $manifestBefore = $this->freshManifest();

        foreach ($this->conformanceCases()['emergency_inert_routes'] as $case) {
            $url = str_replace('{manifest_hash}', $this->fx['manifest_hash'], $case['path']);
            $response = $this->actingAs($emergencyResponder, 'sanctum')
                ->json($case['method'], "/api/v2/admin/widget" . $url, ['expected_control_version' => 1, 'reason' => 'inert check']);

            $response->assertStatus($case['expected_status']);
            $this->assertSame($case['expected_code'], $response->json('code'));
        }

        $manifestAfter = $this->freshManifest();
        $this->assertSame($manifestBefore->control_version, $manifestAfter->control_version, 'A topology-preflight failure must never mutate control_version.');
        $this->assertSame((bool) $manifestBefore->blocked, (bool) $manifestAfter->blocked, 'A topology-preflight failure must never mutate blocked.');

        // Blanket endpoint-level precondition, not per-resource: an UNKNOWN
        // manifest_hash under an unavailable topology still returns 503,
        // never 404 (WidgetManifestRuntimeBlockController's own documented
        // behavior -- the topology guard is checked before any row lookup).
        $unknownHashResponse = $this->actingAs($emergencyResponder, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/does-not-exist-hash/runtime-block', [
                'expected_control_version' => 1,
                'reason' => 'inert check unknown hash',
            ]);
        $unknownHashResponse->assertStatus(503)->assertJsonPath('code', 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE');
    }

    // -----------------------------------------------------------------
    // GROUP 4: project selector (structural proof for the disclosed
    // "project-limited user is not fabricated" judgment call).
    // -----------------------------------------------------------------

    public function test_no_route_accepts_a_client_supplied_project_selector(): void
    {
        foreach ($this->fixture()['routes'] as $route) {
            $fields = $route['request_fields'] ?? [];
            $this->assertNotContains('project_name', $fields, "{$route['method']} {$route['path']} must never accept a client-supplied project_name.");
            $this->assertNotContains('project', $fields, "{$route['method']} {$route['path']} must never accept a client-supplied project selector.");
        }
    }
}
