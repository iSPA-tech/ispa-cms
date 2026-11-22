<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Http;

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Chunk 2 Task 15a: `POST /api/v2/admin/widget/catalog/manifests/materialize`
 * (docs/contracts/widget-site-layout-api.md "Catalog (split semantics)";
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json materialize
 * route entry). The FIRST real HTTP surface in this module.
 *
 * Boots the real WidgetServiceProvider (so route registration is exercised
 * faithfully -- register() already transitively maps
 * cms/Widget/routes/api.php through the nested RouteServiceProvider) and
 * rebinds FilesystemWidgetArtifactBlobStore to an isolated temporary root
 * AFTER boot: this endpoint genuinely writes Blob bytes, so it must never
 * touch the shared production `storage_path('app/widget_artifacts')`
 * directory during tests.
 *
 * IMPORTANT (found via TDD iteration on this exact file): fixtures in this
 * class deliberately call `(new WidgetPermissionTableSeeder())->run()`
 * directly rather than the usual `$this->seed(...)` helper.
 * `TestCase::seed()` dispatches through `Artisan::call('db:seed', ...)`,
 * and running ANY Artisan command here eagerly resolves and permanently
 * caches this module's `WidgetArtifactBlobRepository` singleton (with
 * whatever `FilesystemWidgetArtifactBlobStore` binding was active at that
 * moment) -- silently defeating a LATER per-test rebind of the storage
 * root (observed: a rebind to a broken path was correctly picked up by a
 * direct `app(FilesystemWidgetArtifactBlobStore::class)` call, yet the
 * ACTUAL HTTP-dispatched write still went to the earlier good path,
 * because `DatabaseWidgetArtifactBlobRepository` had already been built
 * and cached with the old store instance). Direct seeder instantiation
 * avoids the Artisan console dispatch entirely and keeps the rebind honest.
 */
final class WidgetManifestMaterializationApiTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    /**
     * See WidgetPermissionSeederTest::beforeRefreshingDatabase() (Task 14):
     * this test also needs the root/Core widgets/widget_themes/users/
     * permissions/roles tables, so it deliberately does NOT override
     * migrateFreshUsing() with an explicit `--path`.
     */
    protected function beforeRefreshingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // WidgetProjectContextResolver resolves config('ispa-cms.folder_name')
        // as the server-derived project. Pinned explicitly rather than
        // relying on the package default ('Default') or whatever a local
        // .env happens to set (observed to differ across environments) --
        // every fixture/assertion below uses this SAME constant.
        config(['ispa-cms.folder_name' => 'ExampleProject']);

        $this->storageRoot = storage_path('framework/testing/widget-manifest-api-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        // Mirrors AchievementAdminApiTest: strip noise middleware, keep
        // auth:sanctum + api.admin ACTIVE so the WEBMASTER/ADMIN user-type
        // gate is real, and let the controller's own widget_template.approve
        // check decide 200/201 vs 403 for the permission tests below.
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

        parent::tearDown();
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
            // Must match the pinned config('ispa-cms.folder_name') in
            // setUp() -- the fixture's default applicability has to match
            // the server-derived project for the happy path, or every
            // "applicable" call would spuriously 422.
            'project_name' => ['ExampleProject'],
        ], $overrides));
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Materialize Tester',
            'email' => 'materialize-tester-' . uniqid('', true) . '@test.local',
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

    private function approverUser(): User
    {
        return $this->userOfType(UserType::ADMIN, ['widget_template.approve']);
    }

    // ===================================================================
    // Authentication / api.admin gate / permission
    // ===================================================================

    public function test_materialize_requires_authentication(): void
    {
        $response = $this->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
            'widget_id' => 1,
            'widget_theme_id' => 1,
            'expected_source_fingerprint' => 'anything',
        ]);

        $response->assertUnauthorized();
    }

    public function test_materialize_denies_a_client_type_user_at_the_api_admin_gate(): void
    {
        $client = $this->userOfType(UserType::CLIENT);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => 1,
                'widget_theme_id' => 1,
                'expected_source_fingerprint' => 'anything',
            ]);

        // ApiAdminVerifyMiddleware (api.admin) returns ires()->unauthorized()
        // for a non-WEBMASTER/ADMIN user type, which is a 401, not a 403 --
        // this route-level gate is distinct from this controller's own
        // widget_template.approve check (proven as a real 403 below).
        $response->assertUnauthorized();
    }

    public function test_materialize_denies_an_admin_type_user_without_the_approve_permission(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => 'anything',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id']);
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count());
    }

    // ===================================================================
    // Precedent: permission (403) is decided BEFORE request-shape
    // validation (422). This is the first module controller; the ordering
    // it sets is what every 15b 403 surface copies.
    // ===================================================================

    public function test_no_permission_plus_malformed_body_returns_403_not_422(): void
    {
        // A user lacking widget_template.approve, sending a body that would
        // ALSO fail shape validation (no widget_id): the permission denial
        // must win -- authorize() runs before rules() validation.
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                // widget_id deliberately omitted (would be a 422 if reached)
                'widget_theme_id' => 'not-an-integer',
                'project_name' => 'SomeProject',
            ]);

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_has_permission_plus_malformed_body_returns_422(): void
    {
        // The SAME malformed body from a PERMITTED user now reaches shape
        // validation and returns 422 -- proving the 403 above was the
        // permission gate, not a coincidental shape failure.
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_theme_id' => 'not-an-integer',
                'project_name' => 'SomeProject',
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED');
    }

    // ===================================================================
    // Happy path: 201 created, 200 reused
    // ===================================================================

    public function test_materialize_creates_a_new_pending_manifest_and_returns_201(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $approver = $this->approverUser();

        $expected = $this->liveSourceFingerprint($widget, $theme);

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => $expected,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.eligibility_state', 'pending')
            ->assertJsonPath('data.attestation_version', 1)
            ->assertJsonPath('data.project_name', 'ExampleProject')
            ->assertJsonStructure(['data' => [
                'manifest_hash', 'project_name', 'eligibility_state', 'attestation_version',
                'policy_version', 'trust_lane', 'control_version', 'blocked',
                'approved_by', 'approved_at', 'revoked_by', 'revoked_at',
            ]]);

        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count());
    }

    public function test_materialize_returns_200_for_exact_reuse(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $approver = $this->approverUser();

        $payload = [
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
        ];

        $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', $payload)
            ->assertCreated();

        $second = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', $payload);

        $second->assertOk();
        $this->assertSame(1, WidgetArtifactManifestModel::query()->count(), 'Exact reuse must never create a duplicate row.');
    }

    // ===================================================================
    // Request-shape validation
    // ===================================================================

    public function test_materialize_rejects_a_request_carrying_a_project_selector(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => 'anything',
                'project_name' => 'SomeProject',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id', 'details']);
        $this->assertArrayHasKey('project_name', $response->json('details'));
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count());
    }

    public function test_materialize_rejects_a_missing_required_field(): void
    {
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_theme_id' => 1,
                'expected_source_fingerprint' => 'anything',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('widget_id', $response->json('details'));
    }

    // ===================================================================
    // Domain validation (422): invalid pairing, not applicable, stale
    // ===================================================================

    public function test_materialize_returns_422_for_an_unknown_widget_theme_pairing(): void
    {
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => 999999,
                'widget_theme_id' => 888888,
                'expected_source_fingerprint' => 'anything',
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_SOURCE_INVALID');
    }

    public function test_materialize_returns_422_when_the_theme_is_not_applicable_to_the_authorized_project(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['project_name' => ['SomeOtherProject']]);
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => 'anything',
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_SOURCE_INVALID');
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count());
    }

    public function test_materialize_returns_422_for_a_stale_expected_source_fingerprint(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                'widget_id' => $widget->id,
                'widget_theme_id' => $theme->id,
                'expected_source_fingerprint' => 'definitely-not-the-real-fingerprint',
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_SOURCE_INVALID');
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count());
    }

    // ===================================================================
    // 503 build/storage failure
    // ===================================================================

    public function test_materialize_returns_503_on_a_storage_failure(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $approver = $this->approverUser();

        // A storage root that is a plain FILE (not a directory) makes the
        // Filesystem Blob store throw a genuine RuntimeException.
        $brokenRoot = $this->storageRoot . '-broken-file';
        file_put_contents($brokenRoot, 'not a directory');
        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            static fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($brokenRoot)
        );

        try {
            $response = $this->actingAs($approver, 'sanctum')
                ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                    'widget_id' => $widget->id,
                    'widget_theme_id' => $theme->id,
                    'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
                ]);
        } finally {
            @unlink($brokenRoot);
        }

        $response->assertStatus(503)
            ->assertJsonPath('code', 'WIDGET_MANIFEST_MATERIALIZATION_FAILED')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id']);
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count());
    }

    public function test_the_503_correlation_id_is_recorded_server_side_with_safe_context(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $approver = $this->approverUser();

        // Spy on the log so we can assert the client-visible correlation_id
        // was actually recorded server-side (with safe context, never source
        // bytes) -- the whole point of minting the id BEFORE responding.
        Log::spy();

        $brokenRoot = $this->storageRoot . '-broken-file';
        file_put_contents($brokenRoot, 'not a directory');
        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            static fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($brokenRoot)
        );

        try {
            $response = $this->actingAs($approver, 'sanctum')
                ->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
                    'widget_id' => $widget->id,
                    'widget_theme_id' => $theme->id,
                    'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
                ]);
        } finally {
            @unlink($brokenRoot);
        }

        $response->assertStatus(503);
        $correlationId = $response->json('correlation_id');
        $this->assertNotEmpty($correlationId);

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) use ($correlationId, $widget, $theme): bool {
                return ($context['correlation_id'] ?? null) === $correlationId
                    && ($context['widget_id'] ?? null) === $widget->id
                    && ($context['widget_theme_id'] ?? null) === $theme->id
                    && ($context['project_name'] ?? null) === 'ExampleProject';
            })
            ->atLeast()->once();
    }

    // ===================================================================
    // Static route ordering (checklist item 6)
    // ===================================================================

    public function test_materialize_is_registered_as_a_literal_static_path_segment(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v2/admin/widget/catalog/manifests/materialize');

        $this->assertNotNull($route, 'The materialize route must be registered.');
        $this->assertStringNotContainsString('{', $route->uri(), 'materialize must be a literal static path segment, never a wildcard.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

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
}
