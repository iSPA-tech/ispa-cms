<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\Rendering;

use Cms\Widget\Rendering\Artifacts\NullWidgetArtifactManifestRepository;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobDescriptor;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBuilder;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactManifest;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactManifestRepository;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Tests\Fakes\InMemoryWidgetArtifactBlobRepository;
use Cms\Widget\Tests\Support\WidgetArtifactRepositoryContractAssertions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Chunk 1 Task 10: separate immutable Artifact Blob and Manifest
 * repositories (plan lines 583-612; architecture.md "Blob and manifest
 * identity" / "widget_artifact_blobs" / "widget_artifact_manifests";
 * docs/architecture/adr-widget-artifact-store.md decision 10, D-012/D-042).
 *
 * This slice is persistence-agnostic (non-goal: no filesystem/database
 * write anywhere in cms/Widget yet): WidgetArtifactBuilder is pure
 * orchestration over two small repository contracts, exercised here only
 * through {@see InMemoryWidgetArtifactBlobRepository} and a co-located
 * in-memory Manifest test double (see bottom of this file -- Task 10's
 * allowed_paths has a Fakes-directory slot for only the Blob fake).
 *
 * REFACTORED for Task 13b (disclosed, per Task 10's own docblock instruction
 * that 13b "must first extract an abstract contract-test base... then run
 * the same assertions against its real adapter"): every Group A/B/C/D/F
 * behavioral assertion now lives in the shared
 * {@see WidgetArtifactRepositoryContractAssertions} trait, reused verbatim
 * by {@see \Cms\Widget\Tests\Feature\Database\WidgetArtifactBlobRepositoryTest}
 * against the real Database adapters. This class keeps ONLY the two
 * assertions that are genuinely specific to this persistence-agnostic slice
 * and would be actively WRONG to share: the I/O-forbidden-token scan below
 * (a real Database adapter performs I/O by design) and the
 * NullWidgetArtifactManifestRepository-specific always-empty proof. No
 * assertion's meaning or expected outcome changed -- only its location and
 * the property names the shared trait renamed (`blobRepository` ->
 * `contractBlobRepository`, etc.).
 *
 * @see \Cms\Widget\Rendering\Artifacts\WidgetArtifactBuilder
 * @see \Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository
 * @see \Cms\Widget\Rendering\Artifacts\WidgetArtifactManifestRepository
 */
final class WidgetArtifactRepositoryTest extends TestCase
{
    use WidgetArtifactRepositoryContractAssertions;

    private InMemoryWidgetArtifactBlobRepository $blobRepository;

    private InMemoryWidgetArtifactManifestRepository $manifestRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeArtifactRepositoryContract();
    }

    protected function createBlobRepositoryUnderTest(): WidgetArtifactBlobRepository
    {
        return $this->blobRepository = new InMemoryWidgetArtifactBlobRepository();
    }

    protected function createManifestRepositoryUnderTest(): WidgetArtifactManifestRepository
    {
        return $this->manifestRepository = new InMemoryWidgetArtifactManifestRepository();
    }

    protected function countBlobRowsForProject(string $projectName): int
    {
        return $this->blobRepository->countRowsForProject($projectName);
    }

    protected function countManifestRowsForProject(string $projectName): int
    {
        return $this->manifestRepository->countRowsForProject($projectName);
    }

    // -----------------------------------------------------------------
    // Group E: persistence-agnostic slice / Null repository default --
    // deliberately NOT shared via the contract trait (see class docblock).
    // -----------------------------------------------------------------

    public function test_production_artifact_classes_perform_no_filesystem_or_database_io(): void
    {
        // Comments are stripped before scanning: several docblocks
        // deliberately CITE the artifact-store ADR's "atomic rename()" and
        // "checksum" publication-flow prose (to document what the real
        // Task 13b adapter must do), which would otherwise false-positive
        // against a naive substring scan of the raw file. Only actual code
        // tokens are checked.
        $forbiddenTokens = [
            'file_put_contents', 'file_get_contents', 'fopen(', 'fwrite(', 'unlink(',
            'mkdir(', 'rename(', 'Storage::', 'DB::', 'Illuminate\\Support\\Facades',
            'Illuminate\\Database', '->save(', '::create(', '::query(',
        ];

        foreach ($this->artifactSliceFilePaths() as $path) {
            $code = $this->stripComments((string) file_get_contents($path));

            foreach ($forbiddenTokens as $token) {
                $this->assertStringNotContainsString(
                    $token,
                    $code,
                    "Persistence-agnostic slice violation: '{$token}' found in " . basename($path)
                );
            }
        }
    }

    /**
     * Removes T_COMMENT/T_DOC_COMMENT token text via PHP's own tokenizer,
     * leaving only executable code (plus whitespace/strings/etc.) to scan.
     */
    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    public function test_null_manifest_repository_always_misses_and_accepts_pending_writes_harmlessly(): void
    {
        $null = new NullWidgetArtifactManifestRepository();
        $pending = $this->contractBuildManifestForNullRepositoryTest();

        $this->assertNull($null->findByProjectAndManifestHash('Default', $pending->manifestHash));

        $result = $null->putPending($pending);

        $this->assertSame($pending, $result);
        $this->assertNull(
            $null->findByProjectAndManifestHash('Default', $pending->manifestHash),
            'NullWidgetArtifactManifestRepository must stay always-empty even after a putPending() call.'
        );
    }

    /**
     * Builds one pending Manifest through this test's own in-memory
     * builder, without touching the trait's shared fixtures (kept
     * independent since this is the one Group-E test that still needs a
     * manifest instance).
     */
    private function contractBuildManifestForNullRepositoryTest(): WidgetArtifactManifest
    {
        $blobRepository = new InMemoryWidgetArtifactBlobRepository();
        $manifestRepository = new InMemoryWidgetArtifactManifestRepository();
        $builder = new WidgetArtifactBuilder($blobRepository, $manifestRepository);
        $analyzer = new LegacyWidgetExecutableSurfaceAnalyzer();

        $fingerprint = $analyzer->analyze(
            widgetJs: 'console.log("hello");',
            themeContent: '<div class="card">{title}</div>',
            themeCss: '.card { color: red; }',
            canonicalSchema: ['title' => ['type' => 'text']],
            policyVersion: 'policy-v1',
            trustLane: 'legacy-approved',
            applicability: ['Default'],
            artifactBytes: '',
        );

        return $builder->build(
            projectName: 'Default',
            finalBytes: '<div class="card">Hello</div>',
            mediaType: 'text/x-blade-php',
            generatorVersion: 'legacy-generator-v1',
            rendererKey: 'legacy-blade',
            rendererVersion: 'v1',
            executableSurfaceFingerprint: $fingerprint,
            providerContracts: [['key' => 'post', 'version' => 'v1', 'provider_contract_hash' => 'contract-hash-post-v1']],
        );
    }

    /**
     * @return list<string>
     */
    private function artifactSliceFilePaths(): array
    {
        $classes = [
            WidgetArtifactBlobDescriptor::class,
            WidgetArtifactManifest::class,
            WidgetArtifactBlobRepository::class,
            WidgetArtifactManifestRepository::class,
            NullWidgetArtifactManifestRepository::class,
            WidgetArtifactBuilder::class,
            // The in-memory fake is part of the delivered slice too (plan:
            // "uses in-memory/null test repositories... performs no
            // filesystem or database write") -- scanned defensively so the
            // fake itself never silently grows real I/O.
            InMemoryWidgetArtifactBlobRepository::class,
        ];

        $paths = [];
        foreach ($classes as $class) {
            $paths[] = (new ReflectionClass($class))->getFileName();
        }

        return array_values(array_unique(array_filter($paths)));
    }
}

/**
 * In-memory WidgetArtifactManifestRepository test double. Co-located in
 * this test file (not in tests/Fakes/) because Task 10's allowed_paths
 * names exactly one Fakes file
 * (tests/Fakes/InMemoryWidgetArtifactBlobRepository.php); mirrors the exact
 * co-location precedent already established in
 * LegacyWidgetExecutableSurfaceAnalyzer.php (Task 9c), where a VO is
 * declared in the same file as its sole producing class because the
 * allowed-file list has no separate slot. This class is only ever
 * referenced from within this test file (and, via the shared contract
 * trait's hook methods, from
 * {@see \Cms\Widget\Tests\Feature\Database\WidgetArtifactBlobRepositoryTest}
 * only by NAME through the trait's abstract hooks -- never a direct
 * `use` -- so it is always reached only after this file has already been
 * parsed).
 *
 * Enforces the same PENDING-only write guard the production
 * NullWidgetArtifactManifestRepository binding does not need to (a
 * silent no-op never needs to validate its input), proving the contract
 * at the interface level regardless of which concrete implementation is
 * under test.
 */
final class InMemoryWidgetArtifactManifestRepository implements WidgetArtifactManifestRepository
{
    /**
     * @var array<string, array<string, WidgetArtifactManifest>> Keyed by [projectName][manifestHash].
     */
    private array $rows = [];

    public function findByProjectAndManifestHash(string $projectName, string $manifestHash): ?WidgetArtifactManifest
    {
        return $this->rows[$projectName][$manifestHash] ?? null;
    }

    public function putPending(WidgetArtifactManifest $manifest): WidgetArtifactManifest
    {
        if ($manifest->status !== WidgetArtifactManifest::STATUS_PENDING) {
            throw new InvalidArgumentException(
                'putPending() only accepts a PENDING manifest in this persistence-agnostic slice; '
                . 'approval/revocation persistence is owned by Task 15a/15b.'
            );
        }

        $existing = $this->rows[$manifest->projectName][$manifest->manifestHash] ?? null;
        if ($existing !== null) {
            // Idempotent reuse: never overwritten, never a false conflict.
            return $existing;
        }

        $this->rows[$manifest->projectName][$manifest->manifestHash] = $manifest;

        return $manifest;
    }

    /**
     * Test-only introspection helper (not part of the production
     * interface).
     */
    public function countRowsForProject(string $projectName): int
    {
        return count($this->rows[$projectName] ?? []);
    }
}
