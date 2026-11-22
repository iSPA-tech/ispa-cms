<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\Instances;

use Cms\Widget\Instances\WidgetInstanceVersionAllocator;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

/**
 * Chunk 2 Task 16: {@see WidgetInstanceVersionAllocator} in isolation
 * (plan lines 993-998; architecture.md "Instance create, clone, and
 * update" step 5: "Lock the owning widget_instances row FOR UPDATE,
 * recheck the expected current Version, and allocate MAX(version)+1
 * under that lock").
 *
 * This allocator is the sole owner of the "recheck expected state" step
 * {@see WidgetAggregateLockOrder} deliberately declines to own (see that
 * class's own docblock). It is used ONLY for existing-Instance
 * updates/repin -- never for the atomic create/clone Version-1 insert,
 * which has no existing row to lock in the first place.
 */
final class WidgetInstanceVersionAllocatorTest extends TestCase
{
    use RefreshDatabase;

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

    private function allocator(): WidgetInstanceVersionAllocator
    {
        return new WidgetInstanceVersionAllocator(new WidgetAggregateLockOrder());
    }

    /**
     * Minimal valid Blob+Manifest fixture (satisfies the composite
     * restrictive FK from widget_instance_versions.artifact_manifest_id on
     * mysql/pgsql; harmless on sqlite where ALTER-added FKs are inert).
     */
    private function createManifest(string $projectName = 'Default'): WidgetArtifactManifest
    {
        $blob = WidgetArtifactBlob::query()->create([
            'project_name' => $projectName,
            'artifact_hash' => hash('sha256', 'allocator-test-' . uniqid('', true)),
            'storage_disk' => 'local',
            'storage_key' => 'allocator-test/' . uniqid('', true),
            'byte_size' => 10,
            'media_type' => 'text/x-blade-template',
            'checksum' => 'sha256:deadbeef',
        ]);

        return WidgetArtifactManifest::query()->create([
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
            'trust_policy_version' => 'v1',
            'trust_lane' => 'safe-legacy',
            'eligibility_state' => 'approved',
            'attestation_version' => 1,
            'control_version' => 1,
            'blocked' => false,
        ]);
    }

    private function createInstanceWithVersion1(string $projectName = 'Default'): WidgetInstance
    {
        $manifest = $this->createManifest($projectName);

        $instance = WidgetInstance::query()->create([
            'project_name' => $projectName,
            'name' => 'Allocator Test Instance',
            'status' => 'active',
        ]);

        $version = WidgetInstanceVersion::query()->create([
            'instance_id' => $instance->id,
            'project_name' => $projectName,
            'version' => 1,
            'widget_id' => 1,
            'widget_theme_id' => 1,
            'renderer_key' => 'legacy-blade-widget',
            'renderer_version' => 'v1',
            'trust_classification' => 'safe-legacy',
            'schema' => [],
            'schema_hash' => hash('sha256', '[]'),
            'values' => [],
            'provider_refs' => [],
            'artifact_manifest_id' => $manifest->id,
        ]);

        $instance->current_version_id = $version->id;
        $instance->save();

        return $instance->fresh();
    }

    public function test_allocates_max_version_plus_one_when_expected_current_version_matches(): void
    {
        $instance = $this->createInstanceWithVersion1();
        $invoked = false;

        $result = $this->allocator()->allocate(
            $instance->id,
            1, // matches the fixture's Version 1 (client-facing version NUMBER, never a raw row id)
            function (WidgetInstance $lockedInstance, int $nextVersion) use (&$invoked, $instance) {
                $invoked = true;
                $this->assertSame($instance->id, $lockedInstance->id);

                return $nextVersion;
            }
        );

        $this->assertTrue($invoked, 'The callback must run when the expected current version matches.');
        $this->assertTrue($result->isAllocated());
        $this->assertSame(2, $result->version);
        $this->assertSame(2, $result->value);
    }

    /**
     * Discriminates "compares by the client-facing version NUMBER" from
     * "compares by the internal current_version_id row id" -- those two
     * coincide for the very first row in a freshly migrated table, which
     * would make a naive row-id comparison pass this suite's other tests
     * for the wrong reason. Creating unrelated noise Version rows for a
     * DIFFERENT instance first desynchronizes row id from version number
     * for the instance under test, so only a genuine version-NUMBER
     * comparison can pass here (widget_instance_versions carries no uuid
     * of its own -- the version number scoped to its Instance is the only
     * client-facing identifier, matching this codebase's UUID-first
     * admin-API rule of never requiring a raw internal row id from a
     * caller).
     */
    public function test_matches_by_the_client_facing_version_number_not_the_internal_row_id(): void
    {
        $noiseInstance = $this->createInstanceWithVersion1();
        $noiseManifest = $this->createManifest($noiseInstance->project_name);
        // Two more noise rows so the next real row's autoincrement id is
        // guaranteed to differ from version number 1.
        for ($i = 0; $i < 2; $i++) {
            WidgetInstanceVersion::query()->create([
                'instance_id' => $noiseInstance->id,
                'project_name' => $noiseInstance->project_name,
                'version' => 90 + $i,
                'widget_id' => 1,
                'widget_theme_id' => 1,
                'renderer_key' => 'legacy-blade-widget',
                'renderer_version' => 'v1',
                'trust_classification' => 'safe-legacy',
                'schema' => [],
                'schema_hash' => hash('sha256', '[]'),
                'values' => [],
                'provider_refs' => [],
                'artifact_manifest_id' => $noiseManifest->id,
            ]);
        }

        $instance = $this->createInstanceWithVersion1();
        $currentVersionRow = WidgetInstanceVersion::query()->find($instance->current_version_id);

        $this->assertSame(1, $currentVersionRow->version, 'Sanity: this Instance\'s current Version is number 1.');
        $this->assertNotSame(1, $currentVersionRow->id, 'Sanity: the row id must have drifted away from 1 for this test to discriminate anything.');

        $invoked = false;
        $result = $this->allocator()->allocate(
            $instance->id,
            1, // the client-facing version NUMBER, never the (now-different) row id
            function () use (&$invoked) {
                $invoked = true;

                return 'ok';
            }
        );

        $this->assertTrue($invoked, 'A version-NUMBER match must invoke the callback even though it differs from the internal row id.');
        $this->assertTrue($result->isAllocated());
    }

    public function test_reports_stale_and_never_invokes_the_callback_when_expected_current_version_does_not_match(): void
    {
        $instance = $this->createInstanceWithVersion1();
        $invoked = false;

        $result = $this->allocator()->allocate(
            $instance->id,
            999999, // wrong expected current version NUMBER
            function () use (&$invoked) {
                $invoked = true;

                return 'should-never-run';
            }
        );

        $this->assertFalse($invoked, 'A stale expected current Version must never invoke the mutation callback.');
        $this->assertTrue($result->isStale());
        $this->assertNull($result->version);
        $this->assertSame(
            1,
            WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(),
            'A stale allocation attempt must insert no new Version row.'
        );
    }

    public function test_reports_instance_not_found_and_never_invokes_the_callback_for_an_unknown_instance_id(): void
    {
        $invoked = false;

        $result = $this->allocator()->allocate(
            999999,
            null,
            function () use (&$invoked) {
                $invoked = true;

                return 'should-never-run';
            }
        );

        $this->assertFalse($invoked);
        $this->assertTrue($result->isNotFound());
    }

    public function test_two_sequential_allocations_with_different_expected_versions_yield_n_plus_one_then_n_plus_two(): void
    {
        $instance = $this->createInstanceWithVersion1();
        $manifest = $this->createManifest($instance->project_name);

        $first = $this->allocator()->allocate(
            $instance->id,
            1, // the fixture's Version 1
            function (WidgetInstance $lockedInstance, int $nextVersion) use ($manifest) {
                $version = WidgetInstanceVersion::query()->create([
                    'instance_id' => $lockedInstance->id,
                    'project_name' => $lockedInstance->project_name,
                    'version' => $nextVersion,
                    'widget_id' => 1,
                    'widget_theme_id' => 1,
                    'renderer_key' => 'legacy-blade-widget',
                    'renderer_version' => 'v1',
                    'trust_classification' => 'safe-legacy',
                    'schema' => [],
                    'schema_hash' => hash('sha256', '[]'),
                    'values' => [],
                    'provider_refs' => [],
                    'artifact_manifest_id' => $manifest->id,
                ]);
                $lockedInstance->current_version_id = $version->id;
                $lockedInstance->save();

                return $version;
            }
        );

        $this->assertTrue($first->isAllocated());
        $this->assertSame(2, $first->version);

        $second = $this->allocator()->allocate(
            $instance->id,
            $first->value->version,
            function (WidgetInstance $lockedInstance, int $nextVersion) use ($manifest) {
                $version = WidgetInstanceVersion::query()->create([
                    'instance_id' => $lockedInstance->id,
                    'project_name' => $lockedInstance->project_name,
                    'version' => $nextVersion,
                    'widget_id' => 1,
                    'widget_theme_id' => 1,
                    'renderer_key' => 'legacy-blade-widget',
                    'renderer_version' => 'v1',
                    'trust_classification' => 'safe-legacy',
                    'schema' => [],
                    'schema_hash' => hash('sha256', '[]'),
                    'values' => [],
                    'provider_refs' => [],
                    'artifact_manifest_id' => $manifest->id,
                ]);
                $lockedInstance->current_version_id = $version->id;
                $lockedInstance->save();

                return $version;
            }
        );

        $this->assertTrue($second->isAllocated());
        $this->assertSame(3, $second->version);
        $this->assertSame(
            3,
            WidgetInstanceVersion::query()->where('instance_id', $instance->id)->count(),
            'Version 1 (fixture) plus the two allocated versions.'
        );
    }
}
