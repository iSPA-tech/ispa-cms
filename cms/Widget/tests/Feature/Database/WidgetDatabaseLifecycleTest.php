<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Database;

use Cms\Widget\Constants\WidgetTableConstant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pointer / state-machine / append-only / lineage proof for the Widget Site
 * Layout schema (plan Chunk 2 Task 13a). Every row is built with raw
 * `DB::table()` calls -- this file proves the SCHEMA's own two-step cyclic
 * pointers and append-only shape work end-to-end, independent of any future
 * service layer (Task 15a+ owns real immutability enforcement; see
 * architecture.md "Persistence Model" and adr-widget-instance-scope-schema.md
 * decision 13).
 *
 * SCOPE: pointer/state/append-only/lineage behavior only. Table/column shape
 * lives in {@see WidgetSiteLayoutSchemaTest}; negative composite-FK rejection
 * lives in {@see WidgetDatabaseIntegrityTest}.
 */
final class WidgetDatabaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * See WidgetSiteLayoutSchemaTest::beforeRefreshingDatabase() for the
     * full explanation: the Widget module stays disabled by design, so its
     * migration path must be registered manually before RefreshDatabase's
     * migrate:fresh runs.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * See WidgetSiteLayoutSchemaTest::migrateFreshUsing() for the full
     * explanation: scope migrate:fresh to only this module's migrations.
     */
    protected function migrateFreshUsing()
    {
        return [
            '--path' => 'cms/Widget/database/migrations',
            '--realpath' => false,
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ];
    }

    /**
     * See WidgetSiteLayoutSchemaTest::tearDownAfterClass() for the full
     * explanation: reset the process-wide RefreshDatabaseState::$migrated
     * flag so this module-scoped migrate:fresh never leaks into whichever
     * unrelated test class runs next in the same process.
     */
    public static function tearDownAfterClass(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_instance_identity_exists_before_any_version_and_pointer_sets_only_after(): void
    {
        $instanceId = $this->insertInstance('ExampleProject');

        $row = DB::table(WidgetTableConstant::INSTANCE)->find($instanceId);
        $this->assertNull($row->current_version_id, 'A freshly created Instance has no current Version yet.');

        $blobId = $this->insertBlob('ExampleProject', 'blob-hash-1');
        $manifestId = $this->insertManifest('ExampleProject', $blobId, 'manifest-hash-1');
        $versionId = $this->insertInstanceVersion('ExampleProject', $instanceId, $manifestId, 1);

        // The pointer is only wired after the exact Blob is verified/readable
        // and the exact Manifest is approved -- simulated here by the mere
        // existence of the (already-inserted) Blob/Manifest rows, matching
        // this task's schema-only scope (materialization/reconciliation is
        // Task 15a/15b).
        DB::table(WidgetTableConstant::INSTANCE)->where('id', $instanceId)->update(['current_version_id' => $versionId]);

        $row = DB::table(WidgetTableConstant::INSTANCE)->find($instanceId);
        $this->assertSame($versionId, $row->current_version_id);
    }

    public function test_two_step_cyclic_pointer_graph_round_trips_end_to_end(): void
    {
        $project = 'ExampleProject';

        $blobId = $this->insertBlob($project, 'blob-hash-graph');
        $manifestId = $this->insertManifest($project, $blobId, 'manifest-hash-graph');

        $instanceId = $this->insertInstance($project);
        $versionId = $this->insertInstanceVersion($project, $instanceId, $manifestId, 1);
        DB::table(WidgetTableConstant::INSTANCE)->where('id', $instanceId)->update(['current_version_id' => $versionId]);

        $layoutId = $this->insertLayout($project, 'site');
        $draftId = $this->insertLayoutVersion($project, $layoutId, 1, 'draft');
        DB::table(WidgetTableConstant::LAYOUT)->where('id', $layoutId)->update(['current_draft_version_id' => $draftId]);

        $placementId = $this->insertPlacement($project, $draftId, $versionId);

        // Re-query fresh and walk the whole graph via the pointers alone.
        $instance = DB::table(WidgetTableConstant::INSTANCE)->find($instanceId);
        $currentVersion = DB::table(WidgetTableConstant::INSTANCE_VERSION)->find($instance->current_version_id);
        $this->assertSame($instanceId, $currentVersion->instance_id);

        $manifest = DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->find($currentVersion->artifact_manifest_id);
        $blob = DB::table(WidgetTableConstant::ARTIFACT_BLOB)->find($manifest->artifact_blob_id);
        $this->assertSame($project, $blob->project_name);

        $layout = DB::table(WidgetTableConstant::LAYOUT)->find($layoutId);
        $draft = DB::table(WidgetTableConstant::LAYOUT_VERSION)->find($layout->current_draft_version_id);
        $this->assertSame($layoutId, $draft->layout_id);
        $this->assertSame('draft', $draft->state);

        $placement = DB::table(WidgetTableConstant::PLACEMENT)->find($placementId);
        $this->assertSame($draft->id, $placement->layout_version_id);
        $this->assertSame($currentVersion->id, $placement->widget_instance_version_id);
    }

    public function test_layout_version_state_is_freely_settable_string_with_no_database_enum(): void
    {
        $project = 'ExampleProject';
        $layoutId = $this->insertLayout($project, 'site');
        $versionId = $this->insertLayoutVersion($project, $layoutId, 1, 'draft');

        foreach (['draft', 'superseded', 'release'] as $state) {
            DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('id', $versionId)->update(['state' => $state]);
            $row = DB::table(WidgetTableConstant::LAYOUT_VERSION)->find($versionId);
            $this->assertSame($state, $row->state);
        }

        // Functional "no DB enum" proof: the database itself does not reject
        // an arbitrary string outside the three app-level states -- the
        // three-value contract (draft|superseded|release) is enforced at the
        // application/cast layer, per plan "no database enum".
        DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('id', $versionId)->update(['state' => 'not_a_real_app_state']);
        $row = DB::table(WidgetTableConstant::LAYOUT_VERSION)->find($versionId);
        $this->assertSame('not_a_real_app_state', $row->state);
    }

    public function test_only_the_current_draft_pointer_row_is_treated_as_mutable_by_convention(): void
    {
        $project = 'ExampleProject';
        $layoutId = $this->insertLayout($project, 'site');
        $draftId = $this->insertLayoutVersion($project, $layoutId, 1, 'draft');
        DB::table(WidgetTableConstant::LAYOUT)->where('id', $layoutId)->update(['current_draft_version_id' => $draftId]);

        // The mutable row is discoverable purely from the pointer.
        $layout = DB::table(WidgetTableConstant::LAYOUT)->find($layoutId);
        $this->assertSame($draftId, $layout->current_draft_version_id);

        DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('id', $draftId)->update(['registry_contract_hash' => str_repeat('a', 64)]);
        $row = DB::table(WidgetTableConstant::LAYOUT_VERSION)->find($draftId);
        $this->assertSame(str_repeat('a', 64), $row->registry_contract_hash);
    }

    public function test_publish_is_append_only_and_retains_lineage(): void
    {
        $project = 'ExampleProject';
        $layoutId = $this->insertLayout($project, 'site');
        $draftV1 = $this->insertLayoutVersion($project, $layoutId, 1, 'draft');
        DB::table(WidgetTableConstant::LAYOUT)->where('id', $layoutId)->update(['current_draft_version_id' => $draftV1]);

        $countBefore = DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('layout_id', $layoutId)->count();

        // Simulate a publish: draft v1 becomes the release, superseded by a
        // fresh draft v2 based on it. No row is deleted (append-only).
        DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('id', $draftV1)->update(['state' => 'release', 'published_at' => now(), 'published_by' => 7]);
        $draftV2 = $this->insertLayoutVersion($project, $layoutId, 2, 'draft', ['based_on_version_id' => $draftV1]);
        DB::table(WidgetTableConstant::LAYOUT)->where('id', $layoutId)->update([
            'current_draft_version_id' => $draftV2,
            'current_published_version_id' => $draftV1,
        ]);

        $countAfter = DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('layout_id', $layoutId)->count();
        $this->assertSame($countBefore + 1, $countAfter, 'Publish only adds a fresh draft row -- nothing is deleted.');

        $release = DB::table(WidgetTableConstant::LAYOUT_VERSION)->find($draftV1);
        $this->assertSame('release', $release->state);
        $this->assertNotNull($release->published_at);

        $freshDraft = DB::table(WidgetTableConstant::LAYOUT_VERSION)->find($draftV2);
        $this->assertSame($draftV1, $freshDraft->based_on_version_id);

        // Rollback to v1: reserves release v3 (rollback_of_version_id = v1)
        // and a fresh draft v4 (based_on_version_id = v3). History (v1, v2)
        // is retained, never reactivated in place.
        $releaseV3 = $this->insertLayoutVersion($project, $layoutId, 3, 'release', ['rollback_of_version_id' => $draftV1, 'published_at' => now(), 'published_by' => 7]);
        $draftV4 = $this->insertLayoutVersion($project, $layoutId, 4, 'draft', ['based_on_version_id' => $releaseV3]);
        DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('id', $draftV2)->update(['state' => 'superseded']);
        DB::table(WidgetTableConstant::LAYOUT)->where('id', $layoutId)->update([
            'current_draft_version_id' => $draftV4,
            'current_published_version_id' => $releaseV3,
        ]);

        $finalCount = DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('layout_id', $layoutId)->count();
        $this->assertSame($countBefore + 3, $finalCount, 'Rollback never deletes -- it reserves two fresh numbers (release + draft).');

        // No duplicate (layout_id, version).
        $versions = DB::table(WidgetTableConstant::LAYOUT_VERSION)->where('layout_id', $layoutId)->pluck('version')->all();
        $this->assertSame(count($versions), count(array_unique($versions)), 'No duplicate (layout_id, version) across publish/rollback.');

        $rollbackRow = DB::table(WidgetTableConstant::LAYOUT_VERSION)->find($releaseV3);
        $this->assertSame($draftV1, $rollbackRow->rollback_of_version_id);
        $this->assertNotSame($draftV1, $rollbackRow->id, 'Rollback creates a NEW Release snapshot, never reactivates the historical Release row.');

        // Original v1 release row is still present and queryable, unchanged identity.
        $this->assertNotNull(DB::table(WidgetTableConstant::LAYOUT_VERSION)->find($draftV1));
    }

    public function test_manifest_eligibility_and_emergency_control_are_independent_and_monotonic_while_canonical_fields_never_mutate(): void
    {
        $project = 'ExampleProject';
        $blobId = $this->insertBlob($project, 'blob-hash-manifest-lifecycle');
        $manifestId = $this->insertManifest($project, $blobId, 'manifest-hash-lifecycle');

        $original = DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->find($manifestId);
        $this->assertSame('pending', $original->eligibility_state);
        $this->assertSame(1, $original->attestation_version);
        $this->assertSame(1, $original->control_version);
        $this->assertSame(0, (int) $original->blocked);

        // Approve: eligibility_state changes, attestation_version increments monotonically.
        DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->where('id', $manifestId)->update([
            'eligibility_state' => 'approved',
            'attestation_version' => 2,
            'approved_by' => 3,
            'approved_at' => now(),
        ]);

        // Emergency block: control_version increments independently of eligibility.
        DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->where('id', $manifestId)->update([
            'blocked' => true,
            'control_version' => 2,
            'blocked_by' => 9,
            'blocked_at' => now(),
            'control_reason' => 'incident-123',
        ]);

        $afterBlock = DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->find($manifestId);
        $this->assertSame('approved', $afterBlock->eligibility_state, 'Eligibility state is untouched by the emergency control transition.');
        $this->assertSame(2, $afterBlock->attestation_version);
        $this->assertSame(2, $afterBlock->control_version);
        $this->assertSame(1, (int) $afterBlock->blocked);

        // Clear the block: control_version increments again, eligibility_state/attestation_version untouched.
        DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->where('id', $manifestId)->update([
            'blocked' => false,
            'control_version' => 3,
            'cleared_by' => 9,
            'cleared_at' => now(),
        ]);

        $final = DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->find($manifestId);
        $this->assertSame(3, $final->control_version);
        $this->assertSame(2, $final->attestation_version, 'attestation_version is independent of control_version -- clearing a block never bumps it.');
        $this->assertSame(0, (int) $final->blocked);

        // Canonical provenance fields never mutate across the whole lifecycle.
        $this->assertSame($original->manifest_hash, $final->manifest_hash);
        $this->assertSame($original->artifact_blob_id, $final->artifact_blob_id);
        $this->assertSame($original->executable_surface_fingerprint_hash, $final->executable_surface_fingerprint_hash);
        $this->assertSame($original->canonical_schema_hash, $final->canonical_schema_hash);
        $this->assertSame($original->project_name, $final->project_name);
    }

    public function test_idempotency_key_processing_to_committed_transition_with_safe_identity_only(): void
    {
        $project = 'ExampleProject';
        $keyId = $this->insertIdempotencyKey($project, 'instance_create', 'caller-key-abc');

        $row = DB::table(WidgetTableConstant::IDEMPOTENCY_KEY)->find($keyId);
        $this->assertSame('processing', $row->state);
        $this->assertNull($row->resource_id);

        DB::table(WidgetTableConstant::IDEMPOTENCY_KEY)->where('id', $keyId)->update([
            'state' => 'committed',
            'http_status' => 201,
            'resource_type' => 'widget_instance',
            'resource_id' => (string) Str::uuid(),
            'resource_version' => '1',
        ]);

        $committed = DB::table(WidgetTableConstant::IDEMPOTENCY_KEY)->find($keyId);
        $this->assertSame('committed', $committed->state);
        $this->assertSame(201, $committed->http_status);

        // No DB enum on state either -- proven the same functional way.
        DB::table(WidgetTableConstant::IDEMPOTENCY_KEY)->where('id', $keyId)->update(['state' => 'anything']);
        $this->assertSame('anything', DB::table(WidgetTableConstant::IDEMPOTENCY_KEY)->find($keyId)->state);
    }

    public function test_idempotency_key_same_scope_uniqueness_is_database_enforced(): void
    {
        $project = 'ExampleProject';
        $this->insertIdempotencyKey($project, 'instance_create', 'dup-key');

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->insertIdempotencyKey($project, 'instance_create', 'dup-key');
    }

    private function insertBlob(string $project, string $hash): int
    {
        return DB::table(WidgetTableConstant::ARTIFACT_BLOB)->insertGetId([
            'project_name' => $project,
            'artifact_hash' => str_pad(substr($hash, 0, 64), 64, '0'),
            'storage_disk' => 'local',
            'storage_key' => "generated_widgets/{$project}/{$hash}",
            'byte_size' => 1024,
            'media_type' => 'text/x-blade',
            'checksum' => str_pad(substr($hash, 0, 64), 64, '0'),
            'created_at' => now(),
        ]);
    }

    private function insertManifest(string $project, int $blobId, string $hash, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::ARTIFACT_MANIFEST)->insertGetId(array_merge([
            'project_name' => $project,
            'manifest_hash' => str_pad(substr($hash, 0, 64), 64, '0'),
            'artifact_blob_id' => $blobId,
            'generator_version' => '1.0.0',
            'renderer_key' => 'legacy_inline',
            'renderer_version' => '1.0.0',
            'applicability' => json_encode([$project]),
            'executable_surface_fingerprint_hash' => str_repeat('b', 64),
            'canonical_schema_hash' => str_repeat('c', 64),
            'provider_contracts' => json_encode([]),
            'trust_policy_version' => '1',
            'trust_lane' => 'legacy',
            'eligibility_state' => 'pending',
            'attestation_version' => 1,
            'control_version' => 1,
            'blocked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertInstance(string $project, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::INSTANCE)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'name' => 'Header banner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertInstanceVersion(string $project, int $instanceId, int $manifestId, int $version, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::INSTANCE_VERSION)->insertGetId(array_merge([
            'instance_id' => $instanceId,
            'project_name' => $project,
            'version' => $version,
            'widget_id' => 1,
            'widget_theme_id' => 1,
            'renderer_key' => 'legacy_inline',
            'renderer_version' => '1.0.0',
            'trust_classification' => 'legacy',
            'schema' => json_encode(['type' => 'object']),
            'schema_hash' => str_repeat('d', 64),
            'values' => json_encode(['title' => 'Hello']),
            'provider_refs' => json_encode([]),
            'artifact_manifest_id' => $manifestId,
            'created_at' => now(),
        ], $overrides));
    }

    private function insertLayout(string $project, string $key = 'site', array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::LAYOUT)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertLayoutVersion(string $project, int $layoutId, int $version, string $state = 'draft', array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::LAYOUT_VERSION)->insertGetId(array_merge([
            'layout_id' => $layoutId,
            'project_name' => $project,
            'version' => $version,
            'state' => $state,
            'lock_version' => 0,
            'registry_contracts' => json_encode(['slots' => []]),
            'registry_contract_hash' => str_repeat('e', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertPlacement(string $project, int $layoutVersionId, int $instanceVersionId, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::PLACEMENT)->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'project_name' => $project,
            'layout_version_id' => $layoutVersionId,
            'widget_instance_version_id' => $instanceVersionId,
            'slot_key' => 'site.header.before',
            'scope_type' => 'global',
            'scope_identity' => 'global',
            'position' => 0,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertIdempotencyKey(string $project, string $action, string $key, array $overrides = []): int
    {
        return DB::table(WidgetTableConstant::IDEMPOTENCY_KEY)->insertGetId(array_merge([
            'project_name' => $project,
            'action' => $action,
            'resource_scope' => '',
            'idempotency_key' => $key,
            'request_hash' => str_repeat('f', 64),
            'state' => 'processing',
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
