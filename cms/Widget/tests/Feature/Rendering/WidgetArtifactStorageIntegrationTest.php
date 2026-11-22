<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Rendering;

use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactManifestRepository;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBuilder;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactIntegrityException;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chunk 2 Task 13b: full integration proof for
 * {@see FilesystemWidgetArtifactBlobStore} -- same-filesystem temporary
 * write, checksum/read-after-write, atomic rename, existing-byte
 * verification against a REAL (possibly corrupted) file on disk,
 * failed-staging cleanup, project-scoped keys, and no overwrite (plan Task
 * 13b checklist: "Test same-filesystem temporary write, checksum/
 * read-after-write, atomic rename, existing-byte verification, failed
 * staging cleanup, project-scoped keys, and no overwrite").
 *
 * Task 5a topology note: this proves the SINGLE-NODE local-POSIX-disk
 * adapter Task 5a approved
 * (docs/evidence/widget-site-layout-preflight.md "4. Artifact topology").
 * True multi-process race proof over the SAME file is out of this task's
 * scope.
 */
final class WidgetArtifactStorageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    private FilesystemWidgetArtifactBlobStore $store;

    private DatabaseWidgetArtifactBlobRepository $blobRepository;

    private DatabaseWidgetArtifactManifestRepository $manifestRepository;

    /**
     * See WidgetSiteLayoutSchemaTest::beforeRefreshingDatabase() (Task 13a).
     */
    protected function beforeRefreshingDatabase(): void
    {
        // See
        // \Cms\Widget\Tests\Feature\Database\WidgetArtifactBlobRepositoryTest::beforeRefreshingDatabase()
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

        $this->storageRoot = storage_path('framework/testing/widget-artifacts-integration-' . bin2hex(random_bytes(6)));
        $this->store = new FilesystemWidgetArtifactBlobStore($this->storageRoot);
        $this->blobRepository = new DatabaseWidgetArtifactBlobRepository($this->store);
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
    // Same-filesystem temp write, checksum, atomic rename
    // -----------------------------------------------------------------

    public function test_write_publishes_the_file_at_the_expected_project_scoped_content_addressed_key(): void
    {
        $bytes = 'integration test bytes';
        $hash = hash('sha256', $bytes);

        $written = $this->store->write('ExampleProject', $hash, $bytes);

        $this->assertSame('ExampleProject/' . substr($hash, 0, 2) . '/' . $hash, $written['storage_key']);
        $this->assertTrue($this->store->exists($written['storage_key']));
        $this->assertSame($bytes, $this->store->read($written['storage_key']));
    }

    public function test_write_leaves_no_temp_file_behind_after_a_successful_publish(): void
    {
        $bytes = 'clean staging bytes';
        $hash = hash('sha256', $bytes);

        $written = $this->store->write('ExampleProject', $hash, $bytes);

        $directory = dirname($this->absolutePath($written['storage_key']));
        $leftoverTempFiles = glob($directory . '/*.tmp-*') ?: [];

        $this->assertSame([], $leftoverTempFiles, 'No .tmp-* staging file may remain after a successful write().');
    }

    public function test_write_verifies_checksum_before_publication_and_cleans_up_the_failed_temp_file(): void
    {
        $bytes = 'bytes that do not match the claimed hash';
        $wrongHash = hash('sha256', 'completely different content');

        try {
            $this->store->write('ExampleProject', $wrongHash, $bytes);
            $this->fail('Expected WidgetArtifactIntegrityException was not thrown.');
        } catch (WidgetArtifactIntegrityException) {
            // expected
        }

        $expectedKey = $this->store->storageKeyFor('ExampleProject', $wrongHash);
        $this->assertFalse($this->store->exists($expectedKey), 'A checksum-mismatched write must never publish anything visible.');

        $directory = dirname($this->absolutePath($expectedKey));
        if (is_dir($directory)) {
            $leftoverTempFiles = glob($directory . '/*.tmp-*') ?: [];
            $this->assertSame([], $leftoverTempFiles, 'Failed staging cleanup: no orphaned temp file after a checksum-mismatch failure.');
        }
    }

    // -----------------------------------------------------------------
    // Existing-byte verification against a REAL (possibly corrupted) file
    // -----------------------------------------------------------------

    public function test_existing_bytes_match_is_true_for_genuinely_identical_stored_bytes(): void
    {
        $bytes = 'identical bytes round trip';
        $hash = hash('sha256', $bytes);
        $written = $this->store->write('ExampleProject', $hash, $bytes);

        $this->assertTrue($this->store->existingBytesMatch($written['storage_key'], $bytes));
    }

    public function test_existing_bytes_match_is_false_after_real_on_disk_corruption(): void
    {
        $bytes = 'original trustworthy bytes';
        $hash = hash('sha256', $bytes);
        $written = $this->store->write('ExampleProject', $hash, $bytes);

        // Simulate real on-disk corruption/tampering, bypassing this
        // class's own write() path entirely.
        file_put_contents($this->absolutePath($written['storage_key']), 'corrupted bytes on disk');

        $this->assertFalse($this->store->existingBytesMatch($written['storage_key'], $bytes));
    }

    public function test_repository_put_reuses_the_row_when_the_stored_file_still_matches(): void
    {
        $bytes = 'reuse path bytes';
        $hash = hash('sha256', $bytes);

        $first = $this->blobRepository->put('ExampleProject', $hash, $bytes, 'text/x-blade-php');
        $storageKey = $first->storageKey;
        $mtimeBeforeSecondPut = filemtime($this->absolutePath($storageKey));

        // No-overwrite proof: a second identical put() must not touch the
        // file at all.
        usleep(1_100_000); // ensure a distinguishable mtime if it WERE rewritten
        $second = $this->blobRepository->put('ExampleProject', $hash, $bytes, 'text/x-blade-php');

        $this->assertEquals($first, $second);
        $this->assertSame($mtimeBeforeSecondPut, filemtime($this->absolutePath($storageKey)), 'A reuse put() must never rewrite the already-published file.');
    }

    public function test_repository_put_self_heals_an_absent_file_when_the_db_row_exists(): void
    {
        // Post-review rider: row present + file ABSENT (lost file, not
        // divergent bytes) must self-heal -- the checksum-verified bytes in
        // hand are written back to the row's deterministic key and the
        // retained descriptor is returned. Contrast with the corruption
        // test below, where DIVERGENT stored bytes still hard-fail.
        $bytes = 'self-heal bytes';
        $hash = hash('sha256', $bytes);

        $first = $this->blobRepository->put('ExampleProject', $hash, $bytes, 'text/x-blade-php');

        unlink($this->absolutePath($first->storageKey));
        $this->assertFalse($this->store->exists($first->storageKey), 'Precondition: the published file is gone.');

        $second = $this->blobRepository->put('ExampleProject', $hash, $bytes, 'text/x-blade-php');

        $this->assertEquals($first, $second, 'The retained row descriptor is returned unchanged (no new row, no new createdAt).');
        $this->assertTrue($this->store->exists($first->storageKey), 'The file must be restored at the row storage key.');
        $this->assertSame($bytes, $this->store->read($first->storageKey), 'Restored content must be byte-identical to the original.');
        $this->assertSame(1, WidgetArtifactBlob::query()->where('project_name', 'ExampleProject')->where('artifact_hash', $hash)->count());
    }

    public function test_repository_put_throws_a_hard_integrity_exception_when_the_stored_file_was_corrupted_on_disk(): void
    {
        $bytes = 'trustworthy bytes before tampering';
        $hash = hash('sha256', $bytes);

        $descriptor = $this->blobRepository->put('ExampleProject', $hash, $bytes, 'text/x-blade-php');

        // Tamper with the file directly on disk (bypassing this repository
        // entirely), then attempt to put() the SAME (project, hash, bytes)
        // again -- the repository must detect the on-disk divergence and
        // refuse, never silently overwrite or silently accept stale bytes.
        file_put_contents($this->absolutePath($descriptor->storageKey), 'tampered content on disk');

        $this->expectException(WidgetArtifactIntegrityException::class);

        $this->blobRepository->put('ExampleProject', $hash, $bytes, 'text/x-blade-php');
    }

    // -----------------------------------------------------------------
    // Project-scoped keys
    // -----------------------------------------------------------------

    public function test_identical_bytes_across_two_projects_are_stored_at_two_distinct_paths(): void
    {
        $bytes = 'shared content, different projects';
        $hash = hash('sha256', $bytes);

        $descriptorA = $this->blobRepository->put('ProjectA', $hash, $bytes, 'text/x-blade-php');
        $descriptorB = $this->blobRepository->put('ProjectB', $hash, $bytes, 'text/x-blade-php');

        $this->assertNotSame($descriptorA->storageKey, $descriptorB->storageKey);
        $this->assertStringStartsWith('ProjectA/', $descriptorA->storageKey);
        $this->assertStringStartsWith('ProjectB/', $descriptorB->storageKey);
        $this->assertTrue($this->store->exists($descriptorA->storageKey));
        $this->assertTrue($this->store->exists($descriptorB->storageKey));
    }

    public function test_storage_key_derivation_rejects_project_names_outside_the_path_safe_allowlist(): void
    {
        // Post-review rider (path-scope guard): projectName becomes a
        // literal path segment, so separators/dots/empty must throw rather
        // than be sanitized -- a hostile or corrupted project name can
        // never traverse outside its project directory.
        $hash = hash('sha256', 'allowlist probe');

        foreach (['../evil', 'a/b', '..', '', '.hidden', "Ofm\0Vn"] as $badProjectName) {
            try {
                $this->store->storageKeyFor($badProjectName, $hash);
                $this->fail("Expected storageKeyFor() to reject project name [{$badProjectName}].");
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        // Real project-name shapes stay accepted.
        $this->assertSame('ExampleProject/' . substr($hash, 0, 2) . '/' . $hash, $this->store->storageKeyFor('ExampleProject', $hash));
    }

    // -----------------------------------------------------------------
    // Full round trip via the real Builder + both real repositories
    // -----------------------------------------------------------------

    public function test_full_builder_round_trip_persists_matching_db_rows_and_on_disk_bytes(): void
    {
        $builder = new WidgetArtifactBuilder($this->blobRepository, $this->manifestRepository);
        $analyzer = new LegacyWidgetExecutableSurfaceAnalyzer();

        $fingerprint = $analyzer->analyze(
            widgetJs: 'console.log("integration");',
            themeContent: '<div class="card">{title}</div>',
            themeCss: '.card { color: green; }',
            canonicalSchema: ['title' => ['type' => 'text']],
            policyVersion: 'policy-v1',
            trustLane: 'legacy-approved',
            applicability: ['ExampleProject'],
            artifactBytes: '',
        );

        $finalBytes = '<div class="card">Rendered</div>';

        $manifest = $builder->build(
            projectName: 'ExampleProject',
            finalBytes: $finalBytes,
            mediaType: 'text/x-blade-php',
            generatorVersion: 'legacy-generator-v1',
            rendererKey: 'legacy-blade',
            rendererVersion: 'v1',
            executableSurfaceFingerprint: $fingerprint,
            providerContracts: [['key' => 'post', 'version' => 'v1', 'provider_contract_hash' => 'hash-post-v1']],
        );

        $blobRow = WidgetArtifactBlob::query()->where('project_name', 'ExampleProject')->where('artifact_hash', $manifest->artifactBlob->artifactHash)->first();
        $this->assertNotNull($blobRow, 'The Blob row must be persisted for real.');

        $onDiskBytes = $this->store->read($blobRow->storage_key);
        $this->assertSame($finalBytes, $onDiskBytes, 'The bytes on disk must match exactly what the Builder generated.');

        $reloadedManifest = $this->manifestRepository->findByProjectAndManifestHash('ExampleProject', $manifest->manifestHash);
        $this->assertNotNull($reloadedManifest);
        $this->assertSame($manifest->artifactBlob->artifactHash, $reloadedManifest->artifactBlob->artifactHash);
    }

    private function absolutePath(string $storageKey): string
    {
        return rtrim($this->storageRoot, '/') . '/' . ltrim($storageKey, '/');
    }
}
