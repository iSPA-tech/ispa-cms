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
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Chunk 2 Task 22: catalogs, history, and health APIs
 * (lines
 * 1181-1216; cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * `/slots`, `/target-contexts`, `/catalog/definitions`,
 * `/catalog/definitions/{widget}`, `/health`, and
 * `/layouts/{uuid}/releases` route entries).
 *
 * Every assertion below matches the frozen fixture's own declared
 * `statuses` for its route exactly (the standing conformance rule already
 * enforced across Tasks 19-21): 200/403 for the plain catalog reads, plus
 * 404 where the fixture allows it (definitions detail, releases).
 *
 * No artifact/materialization storage isolation is needed for the Slot/
 * Target Context/health/releases sections (they never touch a Blob file);
 * the Definition catalog section below DOES isolate storage because it
 * exercises the real `WidgetLegacyManifestMaterializer` fingerprinting
 * path, mirroring `WidgetManifestMaterializationApiTest`'s own precedent.
 */
final class WidgetCatalogAndHealthApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'ExampleProject';

    private string $storageRoot;

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

        config(['ispa-cms.folder_name' => self::PROJECT]);

        $this->storageRoot = storage_path('framework/testing/widget-catalog-api-' . bin2hex(random_bytes(6)));

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        // The Definition-catalog section below exercises the real
        // materialize/approval HTTP flow (WidgetManifestMaterializationApiTest's
        // own established precedent): isolate Blob storage so it never
        // touches the shared production `storage_path('app/widget_artifacts')`.
        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);

        // Proven test adapter (WidgetManifestRuntimeBlockApiTest's own
        // established precedent): the real bound default fails closed
        // (503) whenever the shared read-after-write/cache-bypass topology
        // is unproven, which is always true in this test environment. Only
        // the health-report "blocked manifest count" fixture below needs
        // the runtime-block endpoint to actually succeed; every other test
        // in this file is unaffected by this override.
        $this->app->bind(\Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlTopologyGuard::class, static fn () => new class extends \Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlTopologyGuard {
            public function isAvailable(): bool
            {
                return true;
            }
        });
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

    /**
     * @param list<string> $permissionKeys
     */
    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Catalog Tester',
            'email' => 'catalog-tester-' . uniqid('', true) . '@test.local',
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

    private function userWithoutPermissions(): User
    {
        return $this->userOfType(UserType::ADMIN);
    }

    private function approverUser(): User
    {
        return $this->userOfType(UserType::ADMIN, ['widget_template.approve']);
    }

    private function catalogViewerUser(): User
    {
        return $this->userOfType(UserType::ADMIN, ['widget_instance.viewAny']);
    }

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
     * Mirrors WidgetManifestMaterializationApiTest::liveSourceFingerprint()
     * exactly -- the same client-side pre-computation a real materialize
     * caller performs before its first POST for a never-before-seen pair.
     */
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
     * Drives the real materialize -> approve HTTP flow so a fixture pair
     * ends up with a genuinely APPROVED, eligibility-service-consistent
     * Manifest row -- never a hand-built row whose hash could silently
     * drift from what the real materializer would compute.
     */
    private function materializeAndApprove(Widget $widget, WidgetTheme $theme): void
    {
        $approver = $this->approverUser();

        $materialize = $this->actingAs($approver, 'sanctum')->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
        ]);
        $materialize->assertSuccessful();
        $hash = $materialize->json('data.manifest_hash');
        $attestationVersion = (int) $materialize->json('data.attestation_version');

        $approve = $this->actingAs($approver, 'sanctum')->postJson(
            "/api/v2/admin/widget/catalog/manifests/{$hash}/approval",
            ['expected_attestation_version' => $attestationVersion],
        );
        $approve->assertOk();
    }

    // ===================================================================
    // GET /slots
    // ===================================================================

    public function test_slots_catalog_requires_widget_layout_view_any_permission(): void
    {
        $user = $this->userWithoutPermissions();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/slots');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_slots_catalog_returns_every_registered_slot_declaration(): void
    {
        $user = $this->userOfType(UserType::ADMIN, ['widget_layout.viewAny']);

        app(WidgetSlotRegistry::class)->register(new WidgetSlotDeclaration(
            key: 'site.header.before',
            labelKey: 'widget_site_layout.slot.site_header_before',
            cardinality: 'stack',
            supportedScopeTypes: ['global'],
            fallback: null,
            trustLane: 'legacy',
            availability: ['site.shell'],
        ));

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/slots');

        $response->assertOk()->assertJsonPath('data.0.key', 'site.header.before')
            ->assertJsonPath('data.0.label_key', 'widget_site_layout.slot.site_header_before')
            ->assertJsonPath('data.0.cardinality', 'stack')
            ->assertJsonPath('data.0.supported_scope_types', ['global'])
            ->assertJsonPath('data.0.fallback', null)
            ->assertJsonPath('data.0.trust_lane', 'legacy')
            ->assertJsonPath('data.0.availability', ['site.shell']);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_slots_catalog_returns_an_empty_list_when_nothing_is_registered(): void
    {
        $user = $this->userOfType(UserType::ADMIN, ['widget_layout.viewAny']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/slots');

        $response->assertOk()->assertJsonPath('data', []);
    }

    // ===================================================================
    // GET /target-contexts
    // ===================================================================

    public function test_target_contexts_catalog_requires_widget_layout_view_any_permission(): void
    {
        $user = $this->userWithoutPermissions();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/target-contexts');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_target_contexts_catalog_returns_every_registered_target_context_and_never_leaks_resolver_service(): void
    {
        $user = $this->userOfType(UserType::ADMIN, ['widget_layout.viewAny']);

        app(WidgetTemplateContextRegistry::class)->registerTemplateContext('page_static', []);
        app(WidgetTargetContextRegistry::class)->registerTargetContext(new WidgetTargetContextDefinition(
            targetType: 'page.static',
            labelKey: 'widget_site_layout.template_context.page_static',
            templateContextKey: 'page_static',
            resolverService: FakeWidgetTargetContextResolver::class,
        ));

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/target-contexts');

        $response->assertOk()
            ->assertJsonPath('data.0.target_type', 'page.static')
            ->assertJsonPath('data.0.label_key', 'widget_site_layout.template_context.page_static')
            ->assertJsonPath('data.0.template_context_key', 'page_static')
            ->assertJsonPath('data.0.project_applicable', true);

        $this->assertArrayNotHasKey('resolver_service', $response->json('data.0'));
        $this->assertStringNotContainsString('FakeWidgetTargetContextResolver', $response->getContent());
    }

    // ===================================================================
    // GET /catalog/definitions
    // ===================================================================

    public function test_definitions_catalog_requires_widget_instance_view_any_permission(): void
    {
        $user = $this->userWithoutPermissions();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v2/admin/widget/catalog/definitions');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_definitions_catalog_includes_an_approved_eligible_pair(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['name' => 'Eligible Theme']);
        $this->materializeAndApprove($widget, $theme);

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/catalog/definitions');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($widget->id, $rows[0]['widget_id']);
        $this->assertSame($theme->id, $rows[0]['widget_theme_id']);
        $this->assertSame('Eligible Theme', $rows[0]['theme_name']);
        $this->assertTrue($rows[0]['eligible']);
        $this->assertNotEmpty($rows[0]['manifest_hash']);
        $this->assertSame([self::PROJECT], $rows[0]['applicability']);
    }

    public function test_definitions_catalog_adds_display_names_in_the_request_locale(): void
    {
        // This class runs without ApiLocalization: set the request locale directly.
        app()->setLocale('vi');
        $widget = $this->createWidget();
        $widget->forceFill(['name_translations' => ['vi' => 'Ảnh bìa']])->save();
        $theme = $this->createTheme($widget, ['name' => 'Eligible Theme', 'name_translations' => ['vi' => 'Mặc định']]);
        $this->materializeAndApprove($widget, $theme);

        $vi = $this->actingAs($this->catalogViewerUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/catalog/definitions')->assertOk()->json('data.0');
        $this->assertSame('Test Widget', $vi['name']);
        $this->assertSame('Eligible Theme', $vi['theme_name']);
        $this->assertSame('Ảnh bìa', $vi['display_name']);
        $this->assertSame('Mặc định', $vi['theme_display_name']);
        $this->assertSame('Ảnh bìa: Mặc định', $vi['label']);

        app()->setLocale('en');
        $en = $this->actingAs($this->catalogViewerUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/catalog/definitions')->assertOk()->json('data.0');
        $this->assertSame('Test Widget: Eligible Theme', $en['label']);
    }

    public function test_definitions_catalog_includes_a_pending_never_approved_pair(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['name' => 'Pending Theme']);

        $approver = $this->approverUser();
        $this->actingAs($approver, 'sanctum')->postJson('/api/v2/admin/widget/catalog/manifests/materialize', [
            'widget_id' => $widget->id,
            'widget_theme_id' => $theme->id,
            'expected_source_fingerprint' => $this->liveSourceFingerprint($widget, $theme),
        ])->assertSuccessful();

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/catalog/definitions');

        // Unified Widget Catalog Part 2: no approval step; placing approves the manifest.
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.widget_theme_id', $theme->id);
    }

    public function test_definitions_catalog_includes_a_revoked_pair(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['name' => 'Revoked Theme']);
        $this->materializeAndApprove($widget, $theme);

        $manifest = WidgetArtifactManifest::query()->where('project_name', self::PROJECT)->firstOrFail();
        $approver = $this->approverUser();
        $this->actingAs($approver, 'sanctum')->deleteJson(
            "/api/v2/admin/widget/catalog/manifests/{$manifest->manifest_hash}/approval",
            ['expected_attestation_version' => $manifest->attestation_version],
        )->assertOk();

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/catalog/definitions');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.widget_theme_id', $theme->id);
    }

    public function test_definitions_catalog_includes_a_pair_with_no_manifest_yet(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['name' => 'Never Materialized']);

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/catalog/definitions');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.widget_theme_id', $theme->id);
        $this->assertNull($response->json('data.0.attestation_version'));
        $this->assertSame(0, WidgetArtifactManifest::query()->count(), 'Listing must not materialize anything.');
    }

    public function test_definitions_catalog_excludes_a_core_blocked_widget(): void
    {
        $widget = $this->createWidget();
        $this->createTheme($widget, ['name' => 'Blocked Widget Theme']);
        $widget->forceFill(['blocked_at' => now(), 'blocked_reason' => 'test block'])->save();

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/catalog/definitions');

        $response->assertOk()->assertJsonPath('data', []);

        $this->actingAs($this->catalogViewerUser(), 'sanctum')
            ->getJson("/api/v2/admin/widget/catalog/definitions/{$widget->id}")
            ->assertStatus(404);
    }

    // ===================================================================
    // GET /catalog/definitions/{widget}
    // ===================================================================

    public function test_definitions_detail_requires_widget_instance_view_any_permission(): void
    {
        $widget = $this->createWidget();

        $response = $this->actingAs($this->userWithoutPermissions(), 'sanctum')
            ->getJson("/api/v2/admin/widget/catalog/definitions/{$widget->id}");

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_definitions_detail_returns_the_widgets_eligible_themes(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['name' => 'Eligible Theme']);
        $this->materializeAndApprove($widget, $theme);

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')
            ->getJson("/api/v2/admin/widget/catalog/definitions/{$widget->id}");

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($widget->id, $rows[0]['widget_id']);
        $this->assertSame($theme->id, $rows[0]['widget_theme_id']);
    }

    public function test_definitions_detail_returns_404_for_an_unknown_widget(): void
    {
        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/catalog/definitions/999999');

        $response->assertStatus(404);
    }

    public function test_definitions_detail_returns_404_for_a_widget_with_no_eligible_theme(): void
    {
        $widget = $this->createWidget();
        // A theme applicable to another project only: nothing placeable here.
        $this->createTheme($widget, ['name' => 'Other Project', 'project_name' => ['SomeOtherProject']]);

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')
            ->getJson("/api/v2/admin/widget/catalog/definitions/{$widget->id}");

        $response->assertStatus(404);
    }

    // ===================================================================
    // GET /layouts/{uuid}/releases
    // ===================================================================

    private function layoutEditorUser(): User
    {
        return $this->userOfType(UserType::ADMIN, [
            'widget_layout.viewAny',
            'widget_layout.view',
            'widget_layout.update',
            'widget_layout.publish',
        ]);
    }

    public function test_releases_requires_widget_layout_view_permission(): void
    {
        $editor = $this->layoutEditorUser();
        $bootstrap = $this->actingAs($editor, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site');
        $uuid = $bootstrap->json('data.uuid');

        $response = $this->actingAs($this->userWithoutPermissions(), 'sanctum')
            ->getJson("/api/v2/admin/widget/layouts/{$uuid}/releases");

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_releases_returns_404_for_an_unknown_layout(): void
    {
        $response = $this->actingAs($this->layoutEditorUser(), 'sanctum')
            ->getJson('/api/v2/admin/widget/layouts/does-not-exist/releases');

        $response->assertStatus(404);
    }

    public function test_releases_lists_only_published_releases_never_the_current_draft(): void
    {
        $editor = $this->layoutEditorUser();
        $bootstrap = $this->actingAs($editor, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site');
        $uuid = $bootstrap->json('data.uuid');

        $response = $this->actingAs($editor, 'sanctum')->getJson("/api/v2/admin/widget/layouts/{$uuid}/releases");

        $response->assertOk()->assertJsonPath('data', []);
    }

    public function test_releases_lists_a_published_release_with_the_frozen_fields(): void
    {
        $editor = $this->layoutEditorUser();
        $bootstrap = $this->actingAs($editor, 'sanctum')->putJson('/api/v2/admin/widget/layouts/by-key/site');
        $uuid = $bootstrap->json('data.uuid');

        $publish = $this->actingAs($editor, 'sanctum')
            ->withHeader('Idempotency-Key', 'release-history-test-key')
            ->postJson("/api/v2/admin/widget/layouts/{$uuid}/publish", [
                'expected_draft_id' => $bootstrap->json('data.current_draft.version'),
                'expected_lock_version' => $bootstrap->json('data.current_draft.lock_version'),
            ]);
        $publish->assertOk();

        $response = $this->actingAs($editor, 'sanctum')->getJson("/api/v2/admin/widget/layouts/{$uuid}/releases");

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($publish->json('release.version'), $rows[0]['version']);
        $this->assertArrayHasKey('based_on_version', $rows[0]);
        $this->assertArrayHasKey('rollback_of_version', $rows[0]);
        $this->assertArrayHasKey('published_at', $rows[0]);
        $this->assertArrayHasKey('published_by', $rows[0]);
        $this->assertArrayHasKey('placements_count', $rows[0]);
        $this->assertArrayHasKey('registry_contract_hash', $rows[0]);
    }

    // ===================================================================
    // GET /health
    // ===================================================================

    public function test_health_requires_widget_instance_view_any_permission(): void
    {
        $response = $this->actingAs($this->userWithoutPermissions(), 'sanctum')->getJson('/api/v2/admin/widget/health');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_health_reports_a_clean_baseline_with_nothing_registered_or_materialized(): void
    {
        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/health');

        $response->assertOk()
            ->assertJsonPath('registry_drift', [])
            ->assertJsonPath('provider_usage.missing', [])
            ->assertJsonPath('provider_usage.deprecated', [])
            ->assertJsonPath('materialization.drifted', [])
            ->assertJsonPath('materialization.current', [])
            ->assertJsonPath('materialization.incidents', [])
            ->assertJsonPath('blob_manifest_integrity.unreferenced_blob_count', 0)
            ->assertJsonPath('blob_manifest_integrity.unreferenced_manifest_count', 0)
            ->assertJsonPath('emergency_block.blocked_manifest_count', 0);
    }

    public function test_health_reports_an_unreferenced_manifest_and_reflects_its_eligibility_state(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['name' => 'Health Theme']);
        $this->materializeAndApprove($widget, $theme);

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/health');

        $response->assertOk()->assertJsonPath('blob_manifest_integrity.unreferenced_manifest_count', 1);
        $current = $response->json('materialization.current');
        $this->assertCount(1, $current);
        $this->assertSame(WidgetManifestApprovalService::STATE_APPROVED, $current[0]['eligibility_state']);
    }

    public function test_health_reports_an_unreferenced_blob(): void
    {
        WidgetArtifactBlob::query()->create([
            'project_name' => self::PROJECT,
            'artifact_hash' => str_repeat('a', 64),
            'storage_disk' => 'local',
            'storage_key' => 'unreferenced/blob.php',
            'byte_size' => 10,
            'media_type' => 'application/x-php',
            'checksum' => str_repeat('b', 64),
        ]);

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/health');

        $response->assertOk()->assertJsonPath('blob_manifest_integrity.unreferenced_blob_count', 1);
    }

    public function test_health_reports_the_blocked_manifest_count(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['name' => 'Blocked Theme']);
        $this->materializeAndApprove($widget, $theme);

        $manifest = WidgetArtifactManifest::query()->where('project_name', self::PROJECT)->firstOrFail();
        $blocker = $this->userOfType(UserType::ADMIN, ['widget_template.emergencyDisable']);
        $this->actingAs($blocker, 'sanctum')->postJson(
            "/api/v2/admin/widget/catalog/manifests/{$manifest->manifest_hash}/runtime-block",
            ['expected_control_version' => $manifest->control_version, 'reason' => 'incident-drill'],
        )->assertOk();

        $response = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/health');

        $response->assertOk()->assertJsonPath('emergency_block.blocked_manifest_count', 1);
        // A blocked manifest is emergency-disabled and must never appear in
        // the placement-selectable Definition catalog even though it is
        // still formally "approved" (DoD 1199: no metadata leakage / only
        // safe gateways -- eligible means actually usable right now).
        $catalog = $this->actingAs($this->catalogViewerUser(), 'sanctum')->getJson('/api/v2/admin/widget/catalog/definitions');
        $catalog->assertOk()->assertJsonPath('data', []);
    }
}
