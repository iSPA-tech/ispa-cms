<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Support;

use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBuilder;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactIntegrityException;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactManifest;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactManifestRepository;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetExecutableSurfaceFingerprint;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionParameter;

/**
 * Drop-in-reuse contract-test base for {@see WidgetArtifactBlobRepository}
 * and {@see WidgetArtifactManifestRepository} implementations (plan Chunk 1
 * Task 10 docblock -- both
 * {@see \Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository} and
 * {@see \Cms\Widget\Rendering\Artifacts\WidgetArtifactManifestRepository} --
 * "Task 13b must first extract an abstract contract-test base with
 * repository-factory and row-count hooks, then run the same assertions
 * against its real adapters"). Every Group A/B/C/D/F assertion from Task
 * 10's original single-file suite lives here verbatim (renamed properties
 * only), parametrized by four hook methods, so it now runs UNMODIFIED
 * against both the in-memory fakes (Task 10) and the real Database adapters
 * (Task 13b).
 *
 * DELIBERATELY a trait, not an abstract base class: the two concrete
 * consumers need different TestCase ancestors --
 * {@see \Cms\Widget\Tests\Unit\Rendering\WidgetArtifactRepositoryTest} stays
 * a plain `PHPUnit\Framework\TestCase` (zero Laravel bootstrap, matching its
 * own persistence-agnostic non-goal: "no filesystem or database write
 * anywhere in cms/Widget"), while
 * {@see \Cms\Widget\Tests\Feature\Database\WidgetArtifactBlobRepositoryTest}
 * needs Laravel's `Tests\TestCase` + `RefreshDatabase` for real DB and
 * filesystem I/O. A trait reuses the method bodies without forcing one
 * shared inheritance root or changing either consumer's base class.
 *
 * NOT reused here (deliberately, per the original docblock's own scoping):
 * the persistence-agnostic I/O-forbidden-token scan
 * (`test_production_artifact_classes_perform_no_filesystem_or_database_io`)
 * and the `NullWidgetArtifactManifestRepository`-specific test stay only in
 * the original Task 10 file -- a real Database adapter performs I/O by
 * design, so sharing that guard test here would be actively wrong.
 *
 * Consuming classes MUST call
 * {@see self::initializeArtifactRepositoryContract()} at the top of their
 * own `setUp()` (after `parent::setUp()`) and implement the four abstract
 * hooks below.
 */
trait WidgetArtifactRepositoryContractAssertions
{
    private WidgetArtifactBlobRepository $contractBlobRepository;

    private WidgetArtifactManifestRepository $contractManifestRepository;

    private WidgetArtifactBuilder $contractBuilder;

    abstract protected function createBlobRepositoryUnderTest(): WidgetArtifactBlobRepository;

    abstract protected function createManifestRepositoryUnderTest(): WidgetArtifactManifestRepository;

    abstract protected function countBlobRowsForProject(string $projectName): int;

    abstract protected function countManifestRowsForProject(string $projectName): int;

    protected function initializeArtifactRepositoryContract(): void
    {
        $this->contractBlobRepository = $this->createBlobRepositoryUnderTest();
        $this->contractManifestRepository = $this->createManifestRepositoryUnderTest();
        $this->contractBuilder = new WidgetArtifactBuilder($this->contractBlobRepository, $this->contractManifestRepository);
    }

    // -----------------------------------------------------------------
    // Shared test helpers (verbatim from Task 10's WidgetArtifactRepositoryTest)
    // -----------------------------------------------------------------

    private function contractAnalyzer(): LegacyWidgetExecutableSurfaceAnalyzer
    {
        return new LegacyWidgetExecutableSurfaceAnalyzer();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function contractFingerprint(array $overrides = []): WidgetExecutableSurfaceFingerprint
    {
        $defaults = [
            'widgetJs' => 'console.log("hello");',
            'themeContent' => '<div class="card">{title}</div>',
            'themeCss' => '.card { color: red; }',
            'canonicalSchema' => ['title' => ['type' => 'text']],
            'policyVersion' => 'policy-v1',
            'trustLane' => 'legacy-approved',
            'applicability' => ['Default'],
            'artifactBytes' => '',
        ];

        $unknownKeys = array_diff_key($overrides, $defaults);
        self::assertSame(
            [],
            $unknownKeys,
            'Unknown fingerprint() override key(s): ' . implode(', ', array_keys($unknownKeys))
        );

        $args = array_merge($defaults, $overrides);

        return $this->contractAnalyzer()->analyze(
            widgetJs: $args['widgetJs'],
            themeContent: $args['themeContent'],
            themeCss: $args['themeCss'],
            canonicalSchema: $args['canonicalSchema'],
            policyVersion: $args['policyVersion'],
            trustLane: $args['trustLane'],
            applicability: $args['applicability'],
            artifactBytes: $args['artifactBytes'],
        );
    }

    /**
     * @return list<array{key: string, version: string, provider_contract_hash: string}>
     */
    private function contractProviderContracts(): array
    {
        return [
            ['key' => 'post', 'version' => 'v1', 'provider_contract_hash' => 'contract-hash-post-v1'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function contractBuildManifest(array $overrides = []): WidgetArtifactManifest
    {
        $defaults = [
            'projectName' => 'Default',
            'finalBytes' => '<div class="card">Hello</div>',
            'mediaType' => 'text/x-blade-php',
            'generatorVersion' => 'legacy-generator-v1',
            'rendererKey' => 'legacy-blade',
            'rendererVersion' => 'v1',
            'fingerprint' => $this->contractFingerprint(),
            'providerContracts' => $this->contractProviderContracts(),
        ];

        $unknownKeys = array_diff_key($overrides, $defaults);
        self::assertSame(
            [],
            $unknownKeys,
            'Unknown buildManifest() override key(s): ' . implode(', ', array_keys($unknownKeys))
        );

        $args = array_merge($defaults, $overrides);

        return $this->contractBuilder->build(
            projectName: $args['projectName'],
            finalBytes: $args['finalBytes'],
            mediaType: $args['mediaType'],
            generatorVersion: $args['generatorVersion'],
            rendererKey: $args['rendererKey'],
            rendererVersion: $args['rendererVersion'],
            executableSurfaceFingerprint: $args['fingerprint'],
            providerContracts: $args['providerContracts'],
        );
    }

    // -----------------------------------------------------------------
    // Group A: artifact_hash and manifest_hash are separate identities
    // -----------------------------------------------------------------

    public function test_artifact_hash_is_the_sha256_of_final_bytes_only(): void
    {
        $bytes = '<div class="card">Hello</div>';
        $manifest = $this->contractBuildManifest(['finalBytes' => $bytes]);

        $this->assertSame(hash('sha256', $bytes), $manifest->artifactBlob->artifactHash);
    }

    /**
     * NOTE on this test's meaning for a real Database adapter: unlike the
     * in-memory fake (where a genuinely independent second repository
     * instance holds separate state), a second Database-backed builder
     * instance shares the SAME underlying database, so it exercises the
     * idempotent-reuse path (same descriptor, same row) rather than an
     * independently-computed-but-equal hash. Both outcomes satisfy this
     * assertion (hash computation never depends on repository state), so
     * the test is still a valid, honest proof for either implementation.
     */
    public function test_manifest_hash_is_deterministic_for_identical_inputs(): void
    {
        $first = $this->contractBuildManifest();

        $secondBlobRepository = $this->createBlobRepositoryUnderTest();
        $secondManifestRepository = $this->createManifestRepositoryUnderTest();
        $secondBuilder = new WidgetArtifactBuilder($secondBlobRepository, $secondManifestRepository);

        $second = $secondBuilder->build(
            projectName: 'Default',
            finalBytes: '<div class="card">Hello</div>',
            mediaType: 'text/x-blade-php',
            generatorVersion: 'legacy-generator-v1',
            rendererKey: 'legacy-blade',
            rendererVersion: 'v1',
            executableSurfaceFingerprint: $this->contractFingerprint(),
            providerContracts: $this->contractProviderContracts(),
        );

        $this->assertSame($first->manifestHash, $second->manifestHash);
        $this->assertSame($first->artifactBlob->artifactHash, $second->artifactBlob->artifactHash);
    }

    public function test_identical_bytes_with_a_different_non_byte_input_keep_the_same_artifact_hash_but_change_manifest_hash(): void
    {
        $baseline = $this->contractBuildManifest();
        $changed = $this->contractBuildManifest(['rendererVersion' => 'v2']);

        $this->assertSame(
            $baseline->artifactBlob->artifactHash,
            $changed->artifactBlob->artifactHash,
            'Identical final bytes must keep the identical artifact_hash regardless of manifest-only changes.'
        );
        $this->assertNotSame(
            $baseline->manifestHash,
            $changed->manifestHash,
            'A manifest-only change (rendererVersion) must still produce a different manifest_hash.'
        );
        $this->assertSame(1, $this->countBlobRowsForProject('Default'));
    }

    // -----------------------------------------------------------------
    // Group B: two provider/applicability manifests can reference the
    // same bytes without overwrite or false conflict
    // -----------------------------------------------------------------

    public function test_two_manifests_with_different_provider_contracts_reference_the_same_blob_without_conflict(): void
    {
        $manifestA = $this->contractBuildManifest([
            'providerContracts' => [['key' => 'post', 'version' => 'v1', 'provider_contract_hash' => 'hash-a']],
        ]);
        $manifestB = $this->contractBuildManifest([
            'providerContracts' => [['key' => 'post', 'version' => 'v2', 'provider_contract_hash' => 'hash-b']],
        ]);

        $this->assertNotSame($manifestA->manifestHash, $manifestB->manifestHash);
        $this->assertSame($manifestA->artifactBlob->artifactHash, $manifestB->artifactBlob->artifactHash);
        $this->assertSame(1, $this->countBlobRowsForProject('Default'), 'Only one Blob row: bytes are identical.');
        $this->assertSame(2, $this->countManifestRowsForProject('Default'), 'Two distinct Manifest rows: identity differs.');

        $this->assertNotNull($this->contractManifestRepository->findByProjectAndManifestHash('Default', $manifestA->manifestHash));
        $this->assertNotNull($this->contractManifestRepository->findByProjectAndManifestHash('Default', $manifestB->manifestHash));
    }

    public function test_two_manifests_with_different_applicability_reference_the_same_blob_without_conflict(): void
    {
        $manifestA = $this->contractBuildManifest(['fingerprint' => $this->contractFingerprint(['applicability' => ['Default']])]);
        $manifestB = $this->contractBuildManifest(['fingerprint' => $this->contractFingerprint(['applicability' => ['Default', 'SecondProject']])]);

        $this->assertNotSame($manifestA->manifestHash, $manifestB->manifestHash);
        $this->assertSame($manifestA->artifactBlob->artifactHash, $manifestB->artifactBlob->artifactHash);
        $this->assertSame(1, $this->countBlobRowsForProject('Default'));
        $this->assertSame(2, $this->countManifestRowsForProject('Default'));
    }

    // -----------------------------------------------------------------
    // Group C: canonical manifest content, pending status, no per-Instance
    // business arguments/Input Values
    // -----------------------------------------------------------------

    public function test_manifest_exposes_provider_key_version_and_provider_contract_hash(): void
    {
        $manifest = $this->contractBuildManifest([
            'providerContracts' => [['key' => 'post', 'version' => 'v3', 'provider_contract_hash' => 'contract-hash-post-v3']],
        ]);

        $this->assertSame(
            [['key' => 'post', 'version' => 'v3', 'provider_contract_hash' => 'contract-hash-post-v3']],
            $manifest->providerContracts
        );
    }

    public function test_freshly_built_manifest_is_always_pending(): void
    {
        $manifest = $this->contractBuildManifest();

        $this->assertSame(WidgetArtifactManifest::STATUS_PENDING, $manifest->status);
    }

    public function test_build_signature_structurally_excludes_ordinary_instance_arguments_and_input_values(): void
    {
        // Mirrors WidgetSiteLayoutEligibilityTest's own structural freeze
        // pin: pinning build()'s exact parameter list proves there is no
        // parameter through which per-Instance business arguments or Input
        // Values could enter Manifest identity (D-034). Only structured
        // {key, version, provider_contract_hash} provider references are
        // accepted, never "arguments". Purely structural (Reflection over
        // WidgetArtifactBuilder itself) -- identical regardless of which
        // repository implementation is under test.
        $method = (new ReflectionClass(WidgetArtifactBuilder::class))->getMethod('build');
        $parameterNames = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters()
        );

        $this->assertSame(
            [
                'projectName',
                'finalBytes',
                'mediaType',
                'generatorVersion',
                'rendererKey',
                'rendererVersion',
                'executableSurfaceFingerprint',
                'providerContracts',
            ],
            $parameterNames
        );
    }

    public function test_manifest_repository_rejects_persisting_a_non_pending_manifest(): void
    {
        $pending = $this->contractBuildManifest();

        $notPending = new WidgetArtifactManifest(
            projectName: $pending->projectName,
            manifestHash: 'different-manifest-hash-not-pending',
            artifactBlob: $pending->artifactBlob,
            generatorVersion: $pending->generatorVersion,
            rendererKey: $pending->rendererKey,
            rendererVersion: $pending->rendererVersion,
            applicability: $pending->applicability,
            executableSurfaceFingerprintHash: $pending->executableSurfaceFingerprintHash,
            canonicalSchemaHash: $pending->canonicalSchemaHash,
            providerContracts: $pending->providerContracts,
            trustPolicyVersion: $pending->trustPolicyVersion,
            trustLane: $pending->trustLane,
            createdAt: $pending->createdAt,
            status: 'approved',
        );

        $this->expectException(InvalidArgumentException::class);

        $this->contractManifestRepository->putPending($notPending);
    }

    // -----------------------------------------------------------------
    // Group D: Blob identity/storage keyed by (project_name, artifact_hash)
    // -----------------------------------------------------------------

    public function test_same_project_same_bytes_dedups_to_one_blob_row(): void
    {
        $this->contractBuildManifest();
        $this->contractBuildManifest(['rendererVersion' => 'v9']);
        $this->contractBuildManifest(['generatorVersion' => 'legacy-generator-v9']);

        $this->assertSame(1, $this->countBlobRowsForProject('Default'));
    }

    public function test_cross_project_rows_and_storage_keys_are_fully_isolated_even_with_identical_bytes(): void
    {
        $manifestA = $this->contractBuildManifest(['projectName' => 'ProjectA']);
        $manifestB = $this->contractBuildManifest(['projectName' => 'ProjectB']);

        $this->assertSame(1, $this->countBlobRowsForProject('ProjectA'));
        $this->assertSame(1, $this->countBlobRowsForProject('ProjectB'));

        $blobA = $this->contractBlobRepository->findByProjectAndHash('ProjectA', $manifestA->artifactBlob->artifactHash);
        $blobB = $this->contractBlobRepository->findByProjectAndHash('ProjectB', $manifestB->artifactBlob->artifactHash);

        $this->assertNotNull($blobA);
        $this->assertNotNull($blobB);
        $this->assertNotSame($blobA->storageKey, $blobB->storageKey, 'Cross-project storage keys must never collide.');

        // Same manifest_hash STRING value is fine across projects; the
        // isolation is on the composite (project_name, manifest_hash) key,
        // not on the raw hash value.
        $this->assertSame($manifestA->manifestHash, $manifestB->manifestHash);
        $this->assertNotNull($this->contractManifestRepository->findByProjectAndManifestHash('ProjectA', $manifestA->manifestHash));
        $this->assertNotNull($this->contractManifestRepository->findByProjectAndManifestHash('ProjectB', $manifestB->manifestHash));

        // A project that never built anything must never see the other
        // project's row (row/key isolation, not merely "different value").
        $this->assertSame(0, $this->countBlobRowsForProject('UnrelatedProject'));
    }

    public function test_changing_generator_version_alone_produces_a_different_manifest_hash(): void
    {
        $baseline = $this->contractBuildManifest();
        $changed = $this->contractBuildManifest(['generatorVersion' => 'legacy-generator-v2']);

        $this->assertNotSame($baseline->manifestHash, $changed->manifestHash);
    }

    public function test_changing_renderer_key_alone_produces_a_different_manifest_hash(): void
    {
        $baseline = $this->contractBuildManifest();
        $changed = $this->contractBuildManifest(['rendererKey' => 'safe-html-renderer']);

        $this->assertNotSame($baseline->manifestHash, $changed->manifestHash);
    }

    public function test_changing_renderer_version_alone_produces_a_different_manifest_hash(): void
    {
        $baseline = $this->contractBuildManifest();
        $changed = $this->contractBuildManifest(['rendererVersion' => 'v2']);

        $this->assertNotSame($baseline->manifestHash, $changed->manifestHash);
    }

    public function test_changing_theme_source_or_css_or_javascript_alone_produces_a_different_manifest_hash(): void
    {
        $baseline = $this->contractBuildManifest();

        $variants = [
            'theme content' => $this->contractFingerprint(['themeContent' => '<div class="card">{title} changed</div>']),
            'theme CSS' => $this->contractFingerprint(['themeCss' => '.card { color: blue; }']),
            'widget JavaScript' => $this->contractFingerprint(['widgetJs' => 'console.log("changed");']),
        ];

        foreach ($variants as $label => $fingerprint) {
            $changed = $this->contractBuildManifest(['fingerprint' => $fingerprint]);

            $this->assertNotSame(
                $baseline->manifestHash,
                $changed->manifestHash,
                "Changing {$label} alone must produce a different manifest_hash."
            );
        }
    }

    public function test_changing_canonical_schema_alone_produces_a_different_manifest_hash(): void
    {
        $baseline = $this->contractBuildManifest();
        $changed = $this->contractBuildManifest([
            'fingerprint' => $this->contractFingerprint([
                'canonicalSchema' => ['title' => ['type' => 'text'], 'subtitle' => ['type' => 'text']],
            ]),
        ]);

        $this->assertNotSame($baseline->manifestHash, $changed->manifestHash);
    }

    public function test_changing_provider_contracts_alone_produces_a_different_manifest_hash(): void
    {
        $baseline = $this->contractBuildManifest();
        $changed = $this->contractBuildManifest([
            'providerContracts' => [['key' => 'product', 'version' => 'v1', 'provider_contract_hash' => 'contract-hash-product-v1']],
        ]);

        $this->assertNotSame($baseline->manifestHash, $changed->manifestHash);
    }

    public function test_changing_trust_policy_version_alone_produces_a_different_manifest_hash(): void
    {
        $baseline = $this->contractBuildManifest();
        $changed = $this->contractBuildManifest(['fingerprint' => $this->contractFingerprint(['policyVersion' => 'policy-v2'])]);

        $this->assertNotSame($baseline->manifestHash, $changed->manifestHash);
    }

    public function test_changing_trust_lane_alone_produces_a_different_manifest_hash(): void
    {
        $baseline = $this->contractBuildManifest();
        $changed = $this->contractBuildManifest(['fingerprint' => $this->contractFingerprint(['trustLane' => 'legacy-experimental'])]);

        $this->assertNotSame($baseline->manifestHash, $changed->manifestHash);
    }

    public function test_provider_contract_input_order_does_not_affect_manifest_hash_canonical_json_ordering(): void
    {
        $contracts = [
            ['key' => 'product', 'version' => 'v1', 'provider_contract_hash' => 'hash-product'],
            ['key' => 'post', 'version' => 'v1', 'provider_contract_hash' => 'hash-post'],
        ];

        $forward = $this->contractBuildManifest(['providerContracts' => $contracts]);
        $reversed = $this->contractBuildManifest(['providerContracts' => array_reverse($contracts)]);

        $this->assertSame($forward->manifestHash, $reversed->manifestHash);
        $this->assertSame(
            1,
            $this->countManifestRowsForProject('Default'),
            'Input-order-only variation must dedup to the SAME manifest row, not create a second one.'
        );
    }

    public function test_publish_rejects_bytes_that_do_not_match_the_claimed_artifact_hash_and_publishes_nothing(): void
    {
        $realHash = hash('sha256', 'real bytes');

        try {
            $this->contractBlobRepository->put('Default', $realHash, 'DIFFERENT bytes than were hashed', 'text/x-blade-php');
            $this->fail('Expected WidgetArtifactIntegrityException was not thrown.');
        } catch (WidgetArtifactIntegrityException) {
            // expected
        }

        $this->assertNull(
            $this->contractBlobRepository->findByProjectAndHash('Default', $realHash),
            'A checksum-mismatched publish must leave no orphaned/partially-visible entry (temp write, verify, only-then-visible).'
        );
        $this->assertSame(0, $this->countBlobRowsForProject('Default'));
    }

    public function test_hash_collision_with_different_bytes_is_a_hard_integrity_failure_that_retains_the_original_bytes(): void
    {
        $hash = hash('sha256', 'original bytes');
        $original = $this->contractBlobRepository->put('Default', $hash, 'original bytes', 'text/x-blade-php');

        try {
            // A caller claiming the SAME hash for genuinely different bytes
            // (e.g. a caller bug, or a defensive check against corruption)
            // must never silently overwrite.
            $this->contractBlobRepository->put('Default', $hash, 'different bytes entirely', 'text/x-blade-php');
            $this->fail('Expected WidgetArtifactIntegrityException was not thrown.');
        } catch (WidgetArtifactIntegrityException) {
            // expected
        }

        $retained = $this->contractBlobRepository->findByProjectAndHash('Default', $hash);
        $this->assertNotNull($retained);
        $this->assertEquals($original, $retained, 'The original descriptor must be retained byte-for-byte, never mutated.');
        $this->assertSame(1, $this->countBlobRowsForProject('Default'));
    }

    public function test_unknown_artifact_hash_returns_null_enabling_legacy_id_based_fallback_resolution(): void
    {
        $neverPublishedHash = hash('sha256', 'never published legacy content');

        $this->assertNull($this->contractBlobRepository->findByProjectAndHash('Default', $neverPublishedHash));

        // The fallback window closes cleanly once the SAME content is
        // migrated through the new hash-addressed path: no permanent
        // negative caching trap.
        $published = $this->contractBlobRepository->put('Default', $neverPublishedHash, 'never published legacy content', 'text/x-blade-php');
        $this->assertSame(
            $published->artifactHash,
            $this->contractBlobRepository->findByProjectAndHash('Default', $neverPublishedHash)?->artifactHash
        );
    }

    // -----------------------------------------------------------------
    // Group F: defensive/structural guards
    // -----------------------------------------------------------------

    public function test_build_rejects_an_empty_project_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->contractBuildManifest(['projectName' => '']);
    }

    public function test_build_rejects_a_provider_contract_missing_a_required_field(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->contractBuildManifest(['providerContracts' => [['key' => 'post', 'version' => 'v1']]]);
    }
}
