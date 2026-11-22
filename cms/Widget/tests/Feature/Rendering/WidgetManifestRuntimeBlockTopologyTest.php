<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Rendering;

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Models\User;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Widget\Database\Seeders\WidgetPermissionTableSeeder;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlTopologyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Chunk 2 Task 15c: proves the production topology gate for the emergency
 * runtime-block endpoint stays UNAVAILABLE BY DEFAULT (plan checklist item
 * 4; docs/architecture/adr-widget-artifact-store.md decision 11).
 *
 * This is the CRITICAL safety proof for this task: production must never
 * be able to mutate emergency control until Task 24 wires the named
 * cache-lookup/CDN bypass and Task 24b proves cross-node/edge behavior.
 * This suite deliberately does NOT bind any {@see WidgetRuntimeControlTopologyGuard}
 * override for its "default" tests -- the real production class, with its
 * hardcoded `isAvailable(): false`, is exercised exactly as a real
 * deployment would resolve it. A separate small set of tests proves the
 * test-only override mechanism exists (so later tasks can exercise the
 * CAS/200 path once Task 24/24b legitimately flip readiness), without
 * ever suggesting production itself should be flipped here.
 */
final class WidgetManifestRuntimeBlockTopologyTest extends TestCase
{
    use RefreshDatabase;

    /** See WidgetManifestApprovalApiTest::beforeRefreshingDatabase() (Task 15b) for the full explanation. */
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

        config(['ispa-cms.folder_name' => 'ExampleProject']);

        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->withoutMiddleware([
            ApiLocalization::class,
            ThrottleRequests::class,
            HttpsProtocol::class,
            Localization::class,
        ]);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     */
    private function createManifest(string $projectName, array $overrides = []): WidgetArtifactManifestModel
    {
        $blob = WidgetArtifactBlob::query()->create([
            'project_name' => $projectName,
            'artifact_hash' => hash('sha256', 'blob-' . uniqid('', true)),
            'storage_disk' => 'local',
            'storage_key' => 'widget_artifacts/' . uniqid('', true) . '.blade.php',
            'byte_size' => 42,
            'media_type' => 'text/x-blade-template',
            'checksum' => hash('sha256', 'checksum-' . uniqid('', true)),
        ]);

        return WidgetArtifactManifestModel::query()->create(array_merge([
            'project_name' => $projectName,
            'manifest_hash' => hash('sha256', 'manifest-' . uniqid('', true)),
            'artifact_blob_id' => $blob->id,
            'generator_version' => 'legacy-widget-artifact-set-publisher-v1',
            'renderer_key' => 'legacy-blade-widget',
            'renderer_version' => 'v1',
            'applicability' => [$projectName],
            'executable_surface_fingerprint_hash' => hash('sha256', 'fingerprint-' . uniqid('', true)),
            'canonical_schema_hash' => hash('sha256', 'schema-' . uniqid('', true)),
            'provider_contracts' => [],
            'trust_policy_version' => ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            'trust_lane' => ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            'eligibility_state' => 'pending',
            'attestation_version' => 1,
            'control_version' => 1,
            'blocked' => false,
        ], $overrides));
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function userOfType(int $type, array $permissionKeys = []): User
    {
        $user = User::forceCreate([
            'name' => 'Topology Tester',
            'email' => 'topology-tester-' . uniqid('', true) . '@test.local',
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

    private function emergencyResponderUser(): User
    {
        return $this->userOfType(UserType::ADMIN, ['widget_template.emergencyDisable']);
    }

    private function runtimeBlockUrl(string $manifestHash): string
    {
        return '/api/v2/admin/widget/catalog/manifests/' . $manifestHash . '/runtime-block';
    }

    // ===================================================================
    // Production default: unavailable, no write, no binding overridden
    // ===================================================================

    public function test_topology_guard_default_class_reports_unavailable_directly(): void
    {
        // Direct unit-level proof, independent of HTTP: the real production
        // class, freshly constructed exactly as the container would, always
        // reports unavailable.
        $this->assertFalse((new WidgetRuntimeControlTopologyGuard())->isAvailable());
    }

    public function test_block_returns_503_topology_unavailable_by_default_and_writes_nothing(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Testing the default-unavailable gate.',
            ]);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id']);

        $fresh = $manifest->fresh();
        $this->assertFalse((bool) $fresh->blocked, 'Topology-unavailable must write nothing.');
        $this->assertSame(1, $fresh->control_version, 'control_version must never advance when topology is unavailable.');
        $this->assertSame(0, AuditLog::query()->count(), 'No audit row on a topology-preflight failure.');
    }

    public function test_clear_returns_503_topology_unavailable_by_default_and_writes_nothing(): void
    {
        $manifest = $this->createManifest('ExampleProject', [
            'blocked' => true,
            'control_version' => 2,
            'blocked_by' => 999,
            'blocked_at' => now(),
        ]);
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->deleteJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 2,
                'reason' => 'Testing the default-unavailable gate.',
            ]);

        $response->assertStatus(503)->assertJsonPath('code', 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE');

        $fresh = $manifest->fresh();
        $this->assertTrue((bool) $fresh->blocked, 'Topology-unavailable must leave the manifest exactly as it was.');
        $this->assertSame(2, $fresh->control_version);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_topology_unavailable_check_happens_before_manifest_lookup_so_unknown_hash_also_returns_503_not_404(): void
    {
        // Proves the guard is a BLANKET endpoint-level precondition checked
        // before any row lookup (checklist item 3), not a per-resource
        // check: even a manifest_hash that does not exist anywhere returns
        // 503, never 404, while topology is unavailable.
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl(str_repeat('f', 64)), [
                'expected_control_version' => 1,
                'reason' => 'Unknown hash under unavailable topology.',
            ]);

        $response->assertStatus(503)->assertJsonPath('code', 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE');
    }

    public function test_permission_denial_still_wins_over_topology_unavailable(): void
    {
        // Ordering proof: 403 (FormRequest::authorize(), before the
        // controller even runs) always wins over the controller's own 503
        // topology check, exactly like 403-before-422 in the approval
        // precedent.
        $manifest = $this->createManifest('ExampleProject');
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Should never reach the topology check.',
            ]);

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_validation_failure_still_wins_over_topology_unavailable(): void
    {
        // 422 (FormRequest::rules(), before the controller runs) also wins
        // over the controller's own 503 topology check.
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                // reason omitted -- mandatory field missing.
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED');
    }

    // ===================================================================
    // Test-only override: a bound proven adapter unlocks the CAS/200 path
    // ===================================================================

    public function test_binding_a_proven_test_adapter_allows_block_to_succeed_with_200(): void
    {
        $this->app->bind(WidgetRuntimeControlTopologyGuard::class, static fn () => new class extends WidgetRuntimeControlTopologyGuard {
            public function isAvailable(): bool
            {
                return true;
            }
        });

        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Proven test adapter unlocks the mutation.',
            ]);

        $response->assertOk()->assertJsonPath('data.blocked', true);
        $this->assertTrue((bool) $manifest->fresh()->blocked);
    }

    public function test_unbinding_reverts_to_the_real_unavailable_default_for_a_fresh_test(): void
    {
        // No binding in THIS test method: proves the override from the
        // previous test method does not leak across tests (each test gets
        // a fresh application container per Laravel's TestCase).
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Must be unavailable again with no override bound.',
            ]);

        $response->assertStatus(503)->assertJsonPath('code', 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE');
    }
}
