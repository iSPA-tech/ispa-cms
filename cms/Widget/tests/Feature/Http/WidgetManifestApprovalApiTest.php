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
use Cms\Widget\Rendering\Eligibility\WidgetEligibilityDecision;
use Cms\Widget\Rendering\Eligibility\WidgetExecutableSurfaceFingerprint;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\Eligibility\WidgetSiteLayoutEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Chunk 2 Task 15b: `GET /api/v2/admin/widget/catalog/manifests` (approval
 * inventory), `POST|DELETE /api/v2/admin/widget/catalog/manifests/{manifest_hash}/approval`
 * (docs/contracts/widget-site-layout-api.md "Catalog (split semantics)";
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json approval
 * route entries).
 *
 * Boots the real WidgetServiceProvider so route registration is exercised
 * faithfully, mirroring WidgetManifestMaterializationApiTest (Task 15a).
 * Manifest rows are inserted DIRECTLY (bypassing the materializer/builder
 * pipeline entirely) because this suite tests the approval STATE MACHINE,
 * not materialization -- Task 15a already owns and proves that pipeline.
 */
final class WidgetManifestApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * See WidgetManifestMaterializationApiTest::beforeRefreshingDatabase()
     * (Task 15a) for the full explanation.
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

        // Pinned explicitly, matching WidgetManifestMaterializationApiTest --
        // every fixture/assertion below uses this SAME constant.
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
            'name' => 'Approval Tester',
            'email' => 'approval-tester-' . uniqid('', true) . '@test.local',
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

    private function approverUser(): User
    {
        return $this->userOfType(UserType::ADMIN, ['widget_template.approve']);
    }

    private function approvalUrl(string $manifestHash): string
    {
        return '/api/v2/admin/widget/catalog/manifests/' . $manifestHash . '/approval';
    }

    // ===================================================================
    // Authentication / api.admin gate / permission
    // ===================================================================

    public function test_approve_requires_authentication(): void
    {
        $manifest = $this->createManifest('ExampleProject');

        $response = $this->postJson($this->approvalUrl($manifest->manifest_hash), [
            'expected_attestation_version' => 1,
        ]);

        $response->assertUnauthorized();
    }

    public function test_approve_denies_a_client_type_user_at_the_api_admin_gate(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $client = $this->userOfType(UserType::CLIENT);

        $response = $this->actingAs($client, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 1]);

        $response->assertUnauthorized();
    }

    public function test_approve_denies_an_admin_type_user_without_the_approve_permission(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 1]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id']);
        $this->assertSame('pending', $manifest->fresh()->eligibility_state);
    }

    // ===================================================================
    // Precedent copied from Task 15a: permission (403) before shape (422)
    // ===================================================================

    public function test_no_permission_plus_malformed_body_returns_403_not_422(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), [
                'expected_attestation_version' => 'not-an-integer',
            ]);

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_has_permission_plus_malformed_body_returns_422(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), [
                'expected_attestation_version' => 'not-an-integer',
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED');
    }

    public function test_approve_rejects_a_missing_required_field(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('expected_attestation_version', $response->json('details'));
    }

    // ===================================================================
    // Happy path: pending -> approved -> revoked -> approved (reapproval)
    // ===================================================================

    public function test_approve_transitions_pending_to_approved_and_returns_200(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), [
                'expected_attestation_version' => 1,
                'reason' => 'Reviewed the source, safe to approve.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.eligibility_state', 'approved')
            ->assertJsonPath('data.attestation_version', 2)
            ->assertJsonPath('data.approved_by', $approver->id)
            ->assertJsonStructure(['data' => [
                'manifest_hash', 'project_name', 'eligibility_state', 'attestation_version',
                'policy_version', 'trust_lane', 'control_version', 'blocked',
                'approved_by', 'approved_at', 'revoked_by', 'revoked_at',
            ]]);

        $fresh = $manifest->fresh();
        $this->assertSame('approved', $fresh->eligibility_state);
        $this->assertSame(2, $fresh->attestation_version);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_revoke_transitions_approved_to_revoked_and_returns_200(): void
    {
        $manifest = $this->createManifest('ExampleProject', [
            'eligibility_state' => 'approved',
            'attestation_version' => 2,
            'approved_by' => 999,
            'approved_at' => now(),
        ]);
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->deleteJson($this->approvalUrl($manifest->manifest_hash), [
                'expected_attestation_version' => 2,
                'reason' => 'Security concern found in the theme.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.eligibility_state', 'revoked')
            ->assertJsonPath('data.attestation_version', 3)
            ->assertJsonPath('data.revoked_by', $approver->id);

        $fresh = $manifest->fresh();
        $this->assertSame('revoked', $fresh->eligibility_state);
        $this->assertSame(3, $fresh->attestation_version);
    }

    public function test_reapprove_transitions_revoked_back_to_approved_explicitly(): void
    {
        $manifest = $this->createManifest('ExampleProject', [
            'eligibility_state' => 'revoked',
            'attestation_version' => 3,
            'approved_by' => 111,
            'approved_at' => now()->subDay(),
            'revoked_by' => 222,
            'revoked_at' => now(),
        ]);
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), [
                'expected_attestation_version' => 3,
                'reason' => 'Re-reviewed, issue resolved upstream.',
            ]);

        $response->assertOk()->assertJsonPath('data.eligibility_state', 'approved');

        $fresh = $manifest->fresh();
        $this->assertSame('approved', $fresh->eligibility_state);
        $this->assertSame(4, $fresh->attestation_version);
        $this->assertSame($manifest->manifest_hash, $fresh->manifest_hash, 'Reapproval never changes the manifest_hash identity.');
        $this->assertNull($fresh->revoked_by, 'Reapproval clears the prior revocation marks.');
        $this->assertNull($fresh->revoked_at, 'Reapproval clears the prior revocation marks.');
    }

    // ===================================================================
    // 404: unknown hash, cross-project isolation
    // ===================================================================

    public function test_approve_returns_404_for_an_unknown_manifest_hash(): void
    {
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl(str_repeat('a', 64)), ['expected_attestation_version' => 1]);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'WIDGET_MANIFEST_NOT_FOUND')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id']);
    }

    public function test_approve_returns_404_for_a_manifest_belonging_to_another_project_without_leaking_it(): void
    {
        $foreignManifest = $this->createManifest('SomeOtherProject');
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($foreignManifest->manifest_hash), ['expected_attestation_version' => 1]);

        $response->assertStatus(404)->assertJsonPath('code', 'WIDGET_MANIFEST_NOT_FOUND');
        $this->assertSame('pending', $foreignManifest->fresh()->eligibility_state, 'A cross-project manifest must never be mutated.');
    }

    // ===================================================================
    // 409: stale attestation_version, and wrong current state (the
    // version-alone-is-insufficient race case)
    // ===================================================================

    public function test_approve_returns_409_for_a_stale_expected_attestation_version(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 99]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'WIDGET_ATTESTATION_VERSION_STALE')
            ->assertJsonStructure(['status', 'code', 'message', 'correlation_id']);
        $this->assertSame('pending', $manifest->fresh()->eligibility_state);
    }

    public function test_approve_returns_409_when_attempting_to_approve_an_already_approved_manifest(): void
    {
        $manifest = $this->createManifest('ExampleProject', [
            'eligibility_state' => 'approved',
            'attestation_version' => 2,
            'approved_by' => 999,
            'approved_at' => now(),
        ]);
        $approver = $this->approverUser();

        // expected_attestation_version is the CORRECT current value (2) --
        // proves the 409 comes from the STATE precondition (approved is not
        // a valid FROM-state for approve()), not merely a version mismatch.
        $response = $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 2]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_ATTESTATION_VERSION_STALE');
        $this->assertSame(2, $manifest->fresh()->attestation_version, 'A rejected transition must never bump the counter.');
    }

    public function test_revoke_returns_409_for_a_still_pending_manifest_even_with_the_correct_attestation_version(): void
    {
        // This is the critical safety case: a version-only CAS check (WHERE
        // attestation_version = expected) would WRONGLY succeed here, since
        // a never-approved row's version (1) is still whatever the client
        // expects. The service must independently reject because 'pending'
        // is not a valid FROM-state for revoke() -- only 'approved' is.
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')
            ->deleteJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 1]);

        $response->assertStatus(409)->assertJsonPath('code', 'WIDGET_ATTESTATION_VERSION_STALE');
        $this->assertSame('pending', $manifest->fresh()->eligibility_state, 'A pending manifest must never become revoked.');
        $this->assertSame(1, $manifest->fresh()->attestation_version);
    }

    // ===================================================================
    // Durable audit: written on success, NEVER on a stale/failed attempt
    // ===================================================================

    public function test_successful_approve_writes_exactly_one_durable_audit_row(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), [
                'expected_attestation_version' => 1,
                'reason' => 'Looks safe.',
            ])->assertOk();

        $this->assertSame(1, AuditLog::query()->count());
        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('action.widget_template.approve', $row->action);
        $this->assertSame(WidgetArtifactManifestModel::class, $row->target_type);
        $this->assertSame($manifest->id, $row->target_id);
        $this->assertSame($approver->id, $row->user_id);
        $this->assertSame($manifest->manifest_hash, $row->changed['options']['manifest_hash']);
    }

    public function test_successful_revoke_writes_exactly_one_durable_audit_row(): void
    {
        $manifest = $this->createManifest('ExampleProject', [
            'eligibility_state' => 'approved',
            'attestation_version' => 2,
            'approved_by' => 999,
            'approved_at' => now(),
        ]);
        $approver = $this->approverUser();

        $this->actingAs($approver, 'sanctum')
            ->deleteJson($this->approvalUrl($manifest->manifest_hash), [
                'expected_attestation_version' => 2,
                'reason' => 'Revoking due to a reported issue.',
            ])->assertOk();

        $this->assertSame(1, AuditLog::query()->count());
        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('action.widget_template.revoke', $row->action);
    }

    public function test_a_stale_concurrent_approve_writes_no_misleading_audit_row(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 99])
            ->assertStatus(409);

        $this->assertSame(0, AuditLog::query()->count(), 'A rejected stale/wrong-state attempt must never write an audit row.');
    }

    public function test_a_permission_denial_writes_no_audit_row(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $this->actingAs($unprivileged, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 1])
            ->assertStatus(403);

        $this->assertSame(0, AuditLog::query()->count());
    }

    // ===================================================================
    // Canonical fields never mutate; only attestation metadata changes
    // ===================================================================

    public function test_approve_mutates_only_attestation_metadata_never_canonical_provenance(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 1])
            ->assertOk();

        $fresh = $manifest->fresh();
        $this->assertSame($manifest->manifest_hash, $fresh->manifest_hash);
        $this->assertSame($manifest->artifact_blob_id, $fresh->artifact_blob_id);
        $this->assertSame($manifest->executable_surface_fingerprint_hash, $fresh->executable_surface_fingerprint_hash);
        $this->assertSame($manifest->canonical_schema_hash, $fresh->canonical_schema_hash);
        $this->assertSame($manifest->trust_policy_version, $fresh->trust_policy_version);
        $this->assertSame($manifest->trust_lane, $fresh->trust_lane);
        $this->assertSame($manifest->generator_version, $fresh->generator_version);
        $this->assertSame($manifest->renderer_key, $fresh->renderer_key);
        $this->assertSame($manifest->renderer_version, $fresh->renderer_version);
    }

    public function test_revoke_preserves_the_original_approver_while_recording_the_revoker(): void
    {
        $manifest = $this->createManifest('ExampleProject', [
            'eligibility_state' => 'approved',
            'attestation_version' => 2,
            'approved_by' => 555,
            'approved_at' => now()->subHour(),
        ]);
        $approver = $this->approverUser();

        $this->actingAs($approver, 'sanctum')
            ->deleteJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 2])
            ->assertOk();

        $fresh = $manifest->fresh();
        $this->assertSame(555, $fresh->approved_by, 'Revoke must not erase who originally approved the manifest.');
        $this->assertSame($approver->id, $fresh->revoked_by);
        $this->assertNotNull($fresh->revoked_at);
    }

    // ===================================================================
    // Eligibility catalog inventory (GET) -- project isolation, permission,
    // safe projection
    // ===================================================================

    public function test_catalog_requires_authentication(): void
    {
        $this->getJson('/api/v2/admin/widget/catalog/manifests')->assertUnauthorized();
    }

    public function test_catalog_denies_a_user_without_the_approve_permission(): void
    {
        $unprivileged = $this->userOfType(UserType::ADMIN);

        $response = $this->actingAs($unprivileged, 'sanctum')
            ->getJson('/api/v2/admin/widget/catalog/manifests');

        $response->assertStatus(403)->assertJsonPath('code', 'WIDGET_PERMISSION_DENIED');
    }

    public function test_catalog_lists_only_manifests_inside_the_authorized_project(): void
    {
        $ownProject1 = $this->createManifest('ExampleProject');
        $ownProject2 = $this->createManifest('ExampleProject', ['eligibility_state' => 'approved', 'attestation_version' => 2, 'approved_by' => 1, 'approved_at' => now()]);
        $this->createManifest('SomeOtherProject');
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')->getJson('/api/v2/admin/widget/catalog/manifests');

        $response->assertOk();
        $hashes = collect($response->json('data'))->pluck('manifest_hash')->all();
        $this->assertContains($ownProject1->manifest_hash, $hashes);
        $this->assertContains($ownProject2->manifest_hash, $hashes);
        $this->assertCount(2, $hashes, 'Only the authorized project\'s manifests may appear.');
    }

    public function test_catalog_projects_only_safe_fields_never_source_or_storage_metadata(): void
    {
        $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')->getJson('/api/v2/admin/widget/catalog/manifests');

        $response->assertOk();
        $item = $response->json('data.0');
        $this->assertSame(
            ['manifest_hash', 'project_name', 'eligibility_state', 'attestation_version', 'policy_version', 'trust_lane', 'control_version', 'blocked', 'approved_by', 'approved_at', 'revoked_by', 'revoked_at'],
            array_keys($item)
        );
        $this->assertArrayNotHasKey('artifact_blob_id', $item);
        $this->assertArrayNotHasKey('canonical_schema_hash', $item);
    }

    public function test_catalog_is_paginated_with_the_frozen_default_and_cap(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createManifest('ExampleProject');
        }
        $approver = $this->approverUser();

        $response = $this->actingAs($approver, 'sanctum')->getJson('/api/v2/admin/widget/catalog/manifests?per_page=2');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    // ===================================================================
    // Route registration: static "materialize" segment stays reachable
    // alongside the new {manifest_hash}/approval wildcard routes.
    // ===================================================================

    public function test_approval_and_materialize_routes_coexist_without_the_wildcard_shadowing_the_static_segment(): void
    {
        $approvalRoute = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v2/admin/widget/catalog/manifests/{manifest_hash}/approval' && in_array('POST', $r->methods(), true));
        $materializeRoute = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v2/admin/widget/catalog/manifests/materialize');
        $catalogRoute = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v2/admin/widget/catalog/manifests' && in_array('GET', $r->methods(), true));

        $this->assertNotNull($approvalRoute);
        $this->assertNotNull($materializeRoute);
        $this->assertNotNull($catalogRoute);
    }

    // ===================================================================
    // The eligibility "gate": WidgetManifestApprovalService::attestationFor()
    // reused against Task 9c's frozen WidgetSiteLayoutEligibility, proving
    // real persisted approval/revocation data drives the SAME decision
    // engine future consumers (Instance create/publish/preview) will call.
    // ===================================================================

    private function fingerprintFor(WidgetArtifactManifestModel $row): WidgetExecutableSurfaceFingerprint
    {
        return new WidgetExecutableSurfaceFingerprint(
            jsHash: 'irrelevant-to-evaluate',
            themeContentHash: 'irrelevant-to-evaluate',
            themeCssHash: 'irrelevant-to-evaluate',
            detections: ['inline_event_handlers' => 0, 'script_tags' => 0, 'dangerous_urls' => 0, 'blade_includes' => 0, 'raw_echoes' => 0],
            detectionsHash: 'irrelevant-to-evaluate',
            requestSessionAuthServiceAccessCount: 0,
            requestSessionAuthServiceAccessHash: 'irrelevant-to-evaluate',
            providerSyntax: ['query_keys' => [], 'has_list' => false, 'has_image' => false, 'has_upload' => false, 'has_iseo' => false, 'has_plain_input_placeholder' => false],
            providerSyntaxHash: 'irrelevant-to-evaluate',
            canonicalSchemaHash: $row->canonical_schema_hash,
            artifactBytesHash: 'irrelevant-to-evaluate',
            policyVersion: $row->trust_policy_version,
            trustLane: $row->trust_lane,
            applicability: is_array($row->applicability) ? $row->applicability : ['ExampleProject'],
            applicabilityHash: 'irrelevant-to-evaluate',
            // The Task 9c fingerprint hash IS this row's own
            // executable_surface_fingerprint_hash column (see
            // WidgetManifestApprovalService::attestationFor()'s docblock for
            // the disclosed distinction from this row's OWN manifest_hash).
            manifestHash: $row->executable_surface_fingerprint_hash,
        );
    }

    public function test_gate_reports_no_attestation_for_a_never_approved_manifest(): void
    {
        $manifest = $this->createManifest('ExampleProject');

        $service = app(WidgetManifestApprovalService::class);
        $attestation = $service->attestationFor('ExampleProject', $manifest->manifest_hash);

        $this->assertNull($attestation);

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($this->fingerprintFor($manifest), $attestation);
        $this->assertFalse($decision->eligible);
        $this->assertSame(WidgetEligibilityDecision::REASON_NO_ATTESTATION, $decision->reasonCode);
    }

    public function test_gate_admits_an_approved_manifest_through_the_frozen_task_9c_engine(): void
    {
        $manifest = $this->createManifest('ExampleProject');
        $approver = $this->approverUser();

        $this->actingAs($approver, 'sanctum')
            ->postJson($this->approvalUrl($manifest->manifest_hash), ['expected_attestation_version' => 1])
            ->assertOk();

        $service = app(WidgetManifestApprovalService::class);
        $fresh = $manifest->fresh();
        $attestation = $service->attestationFor('ExampleProject', $fresh->manifest_hash);

        $this->assertNotNull($attestation);
        $this->assertFalse($attestation->revoked);

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($this->fingerprintFor($fresh), $attestation);
        $this->assertTrue($decision->eligible);
    }

    public function test_gate_denies_a_revoked_manifest_through_the_frozen_task_9c_engine(): void
    {
        $manifest = $this->createManifest('ExampleProject', [
            'eligibility_state' => 'revoked',
            'attestation_version' => 3,
            'approved_by' => 111,
            'approved_at' => now()->subDay(),
            'revoked_by' => 222,
            'revoked_at' => now(),
        ]);

        $service = app(WidgetManifestApprovalService::class);
        $attestation = $service->attestationFor('ExampleProject', $manifest->manifest_hash);

        $this->assertNotNull($attestation);
        $this->assertTrue($attestation->revoked);

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($this->fingerprintFor($manifest), $attestation);
        $this->assertFalse($decision->eligible);
        $this->assertSame(WidgetEligibilityDecision::REASON_REVOKED, $decision->reasonCode);
    }
}
