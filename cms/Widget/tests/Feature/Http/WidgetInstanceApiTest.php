<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Http;

use App\Constants\UserType;
use App\Contracts\Modules\ModuleLifecycleBarrier;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Models\User;
use App\Support\Modules\MaintenanceOnlyModuleLifecycleBarrier;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetIdempotencyKey;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Contracts\ActivatorInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Chunk 2 Task 16: `/api/v2/admin/widget/instances*`
 * (
 * lines 975-1018; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * instances route entries).
 *
 * Boots the real WidgetServiceProvider and rebinds FilesystemWidgetArtifactBlobStore
 * to an isolated temp root AFTER boot, mirroring WidgetManifestMaterializationApiTest's
 * established pattern (see that class's own docblock for why: direct
 * WidgetPermissionTableSeeder instantiation, never `$this->seed()`, avoids
 * an Artisan-triggered container cache that would defeat a later storage
 * rebind).
 *
 * ModuleLifecycleBarrier is rebound to a trivial pass-through test double:
 * the real bound default ({@see \App\Support\Modules\MaintenanceOnlyModuleLifecycleBarrier})
 * refuses every non-console-SAPI caller unconditionally (its own class
 * docblock), and while PHPUnit itself runs under the cli SAPI (so
 * runningInConsole() is true for this whole test process), that default
 * still requires either a genuine down-for-maintenance marker or an
 * explicit $maintenanceConfirmed=true this service never passes (see
 * WidgetInstanceService::runVersionCreatingTransaction()'s own docblock).
 * A pass-through double keeps the PRODUCTION default's console-only
 * conservatism completely unchanged while letting this suite exercise the
 * module-enabled recheck the service itself performs inside the callback.
 */
final class WidgetInstanceApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private string $storageRoot;

    private string $isolatedStoragePath;

    /** @var array<string, string|null> class-level snapshot: see setUpBeforeClass()'s docblock. */
    private static array $sharedFileSnapshots = [];

    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * Captures the pristine, pre-suite content of every REAL, git-tracked
     * file this class's tests mutate (modules_statuses.json via
     * setWidgetModuleEnabled(), and the three database/seeders/exported/
     * *.php files the Core WidgetController/WidgetThemeController
     * integration tests indirectly touch through WidgetDefinitionSyncService
     * -- see setUp()'s own docblock) EXACTLY ONCE for the whole class,
     * restored exactly once in tearDownAfterClass().
     *
     * Deliberately class-level (setUpBeforeClass()/tearDownAfterClass()),
     * NOT per-method (setUp()/tearDown()): capturing "original" and
     * restoring it 27 times per test run (once per test method) proved
     * fragile in practice against a real, external, disk-persisted target
     * shared with the rest of this suite -- an interim capture could
     * momentarily race a still-open mutation from a still-running earlier
     * test relying on this same global file. A single capture/restore pair
     * around the ENTIRE class removes that window; individual tests still
     * freely flip modules_statuses.json's "Widget" entry mid-run via
     * setWidgetModuleEnabled(), they just never own restoring it back to
     * the PRISTINE pre-suite baseline themselves.
     *
     * Computed via __DIR__ rather than base_path(): this static hook can
     * run before any instance's setUp() has booted the Laravel application.
     *
     * BELT AND SUSPENDERS: also registers a shutdown function that runs the
     * identical restore unconditionally at PHP process exit. Observed in
     * practice: when this file runs alongside several OTHER explicit test
     * file paths in one `artisan test`/pest invocation (as the plan's own
     * named verification command does), tearDownAfterClass() is not always
     * the last thing Pest's runner does before the process exits, so a
     * restore that depends SOLELY on that hook firing is not fully
     * reliable. `register_shutdown_function()` is a PHP-level guarantee
     * independent of PHPUnit/Pest's own lifecycle, and {@see self::restoreSharedFiles()}
     * is idempotent (a no-op once the snapshot map is already empty), so
     * running it twice (once from tearDownAfterClass(), once at shutdown)
     * is always safe.
     */
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

    protected function setUp(): void
    {
        parent::setUp();

        config(['ispa-cms.folder_name' => self::PROJECT]);

        // The Core WidgetController/WidgetThemeController integration tests
        // below exercise the REAL legacy update()/destroy() endpoints, whose
        // existing WidgetDefinitionSyncService::sync() call runs the real
        // `cms:export-widgets` Artisan command (writing the CURRENT --
        // during a test, nearly-empty -- database into the git-tracked
        // database/seeders/exported/*.php files) and publishes real
        // generated artifact files, exactly the same side effect
        // cms/Core/tests/Feature/Security/WidgetTrustBoundaryTest.php's own
        // beforeEach() isolates via useStoragePath(). storage_path()-based
        // writes are isolated the identical way here; database/seeders/
        // exported/*.php is NOT under storage_path() (unaffected by
        // useStoragePath()) -- its content is protected by
        // setUpBeforeClass()/tearDownAfterClass()'s class-level snapshot
        // instead (see that method's own docblock).
        $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-instance-api-' . uniqid('', true);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($this->isolatedStoragePath . '/app/generated_widgets');
        $this->app->useStoragePath($this->isolatedStoragePath);

        $this->storageRoot = $this->isolatedStoragePath . '/widget-artifact-blobs';

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return $onAcquired();
            }
        });

        // Cms\Widget\Instances\WidgetInstanceService::widgetModuleEnabled()
        // consults the REAL Nwidart module-status activator (the same
        // signal production disable/enable uses) so the "disable wins"
        // race is genuinely exercised. "Widget" is not yet a formally
        // activated module in modules_statuses.json (Chunk 2 is still
        // mid-rollout), so the happy-path default here is ACTIVE, mirroring
        // every other already-shipped module's normal state; individual
        // tests flip it to simulate a concurrent disable via
        // setWidgetModuleEnabled(false). FileActivator caches its statuses
        // array at construction and persists mutations back to the real
        // base_path('modules_statuses.json') (Module::__construct() resolves
        // the activator singleton once, early in bootstrap, so a later
        // container rebind here would not reach the already-constructed
        // 'Widget' Module object) -- the pristine pre-suite content is
        // protected by setUpBeforeClass()/tearDownAfterClass() instead of a
        // per-method capture (see that method's own docblock).
        $this->setWidgetModuleEnabled(true);

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->storageRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->storageRoot);
        }

        if (isset($this->isolatedStoragePath)) {
            \Illuminate\Support\Facades\File::deleteDirectory($this->isolatedStoragePath);
        }

        // modules_statuses.json and database/seeders/exported/*.php are
        // restored ONCE for the whole class in tearDownAfterClass(), not
        // here -- see setUpBeforeClass()'s docblock.

        parent::tearDown();
    }

    /**
     * Flips the REAL module-status activator's "Widget" entry, persisted to
     * the real modules_statuses.json (restored once for the whole class in
     * tearDownAfterClass()) -- see setUp()'s own docblock for why a
     * container rebind cannot reach this instead.
     */
    private function setWidgetModuleEnabled(bool $enabled): void
    {
        app(ActivatorInterface::class)->setActiveByName('Widget', $enabled);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function createWidget(string $js = ''): Widget
    {
        return Widget::query()->create(['name' => 'Test Widget', 'description' => '', 'js' => $js]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTheme(Widget $widget, array $overrides = []): WidgetTheme
    {
        return WidgetTheme::query()->create(array_merge([
            'widget_id' => $widget->id,
            'name' => 'Test Theme',
            'content' => '<div class="card">{title}</div>',
            'css' => '.card { color: red; }',
            'project_name' => [self::PROJECT],
        ], $overrides));
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Instance Tester',
            'email' => 'instance-tester-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
            'type' => $type,
        ]);

        if ($permissionKeys === []) {
            return $user;
        }

        // Deliberately NOT $this->seed(...) -- see class docblock.
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
            'widget_instance.viewAny',
            'widget_instance.view',
            'widget_instance.create',
            'widget_instance.update',
            'widget_instance.delete',
            'widget_template.approve',
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

    /**
     * Materializes AND approves a Manifest for (widget, theme) inside
     * self::PROJECT, so an Instance create/update/clone against it can
     * pass the gate. Mirrors WidgetManifestMaterializationApiTest's
     * liveSourceFingerprint() plus the approval half from
     * {@see WidgetManifestApprovalService}.
     */
    private function approveManifestFor(Widget $widget, WidgetTheme $theme, User $approver): WidgetArtifactManifestModel
    {
        $this->liveSourceFingerprint($widget, $theme); // warms nothing; kept for symmetry/documentation of intent

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
            ]);
        $response->assertSuccessful();
        $manifestHash = $response->json('data.manifest_hash');

        app(WidgetManifestApprovalService::class)->approve(
            self::PROJECT,
            $manifestHash,
            1,
            (int) $approver->id,
            'test approval',
        );

        return WidgetArtifactManifestModel::query()
            ->where('project_name', self::PROJECT)
            ->where('manifest_hash', $manifestHash)
            ->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Create: happy path
    // -----------------------------------------------------------------

    public function test_create_returns_201_with_a_ready_version_one_instance(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $this->approveManifestFor($widget, $theme, $user);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'create-key-1'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'My Instance',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Hello'],
                'provider_contract' => [],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'My Instance')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.project_name', self::PROJECT)
            ->assertJsonPath('data.sync_status', 'ready')
            ->assertJsonPath('data.current_version.version', 1)
            ->assertJsonPath('data.current_version.widget_id', $widget->id)
            ->assertJsonPath('data.current_version.widget_theme_id', $theme->id)
            ->assertJsonStructure(['data' => ['uuid', 'project_name', 'name', 'status', 'current_version', 'sync_status', 'created_at', 'updated_at']]);

        $this->assertSame(1, WidgetInstance::query()->count());
        $this->assertSame(1, WidgetInstanceVersion::query()->count());

        $instance = WidgetInstance::query()->first();
        $this->assertNotNull($instance->current_version_id, 'The current pointer must be set only after the Version is committed.');
    }

    // -----------------------------------------------------------------
    // Create: idempotency
    // -----------------------------------------------------------------

    public function test_create_replays_the_same_result_for_a_repeated_idempotency_key_and_payload(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $this->approveManifestFor($widget, $theme, $user);

        $payload = [
            'name' => 'Replay Instance',
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'values' => [],
            'provider_contract' => [],
        ];

        $first = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'replay-key-1'])
            ->postJson('/api/v2/admin/widget/instances', $payload);
        $first->assertCreated();

        $second = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'replay-key-1'])
            ->postJson('/api/v2/admin/widget/instances', $payload);

        $second->assertCreated()
            ->assertJsonPath('data.uuid', $first->json('data.uuid'));
        $this->assertSame(1, WidgetInstance::query()->count(), 'A replay must never insert a second Instance.');
        $this->assertSame(1, WidgetInstanceVersion::query()->count());
    }

    public function test_create_returns_409_idempotency_mismatch_for_the_same_key_with_a_different_payload(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $this->approveManifestFor($widget, $theme, $user);

        $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'conflict-key-1'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'First Name',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ])
            ->assertCreated();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'conflict-key-1'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'A Completely Different Name',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_IDEMPOTENCY_MISMATCH');
        $this->assertSame(1, WidgetInstance::query()->count());
    }

    // -----------------------------------------------------------------
    // Create: permission / domain validation
    // -----------------------------------------------------------------

    public function test_create_denies_a_user_without_the_create_permission(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'perm-key-1'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'Nope',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ]);

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
        $this->assertSame(0, WidgetInstance::query()->count());
    }

    public function test_create_returns_422_for_an_unknown_widget_theme_pairing(): void
    {
        $user = $this->fullAccessUser();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'invalid-pairing-key'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'Nope',
                'widget_id' => 999999,
                'widget_theme_id' => 888888,
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_SOURCE_INVALID');
        $this->assertSame(0, WidgetInstance::query()->count());
    }

    public function test_create_approves_a_pending_manifest_automatically_for_the_acting_user(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();

        // Materialize but never approve: placing the widget approves it (Unified Widget Catalog Part 2).
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
            ])
            ->assertCreated();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'pending-approval-key'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'Auto approved',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ]);

        $response->assertCreated();
        $this->assertSame(1, WidgetInstance::query()->count());
        $this->assertSame(0, WidgetIdempotencyKey::query()->where('state', WidgetIdempotencyKey::STATE_PROCESSING)->count());
    }

    // -----------------------------------------------------------------
    // Show / list
    // -----------------------------------------------------------------

    public function test_show_returns_the_instance_by_uuid(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $this->approveManifestFor($widget, $theme, $user);

        $created = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'show-key-1'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'Showable',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ])
            ->assertCreated();
        $uuid = $created->json('data.uuid');

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v2/admin/widget/instances/{$uuid}");

        $response->assertOk()->assertJsonPath('data.uuid', $uuid)->assertJsonPath('data.name', 'Showable');
    }

    public function test_show_returns_404_for_an_unknown_uuid(): void
    {
        $user = $this->fullAccessUser();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/instances/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    }

    public function test_index_lists_instances_for_the_authorized_project_paginated(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $this->approveManifestFor($widget, $theme, $user);

        foreach (['A', 'B', 'C'] as $suffix) {
            $this->actingAs($user, 'sanctum')
                ->withHeaders(['Idempotency-Key' => 'list-key-' . $suffix])
                ->postJson('/api/v2/admin/widget/instances', [
                    'name' => 'Listed ' . $suffix,
                    'widget_id' => $widget->id,
                    'widget_theme_id' => $theme->id,
                ])
                ->assertCreated();
        }

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/instances');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_denies_a_user_without_the_view_any_permission(): void
    {
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')->getJson('/api/v2/admin/widget/instances');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    // -----------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------

    private function createReadyInstance(Widget $widget, WidgetTheme $theme, User $user, string $name = 'Base Instance', string $idempotencyKey = 'base-key-1'): string
    {
        $this->approveManifestFor($widget, $theme, $user);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => $name,
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Original'],
            ]);
        $response->assertCreated();

        return $response->json('data.uuid');
    }

    public function test_update_creates_version_two_and_moves_the_pointer(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $uuid = $this->createReadyInstance($widget, $theme, $user);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'update-key-1'])
            ->patchJson("/api/v2/admin/widget/instances/{$uuid}", [
                'expected_current_version' => 1,
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Updated'],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.current_version.version', 2)
            ->assertJsonPath('data.current_version.values.title', 'Updated');

        $instance = WidgetInstance::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(2, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count());
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->where('version', 1)->first()->values['title'] === 'Original' ? 1 : 0, 'Version 1 must remain unchanged (immutable history).');
    }

    public function test_update_returns_409_when_expected_current_version_is_stale(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $uuid = $this->createReadyInstance($widget, $theme, $user);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'stale-update-key'])
            ->patchJson("/api/v2/admin/widget/instances/{$uuid}", [
                'expected_current_version' => 999, // stale/wrong
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'Should Not Apply'],
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_USAGE_CHANGED');

        $instance = WidgetInstance::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(), 'A stale update must insert no new Version.');
    }

    public function test_update_returns_404_for_an_unknown_uuid(): void
    {
        $user = $this->fullAccessUser();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'unknown-update-key'])
            ->patchJson('/api/v2/admin/widget/instances/00000000-0000-0000-0000-000000000000', [
                'expected_current_version' => 1,
                'widget_id' => 1,
                'widget_theme_id' => 1,
            ]);

        $response->assertStatus(404);
    }

    public function test_two_standalone_updates_with_different_idempotency_keys_allocate_sequential_versions(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $uuid = $this->createReadyInstance($widget, $theme, $user);

        $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'seq-key-1'])
            ->patchJson("/api/v2/admin/widget/instances/{$uuid}", [
                'expected_current_version' => 1,
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'V2'],
            ])
            ->assertOk()
            ->assertJsonPath('data.current_version.version', 2);

        $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'seq-key-2'])
            ->patchJson("/api/v2/admin/widget/instances/{$uuid}", [
                'expected_current_version' => 2,
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'values' => ['title' => 'V3'],
            ])
            ->assertOk()
            ->assertJsonPath('data.current_version.version', 3);

        $instance = WidgetInstance::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(3, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count());
    }

    public function test_changing_widget_definition_on_update_creates_a_new_version_and_preserves_old_version_meaning(): void
    {
        $widgetA = $this->createWidget();
        $themeA = $this->createTheme($widgetA);
        $widgetB = $this->createWidget();
        $themeB = $this->createTheme($widgetB);
        $user = $this->fullAccessUser();

        $this->approveManifestFor($widgetA, $themeA, $user);
        $this->approveManifestFor($widgetB, $themeB, $user);

        $uuid = $this->createReadyInstance($widgetA, $themeA, $user, 'Switchable', 'switch-create-key');

        $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'switch-update-key'])
            ->patchJson("/api/v2/admin/widget/instances/{$uuid}", [
                'expected_current_version' => 1,
                'widget_id' => $widgetB->id,
                'widget_theme_id' => $themeB->id,
                'values' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.current_version.version', 2)
            ->assertJsonPath('data.current_version.widget_id', $widgetB->id);

        $instance = WidgetInstance::query()->where('uuid', $uuid)->firstOrFail();
        $versionOne = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->where('version', 1)->firstOrFail();
        $versionTwo = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->where('version', 2)->firstOrFail();

        $this->assertSame($widgetA->id, $versionOne->widget_id, 'Old Version history must retain its original Widget pin (immutability).');
        $this->assertSame($widgetB->id, $versionTwo->widget_id);
    }

    // -----------------------------------------------------------------
    // Delete / Archive
    // -----------------------------------------------------------------

    public function test_delete_soft_deletes_an_unused_instance(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $uuid = $this->createReadyInstance($widget, $theme, $user);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v2/admin/widget/instances/{$uuid}");

        $response->assertOk();
        $this->assertSoftDeleted(WidgetInstance::query()->withTrashed()->where('uuid', $uuid)->firstOrFail());
    }

    public function test_delete_returns_409_when_the_instance_is_placed(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $uuid = $this->createReadyInstance($widget, $theme, $user);

        $instance = WidgetInstance::query()->where('uuid', $uuid)->firstOrFail();
        $this->placeInstanceVersion($instance);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v2/admin/widget/instances/{$uuid}");

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_INSTANCE_IN_USE');
        $this->assertNotSoftDeleted(WidgetInstance::query()->where('uuid', $uuid)->firstOrFail());
    }

    public function test_archive_sets_status_to_archived(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $uuid = $this->createReadyInstance($widget, $theme, $user);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v2/admin/widget/instances/{$uuid}/archive");

        $response->assertOk()->assertJsonPath('data.status', 'archived');
    }

    // -----------------------------------------------------------------
    // Clone
    // -----------------------------------------------------------------

    public function test_clone_creates_a_distinct_identity_with_the_source_current_values(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $uuid = $this->createReadyInstance($widget, $theme, $user, 'Source Instance', 'clone-source-key');

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'clone-key-1'])
            ->postJson("/api/v2/admin/widget/instances/{$uuid}/clone", ['name' => 'Cloned Instance']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Cloned Instance')
            ->assertJsonPath('data.current_version.version', 1)
            ->assertJsonPath('data.current_version.values.title', 'Original');

        $this->assertNotSame($uuid, $response->json('data.uuid'));
        $this->assertSame(2, WidgetInstance::query()->count());
    }

    // -----------------------------------------------------------------
    // Usage
    // -----------------------------------------------------------------

    public function test_usage_returns_a_summary_with_an_opaque_fingerprint(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $uuid = $this->createReadyInstance($widget, $theme, $user);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v2/admin/widget/instances/{$uuid}/usage");

        $response->assertOk()
            ->assertJsonPath('data.in_use', false)
            ->assertJsonPath('data.placement_count', 0)
            ->assertJsonStructure(['data' => ['in_use', 'placement_count', 'usage_fingerprint']]);
        $this->assertIsString($response->json('data.usage_fingerprint'));
        $this->assertNotSame('0', $response->json('data.usage_fingerprint'));
    }

    // -----------------------------------------------------------------
    // Module lifecycle barrier
    // -----------------------------------------------------------------

    public function test_create_returns_409_module_in_use_when_the_widget_module_is_disabled(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $this->approveManifestFor($widget, $theme, $user);

        $this->setWidgetModuleEnabled(false);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'disabled-create-key'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'Should Not Be Created',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_MODULE_IN_USE');
        $this->assertSame(0, WidgetInstance::query()->count(), 'Disable-wins must create no Instance.');
        $this->assertSame(0, WidgetIdempotencyKey::query()->where('state', WidgetIdempotencyKey::STATE_PROCESSING)->count(), 'The claim must be released, not left processing.');
    }

    public function test_update_returns_409_module_in_use_when_the_widget_module_is_disabled(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $uuid = $this->createReadyInstance($widget, $theme, $user);

        $this->setWidgetModuleEnabled(false);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'disabled-update-key'])
            ->patchJson("/api/v2/admin/widget/instances/{$uuid}", [
                'expected_current_version' => 1,
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_MODULE_IN_USE');

        $instance = WidgetInstance::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(1, WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(), 'Disable-wins must create no new Version.');
    }

    // -----------------------------------------------------------------
    // MaintenanceOnly-era complementary windows (coordinator-adjudicated
    // Task 16 rider): live HTTP mutation proceeds IFF the application is
    // NOT down for maintenance; the disable side's window is console +
    // maintenance (MaintenanceOnlyModuleLifecycleBarrier as-is). The two
    // windows are disjoint, so "disable-wins => no Version created" holds
    // without routing live HTTP through the refuse-every-non-console
    // barrier (which would have made the whole API dead on arrival).
    // Console mutation callers keep going through barrier->transition()
    // VERBATIM. The isRunningInConsole ReflectionProperty flip below is
    // the established precedent from
    // cms/Core/tests/Feature/Modules/ModuleDisableGuardTest.php, which
    // simulates a php-fpm worker inside the cli-SAPI test process.
    // -----------------------------------------------------------------

    public function test_http_context_create_succeeds_outside_maintenance_without_consulting_the_barrier(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $this->approveManifestFor($widget, $theme, $user);

        // Bind the REAL refuse-every-non-console barrier in place of
        // setUp()'s pass-through double: if the HTTP path (wrongly)
        // consulted the barrier at all, this request would answer 409
        // MODULE_MAINTENANCE_REQUIRED-driven WIDGET_MODULE_IN_USE; the
        // complement must succeed without ever reaching it.
        $this->app->singleton(ModuleLifecycleBarrier::class, MaintenanceOnlyModuleLifecycleBarrier::class);

        $consoleFlag = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'isRunningInConsole');

        try {
            $consoleFlag->setValue($this->app, false);

            $response = $this->actingAs($user, 'sanctum')
                ->withHeaders(['Idempotency-Key' => 'http-live-create-key'])
                ->postJson('/api/v2/admin/widget/instances', [
                    'name' => 'Live HTTP Instance',
                    'widget_id' => $widget->id,
                    'widget_theme_id' => $theme->id,
                ]);
        } finally {
            $consoleFlag->setValue($this->app, true);
        }

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Live HTTP Instance')
            ->assertJsonPath('data.current_version.version', 1);
        $this->assertSame(1, WidgetInstance::query()->count());
    }

    public function test_http_context_create_is_refused_with_module_in_use_during_maintenance(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $this->approveManifestFor($widget, $theme, $user);

        // Down-for-maintenance marker in the ISOLATED storage root (setUp
        // ran useStoragePath), deleted with it in tearDown().
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('framework'));
        \Illuminate\Support\Facades\File::put(
            storage_path('framework/down'),
            json_encode(['time' => time()], JSON_THROW_ON_ERROR)
        );
        $this->assertTrue($this->app->isDownForMaintenance());

        // Simulate the `artisan down --secret` bypass cookie's effect: the
        // request passes the maintenance middleware and reaches the
        // controller -- the exact hole the service-level check closes.
        $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);

        $consoleFlag = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'isRunningInConsole');

        try {
            $consoleFlag->setValue($this->app, false);

            $response = $this->actingAs($user, 'sanctum')
                ->withHeaders(['Idempotency-Key' => 'maintenance-create-key'])
                ->postJson('/api/v2/admin/widget/instances', [
                    'name' => 'Should Not Be Created',
                    'widget_id' => $widget->id,
                    'widget_theme_id' => $theme->id,
                ]);
        } finally {
            $consoleFlag->setValue($this->app, true);
        }

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_MODULE_IN_USE');
        $this->assertSame(0, WidgetInstance::query()->count(), 'The disable window must create no Instance.');
        $this->assertSame(0, WidgetIdempotencyKey::query()->where('state', WidgetIdempotencyKey::STATE_PROCESSING)->count(), 'The claim must be released, not left processing.');
    }

    public function test_console_context_create_still_routes_through_the_lifecycle_barrier(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $this->approveManifestFor($widget, $theme, $user);

        // A barrier double that REFUSES without invoking the callback:
        // the console path (PHPUnit's own cli SAPI, no flag flip) must
        // still consult barrier->transition() verbatim, so this refusal
        // must surface as the frozen module conflict.
        $this->app->singleton(ModuleLifecycleBarrier::class, fn () => new class implements ModuleLifecycleBarrier {
            public function transition(string $moduleName, callable $onAcquired, bool $maintenanceConfirmed = false): ?string
            {
                return 'MODULE_MAINTENANCE_REQUIRED';
            }
        });

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'console-barrier-key'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'Should Not Be Created',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_MODULE_IN_USE');
        $this->assertSame(0, WidgetInstance::query()->count());
    }

    public function test_core_widget_destroy_is_refused_during_maintenance_over_http(): void
    {
        // No retained reference: outside maintenance this destroy would
        // succeed (proven by test_core_widget_destroy_succeeds_...); the
        // ONLY blocker here is the maintenance window.
        $widget = $this->createWidget();
        $legacyAdmin = $this->legacyWidgetAdminUser();

        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('framework'));
        \Illuminate\Support\Facades\File::put(
            storage_path('framework/down'),
            json_encode(['time' => time()], JSON_THROW_ON_ERROR)
        );
        $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);

        $consoleFlag = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'isRunningInConsole');

        try {
            $consoleFlag->setValue($this->app, false);

            $response = $this->actingAs($legacyAdmin, 'sanctum')->deleteJson("/api/v2/admin/widget/{$widget->id}");
        } finally {
            $consoleFlag->setValue($this->app, true);
        }

        $response->assertStatus(409)->assertJsonPath('error_code', 'WIDGET_MODULE_IN_USE');
        $this->assertNotNull(Widget::query()->find($widget->id), 'The Widget must survive a maintenance-window destroy attempt.');
    }

    public function test_core_theme_destroy_is_refused_during_maintenance_over_http(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $legacyAdmin = $this->legacyWidgetAdminUser();

        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('framework'));
        \Illuminate\Support\Facades\File::put(
            storage_path('framework/down'),
            json_encode(['time' => time()], JSON_THROW_ON_ERROR)
        );
        $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);

        $consoleFlag = new \ReflectionProperty(\Illuminate\Foundation\Application::class, 'isRunningInConsole');

        try {
            $consoleFlag->setValue($this->app, false);

            $response = $this->actingAs($legacyAdmin, 'sanctum')->deleteJson("/api/v2/admin/widget/{$widget->id}/template/{$theme->id}");
        } finally {
            $consoleFlag->setValue($this->app, true);
        }

        $response->assertStatus(409)->assertJsonPath('error_code', 'WIDGET_MODULE_IN_USE');
        $this->assertNotNull(WidgetTheme::query()->find($theme->id), 'The Theme must survive a maintenance-window destroy attempt.');
    }

    // -----------------------------------------------------------------
    // Emergency-blocked Manifest
    // -----------------------------------------------------------------

    public function test_create_returns_409_when_the_manifest_is_emergency_blocked(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $user = $this->fullAccessUser();
        $manifest = $this->approveManifestFor($widget, $theme, $user);
        $manifest->forceFill(['blocked' => true])->save();

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'blocked-create-key'])
            ->postJson('/api/v2/admin/widget/instances', [
                'name' => 'Should Not Be Created',
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_MANIFEST_RUNTIME_BLOCKED');
        $this->assertSame(0, WidgetInstance::query()->count());
    }

    // -----------------------------------------------------------------
    // Module-boundary integration: Core's legacy Widget/Theme destroy
    // paths must return a typed 409 with usage summary BEFORE any FK
    // failure when a retained Instance Version references them (plan
    // checklist; architecture.md "Foreign keys and deletion"). Core never
    // queries a Cms\Widget\* table directly -- these prove the end-to-end
    // behavior through the real HTTP routes exactly as production would
    // exercise them.
    // -----------------------------------------------------------------

    /**
     * Legacy widget/widget_theme permissions are Core's own convention
     * (group = singular table name), seeded ad-hoc here exactly like
     * cms/Core/tests/Feature/Security/WidgetTrustBoundaryTest.php's
     * beforeEach() does -- no dedicated seeder exists for them the way
     * WidgetPermissionTableSeeder covers the NEW widget_instance/
     * widget_layout/widget_template groups.
     */
    private function legacyWidgetAdminUser(): User
    {
        $user = User::forceCreate([
            'name' => 'Legacy Widget Admin',
            'email' => 'legacy-widget-admin-' . uniqid('', true) . '@test.local',
            'password' => bcrypt('secret'),
            'type' => UserType::ADMIN,
        ]);

        $permissionIds = [];
        foreach (['widget' => ['update', 'delete', 'trust'], 'widget_theme' => ['update', 'delete', 'trust']] as $group => $actions) {
            foreach ($actions as $action) {
                $permissionIds[] = Permission::query()->firstOrCreate(
                    ['group' => $group, 'name' => $action],
                    ['title' => $group . ' ' . $action],
                )->id;
            }
        }

        $role = Role::query()->create(['name' => 'Legacy Widget Admin Role ' . uniqid('', true), 'custom' => ['level' => 50]]);
        $role->permissions()->attach($permissionIds);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    public function test_core_widget_destroy_returns_409_definition_in_use_when_referenced_by_a_retained_instance_version(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceUser = $this->fullAccessUser();
        $this->createReadyInstance($widget, $theme, $instanceUser, 'Referencing Instance', 'ref-key-1');

        $legacyAdmin = $this->legacyWidgetAdminUser();

        $response = $this->actingAs($legacyAdmin, 'sanctum')->deleteJson("/api/v2/admin/widget/{$widget->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'WIDGET_DEFINITION_IN_USE')
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('data.retained_version_count', 1)
            ->assertJsonStructure(['data' => ['table', 'id', 'retained_version_count', 'usage_fingerprint']]);

        $this->assertNotNull(Widget::query()->find($widget->id), 'The referenced Widget must not be deleted.');
    }

    public function test_core_widget_destroy_succeeds_when_no_instance_version_references_it(): void
    {
        $widget = $this->createWidget();
        $legacyAdmin = $this->legacyWidgetAdminUser();

        $response = $this->actingAs($legacyAdmin, 'sanctum')->deleteJson("/api/v2/admin/widget/{$widget->id}");

        $response->assertOk();
        $this->assertNull(Widget::query()->find($widget->id));
    }

    public function test_core_theme_destroy_returns_409_template_in_use_when_referenced_by_a_retained_instance_version(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceUser = $this->fullAccessUser();
        $this->createReadyInstance($widget, $theme, $instanceUser, 'Referencing Instance', 'ref-key-2');

        $legacyAdmin = $this->legacyWidgetAdminUser();

        $response = $this->actingAs($legacyAdmin, 'sanctum')->deleteJson("/api/v2/admin/widget/{$widget->id}/template/{$theme->id}");

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'WIDGET_TEMPLATE_IN_USE')
            ->assertJsonPath('data.retained_version_count', 1);

        $this->assertNotNull(WidgetTheme::query()->find($theme->id), 'The referenced Theme must not be deleted.');
    }

    public function test_core_widget_update_is_not_blocked_by_a_retained_instance_version(): void
    {
        // Ordinary field edits stay future-only per architecture.md: the
        // retained-reference guard must never block update(), only
        // destroy() -- proven by successfully renaming a referenced Widget.
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $instanceUser = $this->fullAccessUser();
        $this->createReadyInstance($widget, $theme, $instanceUser, 'Referencing Instance', 'ref-key-3');

        $legacyAdmin = $this->legacyWidgetAdminUser();

        $response = $this->actingAs($legacyAdmin, 'sanctum')
            ->putJson("/api/v2/admin/widget/{$widget->id}", [
                'name' => 'Renamed While Referenced',
                'description' => '',
                'js' => '',
                // Opt into the staged-deletion contract with an empty set so
                // the existing (untouched) Theme survives without resending
                // it — this test is about the retained-reference guard, not
                // template payload mechanics.
                'deleted_template_ids' => [],
            ]);

        $response->assertOk();
        $this->assertSame('Renamed While Referenced', Widget::query()->find($widget->id)->name);
    }

    // -----------------------------------------------------------------
    // Placement fixture helper
    // -----------------------------------------------------------------

    private function placeInstanceVersion(WidgetInstance $instance): void
    {
        $layout = \Cms\Widget\Models\WidgetLayout::query()->create([
            'project_name' => $instance->project_name,
            'key' => 'site',
        ]);

        $layoutVersion = \Cms\Widget\Models\WidgetLayoutVersion::query()->create([
            'layout_id' => $layout->id,
            'project_name' => $instance->project_name,
            'version' => 1,
            'state' => 'draft',
            'registry_contracts' => [],
            'registry_contract_hash' => hash('sha256', '[]'),
        ]);

        \Cms\Widget\Models\WidgetPlacement::query()->create([
            'project_name' => $instance->project_name,
            'layout_version_id' => $layoutVersion->id,
            'widget_instance_version_id' => $instance->current_version_id,
            'slot_key' => 'site_header_before',
            'scope_type' => 'global',
            'scope_identity' => 'global',
            'position' => 0,
            'enabled' => true,
        ]);
    }
}
