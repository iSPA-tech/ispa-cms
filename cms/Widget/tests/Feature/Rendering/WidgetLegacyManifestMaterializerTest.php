<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Rendering;

use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\WidgetDataProvider;
use App\Events\Widget\LegacyWidgetDefinitionChanged;
use App\Support\Widget\CanonicalWidgetSchema;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Widget\Listeners\MaterializeLegacyWidgetManifest;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Providers\EventServiceProvider as WidgetEventServiceProvider;
use Cms\Widget\Providers\WidgetServiceProvider;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactBlobRepository;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactManifestRepository;
use Cms\Widget\Rendering\Artifacts\FilesystemWidgetArtifactBlobStore;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBuilder;
use Cms\Widget\Rendering\DataProviders\WidgetDataProviderRegistry;
use Cms\Widget\Rendering\DataProviders\WidgetProviderDeclaration;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestDriftReader;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializationResult;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Chunk 2 Task 15a: {@see WidgetLegacyManifestMaterializer},
 * {@see WidgetLegacyManifestDriftReader}, and
 * {@see MaterializeLegacyWidgetManifest} (the after-commit listener --
 * this task's allowed_paths lists no separate listener test file, so its
 * behavior is proven here alongside the materializer it drives).
 *
 * Uses the REAL database-backed adapters (Task 13b) exactly as
 * WidgetServiceProvider::boot() wires them in production -- constructed
 * manually with an isolated temporary storage root per
 * WidgetArtifactStorageIntegrationTest's established precedent, so every
 * test proves real persistence, not a mock.
 */
final class WidgetLegacyManifestMaterializerTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    private FilesystemWidgetArtifactBlobStore $store;

    private DatabaseWidgetArtifactBlobRepository $blobRepository;

    private DatabaseWidgetArtifactManifestRepository $manifestRepository;

    private WidgetArtifactBuilder $builder;

    /**
     * See WidgetPermissionSeederTest::beforeRefreshingDatabase() (Task 14)
     * for the full explanation. Deliberately does NOT override
     * migrateFreshUsing() with an explicit `--path` (unlike Task 13b's
     * pure-module storage tests): this test also needs the root/Core
     * `widgets`/`widget_themes`/`widget_inputs` tables, and Laravel's
     * migrate:fresh --path option REPLACES the default migration path set
     * entirely rather than adding to it. Using the plain
     * afterResolving('migrator', ...) push here keeps the default
     * database/migrations root path intact while additively including the
     * module's own migrations.
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

        $this->storageRoot = storage_path('framework/testing/widget-manifest-materializer-' . bin2hex(random_bytes(6)));
        $this->store = new FilesystemWidgetArtifactBlobStore($this->storageRoot);
        $this->blobRepository = new DatabaseWidgetArtifactBlobRepository($this->store);
        $this->manifestRepository = new DatabaseWidgetArtifactManifestRepository();
        $this->builder = new WidgetArtifactBuilder($this->blobRepository, $this->manifestRepository);
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

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function materializer(?WidgetDataProviderRegistry $registry = null): WidgetLegacyManifestMaterializer
    {
        return new WidgetLegacyManifestMaterializer(
            publisher: new LegacyWidgetArtifactSetPublisher(),
            schemaResolver: new CanonicalWidgetSchema(),
            analyzer: new LegacyWidgetExecutableSurfaceAnalyzer(),
            builder: $this->builder,
            manifestRepository: $this->manifestRepository,
            providerRegistry: $registry ?? new WidgetDataProviderRegistry(),
        );
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
            'project_name' => ['ExampleProject'],
        ], $overrides));
    }

    private function fakeProvider(string $key, int $version): WidgetDataProvider
    {
        return new class ($key, $version) implements WidgetDataProvider {
            public function __construct(private readonly string $k, private readonly int $v)
            {
            }

            public function key(): string
            {
                return $this->k;
            }

            public function version(): int
            {
                return $this->v;
            }

            public function fetch(array $arguments, WidgetRenderContext $context): WidgetProviderResult
            {
                return new WidgetProviderResult(items: [], ok: true);
            }
        };
    }

    // ===================================================================
    // Initial creation + same-fingerprint reuse
    // ===================================================================

    public function test_materialize_creates_a_new_pending_manifest_for_a_fresh_source(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);

        $result = $this->materializer()->materialize($widget->id, $theme->id, 'ExampleProject');

        $this->assertTrue($result->wasCreated());
        $this->assertSame(WidgetLegacyManifestMaterializationResult::STATUS_CREATED, $result->status);
        $this->assertSame('pending', $result->eligibilityState);
        $this->assertSame(1, $result->attestationVersion);
        $this->assertNotNull($result->manifest);
        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count());
    }

    public function test_materialize_reuses_the_exact_manifest_on_an_identical_second_call(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $materializer = $this->materializer();

        $first = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        $second = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');

        $this->assertTrue($first->wasCreated());
        $this->assertSame(WidgetLegacyManifestMaterializationResult::STATUS_REUSED, $second->status);
        $this->assertSame($first->manifest->manifestHash, $second->manifest->manifestHash);
        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count(), 'Reuse must never create a duplicate row.');
    }

    // ===================================================================
    // Source drift -> a different pending Manifest, no inherited approval
    // ===================================================================

    public function test_materialize_creates_a_different_manifest_when_theme_content_changes(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $materializer = $this->materializer();

        $before = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        $theme->update(['content' => '<div class="card">{subtitle}</div>']);
        $after = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');

        $this->assertTrue($after->wasCreated());
        $this->assertNotSame($before->manifest->manifestHash, $after->manifest->manifestHash);
        $this->assertSame(2, WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count());
    }

    public function test_materialize_creates_a_different_manifest_when_theme_css_changes(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $materializer = $this->materializer();

        $before = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        $theme->update(['css' => '.card { color: blue; }']);
        $after = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');

        $this->assertTrue($after->wasCreated());
        $this->assertNotSame($before->manifest->manifestHash, $after->manifest->manifestHash);
    }

    public function test_materialize_creates_a_different_manifest_when_widget_javascript_changes(): void
    {
        $widget = $this->createWidget('console.log("v1");');
        $theme = $this->createTheme($widget);
        $materializer = $this->materializer();

        $before = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        $widget->update(['js' => 'console.log("v2");']);
        $after = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');

        $this->assertTrue($after->wasCreated());
        $this->assertNotSame($before->manifest->manifestHash, $after->manifest->manifestHash);
    }

    public function test_materialize_creates_a_different_manifest_when_the_canonical_schema_changes(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $materializer = $this->materializer();

        $before = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $after = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');

        $this->assertTrue($after->wasCreated());
        $this->assertNotSame($before->manifest->manifestHash, $after->manifest->manifestHash);
    }

    public function test_materialize_creates_a_different_manifest_when_applicability_changes(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['project_name' => ['ExampleProject', 'Default']]);
        $materializer = $this->materializer();

        $before = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        $theme->update(['project_name' => ['ExampleProject']]);
        $after = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');

        $this->assertTrue($after->wasCreated());
        $this->assertNotSame($before->manifest->manifestHash, $after->manifest->manifestHash);
    }

    public function test_materialize_creates_a_different_manifest_when_a_provider_contract_changes(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['content' => '<div>{query:post.latest limit="5"}</div>']);

        $registryV1 = new WidgetDataProviderRegistry();
        $registryV1->register(
            $this->fakeProvider('post.latest', 1),
            new WidgetProviderDeclaration(key: 'post.latest', version: 1, argumentSchema: ['limit' => ['type' => 'int']])
        );
        $before = $this->materializer($registryV1)->materialize($widget->id, $theme->id, 'ExampleProject');
        $this->assertNotEmpty($before->manifest->providerContracts, 'Precondition: the detected post.latest key must resolve to a registered contract.');

        $registryChanged = new WidgetDataProviderRegistry();
        $registryChanged->register(
            $this->fakeProvider('post.latest', 1),
            new WidgetProviderDeclaration(key: 'post.latest', version: 1, argumentSchema: ['limit' => ['type' => 'int'], 'category_id' => ['type' => 'int']])
        );
        $after = $this->materializer($registryChanged)->materialize($widget->id, $theme->id, 'ExampleProject');

        $this->assertTrue($after->wasCreated());
        $this->assertNotSame($before->manifest->manifestHash, $after->manifest->manifestHash, 'An undeclared semantic provider change without a version bump must still move the Manifest hash.');
    }

    // ===================================================================
    // Business argument non-explosion
    // ===================================================================

    public function test_materialize_is_insensitive_to_an_inputs_default_value_change(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $input = WidgetInput::query()->create([
            'widget_id' => $widget->id,
            'name' => 'title',
            'type' => 'text',
            'default_value' => 'Hello',
        ]);
        $materializer = $this->materializer();

        $before = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        $input->update(['default_value' => 'A completely different ordinary business value']);
        $after = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');

        $this->assertSame(
            $before->manifest->manifestHash,
            $after->manifest->manifestHash,
            'An Input default_value is ordinary business data (only its canonical `type` is schema), never Manifest identity.'
        );
        $this->assertSame(WidgetLegacyManifestMaterializationResult::STATUS_REUSED, $after->status);
        $this->assertSame(1, WidgetArtifactManifestModel::query()->count());
    }

    // ===================================================================
    // Skip paths: no Blob/Manifest write happens for any of these
    // ===================================================================

    public function test_materialize_returns_invalid_pairing_when_the_theme_belongs_to_a_different_widget(): void
    {
        $widgetA = $this->createWidget();
        $widgetB = $this->createWidget();
        $themeOfB = $this->createTheme($widgetB);

        $result = $this->materializer()->materialize($widgetA->id, $themeOfB->id, 'ExampleProject');

        $this->assertSame(WidgetLegacyManifestMaterializationResult::STATUS_INVALID_PAIRING, $result->status);
        $this->assertNull($result->manifest);
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count());
    }

    public function test_materialize_returns_invalid_pairing_for_a_nonexistent_widget_and_theme(): void
    {
        $result = $this->materializer()->materialize(999999, 888888, 'ExampleProject');

        $this->assertSame(WidgetLegacyManifestMaterializationResult::STATUS_INVALID_PAIRING, $result->status);
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count());
    }

    public function test_materialize_returns_not_applicable_when_the_project_is_outside_applicability(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        $result = $this->materializer()->materialize($widget->id, $theme->id, 'SomeOtherProject');

        $this->assertSame(WidgetLegacyManifestMaterializationResult::STATUS_NOT_APPLICABLE, $result->status);
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count());
    }

    public function test_a_default_theme_materializes_for_any_project_under_that_project_only(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['project_name' => ['Default']]);

        $result = $this->materializer()->materialize($widget->id, $theme->id, 'SomeOtherProject');

        $this->assertTrue($result->isPersisted(), $result->status);
        $this->assertSame(['SomeOtherProject'], WidgetArtifactManifestModel::query()->pluck('project_name')->all());
    }

    public function test_materialize_returns_source_fingerprint_stale_when_the_expectation_no_longer_matches(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);

        $result = $this->materializer()->materialize($widget->id, $theme->id, 'ExampleProject', 'not-the-real-fingerprint');

        $this->assertSame(WidgetLegacyManifestMaterializationResult::STATUS_SOURCE_FINGERPRINT_STALE, $result->status);
        $this->assertNotNull($result->sourceFingerprintHash);
        $this->assertSame(0, WidgetArtifactManifestModel::query()->count(), 'A stale client expectation must write nothing.');
    }

    public function test_materialize_proceeds_when_no_expectation_is_supplied(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);

        $result = $this->materializer()->materialize($widget->id, $theme->id, 'ExampleProject', null);

        $this->assertTrue($result->isPersisted());
    }

    public function test_materialize_succeeds_when_the_expectation_exactly_matches_the_live_source(): void
    {
        $widget = $this->createWidget('console.log("expect");');
        $theme = $this->createTheme($widget);

        // The wire-level expected_source_fingerprint is the Task-9c
        // composite source hash (content/CSS/JS/schema/policy/trust/
        // applicability) -- independently computed here via the SAME
        // analyzer, with the SAME inputs resolveSource() itself would use,
        // to obtain a genuine "the client read this value earlier" fixture
        // rather than reaching into the materializer's private internals.
        $liveFingerprint = (new LegacyWidgetExecutableSurfaceAnalyzer())->analyze(
            widgetJs: (string) $widget->js,
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: (new CanonicalWidgetSchema())->resolve($widget->id, $theme->id),
            policyVersion: ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: ['ExampleProject'],
            artifactBytes: (new LegacyWidgetArtifactSetPublisher())->renderThemeContent($theme),
        );

        $result = $this->materializer()->materialize($widget->id, $theme->id, 'ExampleProject', $liveFingerprint->manifestHash);

        $this->assertTrue($result->isPersisted());
        $this->assertSame(WidgetLegacyManifestMaterializationResult::STATUS_CREATED, $result->status);
    }

    // ===================================================================
    // Cross-project isolation / fan-out
    // ===================================================================

    public function test_materialize_fans_out_a_separate_manifest_row_per_named_project_sharing_the_same_hash(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget, ['project_name' => ['ExampleProject', 'ProjectB']]);
        $materializer = $this->materializer();

        $resultA = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        $resultB = $materializer->materialize($widget->id, $theme->id, 'ProjectB');

        $this->assertTrue($resultA->wasCreated());
        $this->assertTrue($resultB->wasCreated());
        $this->assertSame(
            $resultA->manifest->manifestHash,
            $resultB->manifest->manifestHash,
            'Identical source content -> identical manifest_hash regardless of which project owns the row (D-012).'
        );
        $this->assertNotSame(
            $resultA->manifest->artifactBlob->storageKey,
            $resultB->manifest->artifactBlob->storageKey,
            'Blob storage stays project-scoped -- MVP never deduplicates across projects.'
        );
        $this->assertSame(2, WidgetArtifactManifestModel::query()->count());
        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count());
        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ProjectB')->count());
    }

    // ===================================================================
    // Hash-parity pin: the materializer's independent pre-check hash must
    // exactly match WidgetArtifactBuilder's own real computation.
    // ===================================================================

    public function test_the_independently_computed_manifest_hash_matches_the_real_artifact_builder_behaviorally(): void
    {
        $widget = $this->createWidget('console.log("pin");');
        $theme = $this->createTheme($widget, ['content' => '<div>{query:post.latest limit="5"}</div>']);

        $registry = new WidgetDataProviderRegistry();
        $registry->register($this->fakeProvider('post.latest', 1), new WidgetProviderDeclaration(key: 'post.latest', version: 1));
        $materializer = $this->materializer($registry);

        $result = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        $this->assertTrue($result->wasCreated());

        // If the materializer's own pre-check hash did not exactly equal
        // the builder's real returned manifest_hash, this second identical
        // call would incorrectly report "created" again instead of
        // "reused" -- the executable proof the two algorithms agree.
        $second = $materializer->materialize($widget->id, $theme->id, 'ExampleProject');
        $this->assertSame(WidgetLegacyManifestMaterializationResult::STATUS_REUSED, $second->status);
        $this->assertSame($result->manifest->manifestHash, $second->manifest->manifestHash);
        $this->assertSame(1, WidgetArtifactManifestModel::query()->count());
    }

    public function test_compute_manifest_hash_matches_a_direct_builder_call_for_identical_inputs_via_reflection(): void
    {
        $analyzer = new LegacyWidgetExecutableSurfaceAnalyzer();
        $fingerprint = $analyzer->analyze(
            widgetJs: 'console.log("reflect");',
            themeContent: '<div>{title}</div>',
            themeCss: '.x{}',
            canonicalSchema: ['title' => ['type' => 'text']],
            policyVersion: ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: ['ExampleProject'],
            artifactBytes: 'FINAL BYTES',
        );

        $manifest = $this->builder->build(
            projectName: 'ReflectProject',
            finalBytes: 'FINAL BYTES',
            mediaType: 'text/x-blade-template',
            generatorVersion: WidgetLegacyManifestMaterializer::GENERATOR_VERSION,
            rendererKey: WidgetLegacyManifestMaterializer::RENDERER_KEY,
            rendererVersion: WidgetLegacyManifestMaterializer::RENDERER_VERSION,
            executableSurfaceFingerprint: $fingerprint,
            providerContracts: [],
        );

        $materializer = $this->materializer();
        $reflection = new ReflectionMethod($materializer, 'computeManifestHash');
        $reflection->setAccessible(true);
        $computed = $reflection->invoke($materializer, hash('sha256', 'FINAL BYTES'), $fingerprint, []);

        $this->assertSame(
            $manifest->manifestHash,
            $computed,
            'WidgetLegacyManifestMaterializer::computeManifestHash() must byte-for-byte match WidgetArtifactBuilder::build()\'s own manifest_hash for identical inputs.'
        );
    }

    // ===================================================================
    // WidgetLegacyManifestDriftReader
    // ===================================================================

    public function test_drift_reader_reports_a_never_materialized_pair_as_drifted(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);

        $reader = new WidgetLegacyManifestDriftReader(new LegacyWidgetArtifactSetPublisher(), $this->materializer());
        $report = $reader->report();

        $this->assertCount(1, $report['drifted']);
        $this->assertSame($widget->id, $report['drifted'][0]['widget_id']);
        $this->assertSame($theme->id, $report['drifted'][0]['theme_id']);
        $this->assertSame('ExampleProject', $report['drifted'][0]['project_name']);
        $this->assertSame([], $report['current']);
        $this->assertSame([], $report['incidents']);
    }

    public function test_drift_reader_reports_a_materialized_pair_as_current_with_safe_fields_only(): void
    {
        $widget = $this->createWidget();
        $theme = $this->createTheme($widget);
        $materializer = $this->materializer();
        $materializer->materialize($widget->id, $theme->id, 'ExampleProject');

        $reader = new WidgetLegacyManifestDriftReader(new LegacyWidgetArtifactSetPublisher(), $materializer);
        $report = $reader->report();

        $this->assertSame([], $report['drifted']);
        $this->assertCount(1, $report['current']);
        $entry = $report['current'][0];
        $this->assertSame('pending', $entry['eligibility_state']);
        $this->assertSame(1, $entry['attestation_version']);
        $this->assertArrayNotHasKey('content', $entry);
        $this->assertArrayNotHasKey('css', $entry);
        $this->assertArrayNotHasKey('storage_key', $entry);
        $this->assertArrayNotHasKey('bytes', $entry);
    }

    public function test_drift_reader_never_leaks_theme_source_or_storage_paths_in_its_serialized_output(): void
    {
        $widget = $this->createWidget('SECRET_JS_MARKER_XYZ();');
        $theme = $this->createTheme($widget, ['content' => 'SECRET_CONTENT_MARKER_XYZ']);
        $materializer = $this->materializer();
        $materializer->materialize($widget->id, $theme->id, 'ExampleProject');

        $reader = new WidgetLegacyManifestDriftReader(new LegacyWidgetArtifactSetPublisher(), $materializer);
        $serialized = json_encode($reader->report(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('SECRET_JS_MARKER_XYZ', $serialized);
        $this->assertStringNotContainsString('SECRET_CONTENT_MARKER_XYZ', $serialized);
        $this->assertStringNotContainsString($this->storageRoot, $serialized);
    }

    public function test_drift_reader_report_is_robust_to_a_pair_that_vanishes_mid_scan(): void
    {
        // WidgetLegacyManifestDriftReader::report() never touches Blob
        // storage (inspect() is pure computation + DB reads only), so a
        // genuine per-pair I/O incident cannot be forced here the same way
        // the listener test forces one against the Blob store; this proves
        // the narrower but still real guarantee that a row disappearing
        // between the map snapshot and inspect() resolves as a clean skip
        // rather than aborting or corrupting the rest of the report.
        $widgetA = $this->createWidget();
        $themeA = $this->createTheme($widgetA, ['project_name' => ['ExampleProject']]);
        $widgetB = $this->createWidget();
        $this->createTheme($widgetB, ['project_name' => ['ExampleProject'], 'content' => '<div>distinct B content</div>']);

        $themeA->delete();

        $reader = new WidgetLegacyManifestDriftReader(new LegacyWidgetArtifactSetPublisher(), $this->materializer());
        $report = $reader->report();

        $this->assertCount(1, $report['drifted'], 'The healthy pair (themeB) must still be reported even though themeA vanished mid-scan.');
        $this->assertSame([], $report['incidents']);
    }

    // ===================================================================
    // Listener: after-commit, idempotent, fans out per named affected
    // project, regardless of ready|pending sync status
    // ===================================================================

    public function test_listener_materializes_every_theme_applicable_to_the_events_affected_projects(): void
    {
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        $listener = new MaterializeLegacyWidgetManifest(new LegacyWidgetArtifactSetPublisher(), $this->materializer());
        $listener->handle(new LegacyWidgetDefinitionChanged(['ExampleProject'], 'ready'));

        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count());
    }

    public function test_listener_still_materializes_when_the_sync_status_is_pending(): void
    {
        // checklist: "regardless of legacy sync ready|pending" -- a legacy
        // publish-side incident never blocks Site Layout materialization,
        // because the underlying Widget/Theme DB mutation already committed.
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        $listener = new MaterializeLegacyWidgetManifest(new LegacyWidgetArtifactSetPublisher(), $this->materializer());
        $listener->handle(new LegacyWidgetDefinitionChanged(['ExampleProject'], 'pending', 'incident-123'));

        $this->assertSame(1, WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count());
    }

    public function test_listener_is_idempotent_when_the_event_fires_twice_for_unchanged_content(): void
    {
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        $listener = new MaterializeLegacyWidgetManifest(new LegacyWidgetArtifactSetPublisher(), $this->materializer());
        $listener->handle(new LegacyWidgetDefinitionChanged(['ExampleProject'], 'ready'));
        $listener->handle(new LegacyWidgetDefinitionChanged(['ExampleProject'], 'ready'));

        $this->assertSame(
            1,
            WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count(),
            'Re-running the listener for unchanged content must never create a duplicate row.'
        );
    }

    public function test_listener_ignores_a_project_not_named_in_the_event(): void
    {
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        $listener = new MaterializeLegacyWidgetManifest(new LegacyWidgetArtifactSetPublisher(), $this->materializer());
        $listener->handle(new LegacyWidgetDefinitionChanged(['SomeUnrelatedProject'], 'ready'));

        $this->assertSame(0, WidgetArtifactManifestModel::query()->count());
    }

    // ===================================================================
    // Container wiring (spec-review rider): the module EventServiceProvider
    // $listen mapping connects event -> listener through the container --
    // proven both declaratively (assertListening) and end-to-end (a real
    // event() dispatch through the fully booted provider chain).
    // ===================================================================

    public function test_module_event_service_provider_registers_the_listener_for_the_root_event(): void
    {
        // Registering on an already-booted app runs the provider's booting
        // callbacks immediately (Application::register() -> bootProvider()),
        // which is when Laravel's base EventServiceProvider installs the
        // $listen mapping into the real dispatcher.
        $this->app->register(WidgetEventServiceProvider::class);

        // Event::fake() wraps the real dispatcher while keeping a reference
        // to it; assertListening() consults that wrapped original, so the
        // just-registered mapping is exactly what is being asserted here.
        Event::fake([LegacyWidgetDefinitionChanged::class]);
        Event::assertListening(LegacyWidgetDefinitionChanged::class, MaterializeLegacyWidgetManifest::class);
    }

    public function test_dispatching_the_root_event_through_the_container_materializes_via_the_wired_listener(): void
    {
        // Boot the FULL provider chain exactly as production does --
        // WidgetServiceProvider::register() pulls in the module
        // EventServiceProvider (listener wiring) and boot() binds the
        // artifact repositories -- then rebind the Blob store to this
        // test's isolated temp root (same post-boot rebind pattern as
        // WidgetLegacyManifestReconciliationCommandTest, valid because
        // nothing resolves the store singleton before the dispatch below).
        $provider = new WidgetServiceProvider($this->app);
        $provider->register();
        $provider->boot();
        $this->app->singleton(
            FilesystemWidgetArtifactBlobStore::class,
            fn (): FilesystemWidgetArtifactBlobStore => new FilesystemWidgetArtifactBlobStore($this->storageRoot)
        );

        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject']]);

        // No listener is invoked by hand here: the ONLY path from this
        // dispatch to a persisted row is dispatcher -> $listen mapping ->
        // container-resolved MaterializeLegacyWidgetManifest.
        event(new LegacyWidgetDefinitionChanged(['ExampleProject'], 'ready'));

        $this->assertSame(
            1,
            WidgetArtifactManifestModel::query()->where('project_name', 'ExampleProject')->count(),
            'A real event() dispatch through the booted provider chain must reach the wired listener and materialize.'
        );
    }

    public function test_listener_survives_a_thrown_per_pair_failure_without_aborting_the_batch(): void
    {
        $widgetA = $this->createWidget();
        $this->createTheme($widgetA, ['project_name' => ['ExampleProject']]);
        $widgetB = $this->createWidget();
        $this->createTheme($widgetB, ['project_name' => ['ExampleProject'], 'content' => '<div>distinct B content</div>']);

        // A storage root that is a plain FILE (not a directory) makes
        // FilesystemWidgetArtifactBlobStore::write() throw a genuine
        // RuntimeException for every pair -- proving a real thrown failure
        // never escapes the listener nor crashes the process (report()
        // is called, no exception propagates out of handle()).
        $brokenStorageRoot = $this->storageRoot . '-broken-file';
        file_put_contents($brokenStorageRoot, 'not a directory');
        $brokenStore = new FilesystemWidgetArtifactBlobStore($brokenStorageRoot);
        $brokenBlobRepository = new DatabaseWidgetArtifactBlobRepository($brokenStore);
        $brokenBuilder = new WidgetArtifactBuilder($brokenBlobRepository, $this->manifestRepository);
        $brokenMaterializer = new WidgetLegacyManifestMaterializer(
            publisher: new LegacyWidgetArtifactSetPublisher(),
            schemaResolver: new CanonicalWidgetSchema(),
            analyzer: new LegacyWidgetExecutableSurfaceAnalyzer(),
            builder: $brokenBuilder,
            manifestRepository: $this->manifestRepository,
            providerRegistry: new WidgetDataProviderRegistry(),
        );

        $listener = new MaterializeLegacyWidgetManifest(new LegacyWidgetArtifactSetPublisher(), $brokenMaterializer);

        try {
            $listener->handle(new LegacyWidgetDefinitionChanged(['ExampleProject'], 'ready'));
        } catch (RuntimeException $e) {
            $this->fail('The listener must catch and report per-pair failures, never let one escape handle(): ' . $e->getMessage());
        } finally {
            @unlink($brokenStorageRoot);
        }

        $this->assertSame(0, WidgetArtifactManifestModel::query()->count(), 'Every pair failed to persist, but handle() itself must not throw.');
    }

    public function test_listener_logs_the_event_incident_id_when_a_pair_fails(): void
    {
        // The event docblock advertises that incidentId lets a listener-side
        // failure be correlated in logs with the legacy publish incident
        // that preceded it. Prove the listener actually records it, with
        // safe context only (exception CLASS name, never its message).
        $widget = $this->createWidget();
        $this->createTheme($widget, ['project_name' => ['ExampleProject'], 'content' => 'SECRET_CONTENT_MARKER_XYZ']);

        $brokenStorageRoot = $this->storageRoot . '-broken-file-incident';
        file_put_contents($brokenStorageRoot, 'not a directory');
        $brokenStore = new FilesystemWidgetArtifactBlobStore($brokenStorageRoot);
        $brokenBuilder = new WidgetArtifactBuilder(new DatabaseWidgetArtifactBlobRepository($brokenStore), $this->manifestRepository);
        $brokenMaterializer = new WidgetLegacyManifestMaterializer(
            publisher: new LegacyWidgetArtifactSetPublisher(),
            schemaResolver: new CanonicalWidgetSchema(),
            analyzer: new LegacyWidgetExecutableSurfaceAnalyzer(),
            builder: $brokenBuilder,
            manifestRepository: $this->manifestRepository,
            providerRegistry: new WidgetDataProviderRegistry(),
        );

        Log::spy();

        try {
            (new MaterializeLegacyWidgetManifest(new LegacyWidgetArtifactSetPublisher(), $brokenMaterializer))
                ->handle(new LegacyWidgetDefinitionChanged(['ExampleProject'], 'pending', 'incident-abc-123'));
        } finally {
            @unlink($brokenStorageRoot);
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []): bool {
                return ($context['incident_id'] ?? null) === 'incident-abc-123'
                    && ($context['sync_status'] ?? null) === 'pending'
                    && ($context['project_name'] ?? null) === 'ExampleProject'
                    && array_key_exists('exception_class', $context)
                    // Safety: the failure breadcrumb must never carry raw source.
                    && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'SECRET_CONTENT_MARKER_XYZ');
            })
            ->atLeast()->once();
    }
}
