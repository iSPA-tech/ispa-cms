<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Database;

use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactManifestRepository;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobDescriptor;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Chunk 2 Task 13b: {@see DatabaseWidgetArtifactManifestRepository}-specific
 * proofs that go beyond the reusable
 * {@see \Cms\Widget\Tests\Support\WidgetArtifactRepositoryContractAssertions}
 * trait (already exercised end-to-end for this repository by
 * {@see WidgetArtifactBlobRepositoryTest}): real-row Blob<->Manifest FK
 * linkage, the defensive "blob not published yet" guard, the two CAS
 * primitives (attestation_version / control_version), and descriptor reload
 * equality.
 *
 * Direct DB-layer cross-project FK rejection for `artifact_blob_id` is
 * ALREADY proven by
 * {@see \Cms\Widget\Tests\Feature\Database\WidgetDatabaseIntegrityTest::test_manifest_rejects_cross_project_blob_reference()}
 * (Task 13a) via raw DB::table() writes that bypass application code
 * entirely -- this file does not re-prove that composite FK, only this
 * repository's own defensive application-level behavior around it.
 */
final class WidgetArtifactManifestRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    private FilesystemWidgetArtifactBlobStore $filesystemStore;

    private DatabaseWidgetArtifactBlobRepository $blobRepository;

    private DatabaseWidgetArtifactManifestRepository $manifestRepository;

    /**
     * See WidgetSiteLayoutSchemaTest::beforeRefreshingDatabase() (Task 13a).
     */
    protected function beforeRefreshingDatabase(): void
    {
        // See WidgetArtifactBlobRepositoryTest::beforeRefreshingDatabase()
        // for the full explanation: force a real migrate:fresh for THIS
        // class regardless of what an earlier-running, non-Widget-scoped
        // RefreshDatabase test left the process-wide
        // RefreshDatabaseState::$migrated flag set to.
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;

        $this->app->afterResolving('migrator', static function ($migrator): void {
            $migrator->path(base_path('cms/Widget/database/migrations'));
        });
    }

    /**
     * See WidgetSiteLayoutSchemaTest::migrateFreshUsing() (Task 13a).
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
     * See WidgetSiteLayoutSchemaTest::tearDownAfterClass() (Task 13a).
     */
    public static function tearDownAfterClass(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = storage_path('framework/testing/widget-artifacts-manifest-' . bin2hex(random_bytes(6)));
        $this->filesystemStore = new FilesystemWidgetArtifactBlobStore($this->storageRoot);
        $this->blobRepository = new DatabaseWidgetArtifactBlobRepository($this->filesystemStore);
        $this->manifestRepository = new DatabaseWidgetArtifactManifestRepository();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // One-Blob/many-Manifests via real rows
    // -----------------------------------------------------------------

    public function test_putPending_persists_a_new_row_with_the_correct_blob_foreign_key(): void
    {
        $manifest = $this->buildManifest('ProjectA', 'contract-hash-1');

        $persisted = $this->manifestRepository->putPending($manifest);

        $row = WidgetArtifactManifestModel::query()
            ->where('project_name', 'ProjectA')
            ->where('manifest_hash', $manifest->manifestHash)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(WidgetArtifactManifest::STATUS_PENDING, $persisted->status);

        $blobRow = WidgetArtifactBlob::query()
            ->where('project_name', 'ProjectA')
            ->where('artifact_hash', $manifest->artifactBlob->artifactHash)
            ->first();

        $this->assertNotNull($blobRow);
        $this->assertSame($blobRow->id, $row->artifact_blob_id, 'The persisted row must reference the real Blob row by id.');
    }

    public function test_many_manifests_persist_against_one_real_blob_row(): void
    {
        // Same bytes (-> same artifact_hash) for both, distinguished only by
        // provider contract hash (-> different manifest_hash).
        $sharedBytes = '<div>shared-bytes-for-one-blob-many-manifests</div>';
        $manifestA = $this->buildManifest('ProjectA', 'contract-hash-a', $sharedBytes);
        $manifestB = $this->buildManifest('ProjectA', 'contract-hash-b', $sharedBytes);

        $this->manifestRepository->putPending($manifestA);
        $this->manifestRepository->putPending($manifestB);

        $this->assertSame(1, WidgetArtifactBlob::query()->where('project_name', 'ProjectA')->count(), 'Identical bytes -- one Blob row.');
        $this->assertSame(2, WidgetArtifactManifestModel::query()->where('project_name', 'ProjectA')->count(), 'Distinct provider contracts -- two Manifest rows.');

        $rows = WidgetArtifactManifestModel::query()->where('project_name', 'ProjectA')->get();
        $this->assertCount(1, $rows->pluck('artifact_blob_id')->unique(), 'Both Manifest rows reference the SAME Blob row id.');
    }

    public function test_putPending_is_idempotent_and_never_creates_a_duplicate_row(): void
    {
        $manifest = $this->buildManifest('ProjectA', 'contract-hash-idem');

        $first = $this->manifestRepository->putPending($manifest);
        $second = $this->manifestRepository->putPending($manifest);

        $this->assertSame($first->manifestHash, $second->manifestHash);
        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ProjectA')->count());
    }

    public function test_putPending_rejects_a_manifest_whose_blob_was_never_published_in_that_project(): void
    {
        // Build a Manifest VO referencing a Blob descriptor that was never
        // actually persisted via the Blob repository -- simulates a caller
        // bug (or a genuinely cross-project descriptor) that this
        // repository must catch defensively before ever attempting an
        // insert doomed to fail the composite FK.
        $unpublishedBlob = new WidgetArtifactBlobDescriptor(
            projectName: 'ProjectA',
            artifactHash: hash('sha256', 'never actually published'),
            byteSize: 10,
            mediaType: 'text/x-blade-php',
            checksum: hash('sha256', 'never actually published'),
            storageDisk: 'widget_artifacts_local',
            storageKey: 'ProjectA/00/never',
            createdAt: new \DateTimeImmutable(),
        );

        $manifest = new WidgetArtifactManifest(
            projectName: 'ProjectA',
            manifestHash: hash('sha256', 'manifest-for-unpublished-blob'),
            artifactBlob: $unpublishedBlob,
            generatorVersion: 'v1',
            rendererKey: 'legacy-blade',
            rendererVersion: 'v1',
            applicability: ['ProjectA'],
            executableSurfaceFingerprintHash: str_repeat('a', 64),
            canonicalSchemaHash: str_repeat('b', 64),
            providerContracts: [],
            trustPolicyVersion: '1',
            trustLane: 'legacy',
            createdAt: new \DateTimeImmutable(),
        );

        $this->expectException(InvalidArgumentException::class);

        $this->manifestRepository->putPending($manifest);
    }

    public function test_findByProjectAndManifestHash_returns_null_on_a_genuine_miss(): void
    {
        $this->assertNull($this->manifestRepository->findByProjectAndManifestHash('ProjectA', str_repeat('f', 64)));
    }

    // -----------------------------------------------------------------
    // Descriptor reload equality
    // -----------------------------------------------------------------

    public function test_descriptor_reload_produces_an_equal_manifest_value_object(): void
    {
        $manifest = $this->buildManifest('ProjectA', 'contract-hash-reload');
        $this->manifestRepository->putPending($manifest);

        $first = $this->manifestRepository->findByProjectAndManifestHash('ProjectA', $manifest->manifestHash);
        $second = $this->manifestRepository->findByProjectAndManifestHash('ProjectA', $manifest->manifestHash);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertEquals($first, $second, 'Reloading the same identity twice must produce value-equal VOs.');
        $this->assertNotSame($first, $second, 'Each reload constructs a fresh VO instance, not a cached reference.');
    }

    // -----------------------------------------------------------------
    // Attestation / control compare-and-swap primitives
    // -----------------------------------------------------------------

    public function test_compare_and_swap_attestation_succeeds_with_the_matching_expected_version(): void
    {
        $manifest = $this->buildManifest('ProjectA', 'contract-hash-cas-1');
        $this->manifestRepository->putPending($manifest);

        $succeeded = $this->manifestRepository->compareAndSwapAttestation(
            'ProjectA',
            $manifest->manifestHash,
            1,
            ['eligibility_state' => 'approved', 'attestation_version' => 2, 'approved_by' => 7]
        );

        $this->assertTrue($succeeded);

        $row = WidgetArtifactManifestModel::query()->where('project_name', 'ProjectA')->where('manifest_hash', $manifest->manifestHash)->first();
        $this->assertSame('approved', $row->eligibility_state);
        $this->assertSame(2, $row->attestation_version);
    }

    public function test_compare_and_swap_attestation_fails_and_changes_nothing_with_a_stale_expected_version(): void
    {
        $manifest = $this->buildManifest('ProjectA', 'contract-hash-cas-2');
        $this->manifestRepository->putPending($manifest);

        // Real current version is 1 -- claim we expect 99 (stale).
        $succeeded = $this->manifestRepository->compareAndSwapAttestation(
            'ProjectA',
            $manifest->manifestHash,
            99,
            ['eligibility_state' => 'approved', 'attestation_version' => 100]
        );

        $this->assertFalse($succeeded);

        $row = WidgetArtifactManifestModel::query()->where('project_name', 'ProjectA')->where('manifest_hash', $manifest->manifestHash)->first();
        $this->assertSame('pending', $row->eligibility_state, 'A failed CAS must leave the row completely unchanged.');
        $this->assertSame(1, $row->attestation_version);
    }

    public function test_compare_and_swap_attestation_rejects_changes_that_omit_the_new_attestation_version(): void
    {
        // Post-review rider guard: omitting the new counter value would make
        // every "successful" CAS leave attestation_version unchanged, so the
        // same expected version keeps matching forever (silent
        // always-succeeds ABA). The primitive throws instead.
        $manifest = $this->buildManifest('ProjectA', 'contract-hash-cas-guard-1');
        $this->manifestRepository->putPending($manifest);

        $this->expectException(InvalidArgumentException::class);

        $this->manifestRepository->compareAndSwapAttestation(
            'ProjectA',
            $manifest->manifestHash,
            1,
            ['eligibility_state' => 'approved'] // no attestation_version
        );
    }

    public function test_compare_and_swap_control_rejects_changes_that_omit_the_new_control_version(): void
    {
        $manifest = $this->buildManifest('ProjectA', 'contract-hash-cas-guard-2');
        $this->manifestRepository->putPending($manifest);

        $this->expectException(InvalidArgumentException::class);

        $this->manifestRepository->compareAndSwapControl(
            'ProjectA',
            $manifest->manifestHash,
            1,
            ['blocked' => true] // no control_version
        );
    }

    public function test_compare_and_swap_control_is_independent_of_attestation_version(): void
    {
        $manifest = $this->buildManifest('ProjectA', 'contract-hash-cas-3');
        $this->manifestRepository->putPending($manifest);

        $this->assertTrue($this->manifestRepository->compareAndSwapAttestation(
            'ProjectA',
            $manifest->manifestHash,
            1,
            ['eligibility_state' => 'approved', 'attestation_version' => 2]
        ));

        $controlSucceeded = $this->manifestRepository->compareAndSwapControl(
            'ProjectA',
            $manifest->manifestHash,
            1,
            ['blocked' => true, 'control_version' => 2, 'control_reason' => 'incident-1']
        );

        $this->assertTrue($controlSucceeded, 'control_version CAS must succeed independently of the already-advanced attestation_version.');

        $row = WidgetArtifactManifestModel::query()->where('project_name', 'ProjectA')->where('manifest_hash', $manifest->manifestHash)->first();
        $this->assertSame(2, $row->attestation_version);
        $this->assertSame(2, $row->control_version);
        $this->assertSame(1, (int) $row->blocked);
        $this->assertSame('approved', $row->eligibility_state, 'The control-axis CAS must never touch eligibility_state.');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function buildManifest(string $projectName, string $contractHash, ?string $bytes = null): WidgetArtifactManifest
    {
        $bytes ??= '<div>' . $contractHash . '</div>';
        $artifactHash = hash('sha256', $bytes);

        $blobDescriptor = $this->blobRepository->put($projectName, $artifactHash, $bytes, 'text/x-blade-php');

        return new WidgetArtifactManifest(
            projectName: $projectName,
            manifestHash: hash('sha256', $contractHash),
            artifactBlob: $blobDescriptor,
            generatorVersion: 'legacy-generator-v1',
            rendererKey: 'legacy-blade',
            rendererVersion: 'v1',
            applicability: [$projectName],
            executableSurfaceFingerprintHash: str_repeat('a', 64),
            canonicalSchemaHash: str_repeat('b', 64),
            providerContracts: [['key' => 'post', 'version' => 'v1', 'provider_contract_hash' => $contractHash]],
            trustPolicyVersion: '1',
            trustLane: 'legacy',
            createdAt: new \DateTimeImmutable(),
        );
    }
}
