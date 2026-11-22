<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Http;

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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Chunk 2 Task 15c: `POST|DELETE /api/v2/admin/widget/catalog/manifests/{manifest_hash}/runtime-block`
 * (docs/contracts/widget-site-layout-api.md "Catalog (split semantics)";
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * runtime-block route entries).
 *
 * TOPOLOGY GUARD IS BOUND AVAILABLE FOR THIS ENTIRE SUITE (setUp() below):
 * this file proves the block/clear BUSINESS logic (CAS, permission
 * separation, mandatory reason, audit, no-canonical-mutation, field
 * mutation semantics, project isolation) -- none of which is reachable
 * while the real production topology guard reports unavailable. The
 * DEDICATED proof that production stays unavailable-by-default, and that
 * this exact override mechanism is what a later task must use, lives in
 * {@see \Cms\Widget\Tests\Feature\Rendering\WidgetManifestRuntimeBlockTopologyTest}.
 *
 * Manifest rows are inserted DIRECTLY (bypassing the materializer
 * pipeline), mirroring WidgetManifestApprovalApiTest (Task 15b): this
 * suite tests the block/clear state machine, not materialization.
 */
final class WidgetManifestRuntimeBlockApiTest extends TestCase
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

        // Proven test adapter: this whole suite exercises the CAS/business
        // logic reachable only once topology is available. See class
        // docblock.
        $this->app->bind(WidgetRuntimeControlTopologyGuard::class, static fn () => new class extends WidgetRuntimeControlTopologyGuard {
            public function isAvailable(): bool
            {
                return true;
            }
        });
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
            'name' => 'Runtime Block Tester',
            'email' => 'runtime-block-tester-' . uniqid('', true) . '@test.local',
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

    private function eligibilityApproverUser(): User
    {
        return $this->userOfType(UserType::ADMIN, ['widget_template.approve']);
    }

    private function runtimeBlockUrl(string $manifestHash): string
    {
        return '/api/v2/admin/widget/catalog/manifests/' . $manifestHash . '/runtime-block';
    }

    // ===================================================================
    // Authentication / api.admin gate / permission separation
    // ===================================================================

    public function test_block_requires_authentication(): void
    {
        $manifest = $this->createManifest('ExampleProject');

        $response = $this->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
            'expected_control_version' => 1,
            'reason' => 'Unauthenticated attempt.',
        ]);

        $response->assertUnauthorized();
    }

    public function test_block_denies_a_client_type_user_at_the_api_admin_gate(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $client = $this->userOfType(UserType::CLIENT);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Client-type attempt.',
            ]);

        $response->assertUnauthorized();
    }

    public function test_block_denies_an_admin_type_user_without_the_emergencydisable_permission(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'No permission at all.',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id']);
        $this->assertFalse((bool) $manifest->fresh()->blocked);
    }

    public function test_eligibility_approver_without_emergencydisable_cannot_block(): void
    {
        // THE key permission-separation proof (checklist item 1: "Eligibility
        // approver permission alone is insufficient"). Holds
        // widget_template.approve but NOT widget_template.emergencyDisable.
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->eligibilityApproverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'An approver alone must not be able to block.',
            ]);

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
        $this->assertFalse((bool) $manifest->fresh()->blocked);
    }

    public function test_emergency_responder_without_approve_permission_can_still_block(): void
    {
        // Symmetric converse: emergencyDisable alone (no widget_template.approve)
        // is sufficient for block/clear -- the two permissions are fully
        // independent, neither implies the other.
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Emergency permission alone is sufficient here.',
            ]);

        $response->assertOk();
    }

    // ===================================================================
    // Precedent: permission (403) before shape (422); mandatory reason
    // ===================================================================

    public function test_no_permission_plus_malformed_body_returns_403_not_422(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 'not-an-integer',
            ]);

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_has_permission_plus_malformed_body_returns_422(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 'not-an-integer',
                'reason' => 'Reason present, version malformed.',
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED');
    }

    public function test_block_rejects_a_missing_reason_the_mandatory_field(): void
    {
        // Unlike Task 15b's optional approval `reason`, this field is
        // genuinely mandatory (checklist item 1; ApproveWidgetManifestRequest's
        // own docblock discloses the "mandatory reason" language is scoped
        // to THIS endpoint).
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED');
        $this->assertArrayHasKey('reason', $response->json('details'));
        $this->assertFalse((bool) $manifest->fresh()->blocked);
    }

    public function test_block_rejects_a_missing_expected_control_version(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'reason' => 'Version omitted.',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('expected_control_version', $response->json('details'));
    }

    public function test_clear_also_rejects_a_missing_reason(): void
    {
        $manifest = $this->createManifest('ExampleProject', ['blocked' => true, 'control_version' => 2]);
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->deleteJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 2,
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED');
    }

    // ===================================================================
    // Happy path: unblocked -> blocked -> unblocked
    // ===================================================================

    public function test_block_transitions_unblocked_to_blocked_and_returns_200(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Confirmed security issue in this theme.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.blocked', true)
            ->assertJsonPath('data.control_version', 2)
            ->assertJsonStructure(['data' => [
                'manifest_hash', 'project_name', 'eligibility_state', 'attestation_version',
                'policy_version', 'trust_lane', 'control_version', 'blocked',
                'approved_by', 'approved_at', 'revoked_by', 'revoked_at',
            ]]);

        $fresh = $manifest->fresh();
        $this->assertTrue((bool) $fresh->blocked);
        $this->assertSame(2, $fresh->control_version);
        $this->assertSame($responder->id, $fresh->blocked_by);
        $this->assertNotNull($fresh->blocked_at);
        $this->assertSame('Confirmed security issue in this theme.', $fresh->control_reason);
    }

    public function test_clear_transitions_blocked_to_unblocked_and_returns_200(): void
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
                'reason' => 'Issue resolved upstream, safe to restore.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.blocked', false)
            ->assertJsonPath('data.control_version', 3);

        $fresh = $manifest->fresh();
        $this->assertFalse((bool) $fresh->blocked);
        $this->assertSame(3, $fresh->control_version);
        $this->assertSame($responder->id, $fresh->cleared_by);
        $this->assertNotNull($fresh->cleared_at);
    }

    public function test_block_can_be_called_again_while_already_blocked_idempotently(): void
    {
        // Disclosed design (WidgetManifestRuntimeBlockService docblock): no
        // illegal FROM-state for a boolean flag -- an operator may safely
        // re-block with an updated reason.
        $manifest = $this->createManifest('ExampleProject', [
            'blocked' => true,
            'control_version' => 2,
            'blocked_by' => 111,
            'blocked_at' => now()->subHour(),
            'control_reason' => 'Original reason.',
        ]);
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 2,
                'reason' => 'Escalated reason.',
            ]);

        $response->assertOk()->assertJsonPath('data.blocked', true)->assertJsonPath('data.control_version', 3);

        $fresh = $manifest->fresh();
        $this->assertSame($responder->id, $fresh->blocked_by);
        $this->assertSame('Escalated reason.', $fresh->control_reason);
    }

    // ===================================================================
    // 404: unknown hash, cross-project isolation
    // ===================================================================

    public function test_block_returns_404_for_an_unknown_manifest_hash(): void
    {
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl(str_repeat('a', 64)), [
                'expected_control_version' => 1,
                'reason' => 'Unknown hash.',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'WIDGET_MANIFEST_NOT_FOUND')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id']);
    }

    public function test_block_returns_404_for_a_manifest_belonging_to_another_project_without_leaking_it(): void
    {
        $foreignManifest = $this->createManifest('SomeOtherProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($foreignManifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Cross-project attempt.',
            ]);

        $response->assertStatus(404)->assertJsonPath('code', 'WIDGET_MANIFEST_NOT_FOUND');
        $this->assertFalse((bool) $foreignManifest->fresh()->blocked, 'A cross-project manifest must never be mutated.');
    }

    // ===================================================================
    // 409: stale expected_control_version, on the INDEPENDENT control axis
    // ===================================================================

    public function test_block_returns_409_for_a_stale_expected_control_version(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 99,
                'reason' => 'Stale version attempt.',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'WIDGET_RUNTIME_CONTROL_VERSION_STALE')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id']);
        $this->assertFalse((bool) $manifest->fresh()->blocked);
        $this->assertSame(1, $manifest->fresh()->control_version);
    }

    public function test_clear_returns_409_for_a_stale_expected_control_version(): void
    {
        $manifest = $this->createManifest('ExampleProject', ['blocked' => true, 'control_version' => 5]);
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->deleteJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 4,
                'reason' => 'Stale version attempt.',
            ]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_RUNTIME_CONTROL_VERSION_STALE');
        $this->assertTrue((bool) $manifest->fresh()->blocked);
        $this->assertSame(5, $manifest->fresh()->control_version);
    }

    public function test_stale_control_version_never_touches_the_separate_attestation_version(): void
    {
        // Proves the two CAS axes are truly independent: a stale
        // control_version attempt must never bump (or even inspect the
        // outcome of) attestation_version.
        $manifest = $this->createManifest('ExampleProject', ['attestation_version' => 7]);
        $responder = $this->emergencyResponderUser();

        $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 99,
                'reason' => 'Stale control_version, must not touch attestation_version.',
            ])->assertStatus(409);

        $this->assertSame(7, $manifest->fresh()->attestation_version);
    }

    // ===================================================================
    // Durable audit: written on success, NEVER on a stale/denied attempt
    // ===================================================================

    public function test_successful_block_writes_exactly_one_durable_audit_row(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Legal takedown request received.',
            ])->assertOk();

        $this->assertSame(1, AuditLog::query()->count());
        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('action.widget_template.block', $row->action);
        $this->assertSame(WidgetArtifactManifestModel::class, $row->target_type);
        $this->assertSame($manifest->id, $row->target_id);
        $this->assertSame($responder->id, $row->user_id);
        $this->assertSame($manifest->manifest_hash, $row->changed['options']['manifest_hash']);
    }

    public function test_successful_clear_writes_exactly_one_durable_audit_row(): void
    {
        $manifest = $this->createManifest('ExampleProject', ['blocked' => true, 'control_version' => 2]);
        $responder = $this->emergencyResponderUser();

        $this->actingAs($responder, 'sanctum')
            ->deleteJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 2,
                'reason' => 'Confirmed safe, restoring output.',
            ])->assertOk();

        $this->assertSame(1, AuditLog::query()->count());
        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('action.widget_template.clear', $row->action);
    }

    public function test_a_stale_concurrent_block_writes_no_misleading_audit_row(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 99,
                'reason' => 'Stale attempt.',
            ])->assertStatus(409);

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_a_permission_denial_writes_no_audit_row(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $this->actingAs($unprivileged, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Denied attempt.',
            ])->assertStatus(403);

        $this->assertSame(0, AuditLog::query()->count());
    }

    // ===================================================================
    // Canonical fields and eligibility attestation never mutate; only
    // control metadata changes -- and the reason is never publicly leaked
    // ===================================================================

    public function test_block_mutates_only_control_metadata_never_canonical_provenance_or_eligibility(): void
    {
        $manifest = $this->createManifest('ExampleProject', [
            'eligibility_state' => 'approved',
            'attestation_version' => 4,
            'approved_by' => 321,
            'approved_at' => now()->subDay(),
        ]);
        $responder = $this->emergencyResponderUser();

        $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Emergency block on an approved manifest.',
            ])->assertOk();

        $fresh = $manifest->fresh();
        $this->assertSame($manifest->manifest_hash, $fresh->manifest_hash);
        $this->assertSame($manifest->artifact_blob_id, $fresh->artifact_blob_id);
        $this->assertSame($manifest->executable_surface_fingerprint_hash, $fresh->executable_surface_fingerprint_hash);
        $this->assertSame($manifest->canonical_schema_hash, $fresh->canonical_schema_hash);
        $this->assertSame($manifest->trust_policy_version, $fresh->trust_policy_version);
        $this->assertSame($manifest->trust_lane, $fresh->trust_lane);
        // Eligibility attestation axis untouched by the control axis.
        $this->assertSame('approved', $fresh->eligibility_state);
        $this->assertSame(4, $fresh->attestation_version);
        $this->assertSame(321, $fresh->approved_by);
    }

    public function test_block_preserves_prior_clear_marks_while_setting_blocked_marks(): void
    {
        // Field mutation semantics (WidgetManifestRuntimeBlockService
        // docblock): block() preserves cleared_by/at, mirroring revoke()
        // preserving approved_by/at in Task 15b.
        $manifest = $this->createManifest('ExampleProject', [
            'control_version' => 3,
            'cleared_by' => 555,
            'cleared_at' => now()->subDay(),
        ]);
        $responder = $this->emergencyResponderUser();

        $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 3,
                'reason' => 'New incident.',
            ])->assertOk();

        $fresh = $manifest->fresh();
        $this->assertSame(555, $fresh->cleared_by, 'block() must not erase who last cleared the manifest.');
        $this->assertNotNull($fresh->cleared_at);
        $this->assertSame($responder->id, $fresh->blocked_by);
    }

    public function test_clear_clears_prior_block_marks_while_setting_cleared_marks(): void
    {
        $manifest = $this->createManifest('ExampleProject', [
            'blocked' => true,
            'control_version' => 2,
            'blocked_by' => 777,
            'blocked_at' => now()->subHour(),
        ]);
        $responder = $this->emergencyResponderUser();

        $this->actingAs($responder, 'sanctum')
            ->deleteJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 2,
                'reason' => 'All clear.',
            ])->assertOk();

        $fresh = $manifest->fresh();
        $this->assertNull($fresh->blocked_by, 'clear() must null the prior blocked_by marker (no longer currently blocked).');
        $this->assertNull($fresh->blocked_at);
        $this->assertSame($responder->id, $fresh->cleared_by);
    }

    public function test_response_never_exposes_the_control_reason_field(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $responder = $this->emergencyResponderUser();

        $response = $this->actingAs($responder, 'sanctum')
            ->postJson($this->runtimeBlockUrl($manifest->manifest_hash), [
                'expected_control_version' => 1,
                'reason' => 'Sensitive internal legal reason, must not be echoed back.',
            ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayNotHasKey('reason', $data);
        $this->assertArrayNotHasKey('control_reason', $data);
        $this->assertSame(
            ['manifest_hash', 'project_name', 'eligibility_state', 'attestation_version', 'policy_version', 'trust_lane', 'control_version', 'blocked', 'approved_by', 'approved_at', 'revoked_by', 'revoked_at'],
            array_keys($data)
        );
    }

    // ===================================================================
    // Route registration: coexists with static/wildcard siblings
    // ===================================================================

    public function test_runtime_block_routes_coexist_with_approval_and_materialize_routes(): void
    {
        $blockPostRoute = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v2/admin/widget/catalog/manifests/{manifest_hash}/runtime-block' && in_array('POST', $r->methods(), true));
        $blockDeleteRoute = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v2/admin/widget/catalog/manifests/{manifest_hash}/runtime-block' && in_array('DELETE', $r->methods(), true));
        $approvalRoute = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v2/admin/widget/catalog/manifests/{manifest_hash}/approval' && in_array('POST', $r->methods(), true));
        $materializeRoute = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v2/admin/widget/catalog/manifests/materialize');

        $this->assertNotNull($blockPostRoute);
        $this->assertNotNull($blockDeleteRoute);
        $this->assertNotNull($approvalRoute);
        $this->assertNotNull($materializeRoute);
    }
}
