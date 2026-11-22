<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Rendering;

use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetEligibilityAttestation;
use Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlReader;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDOException;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * Chunk 2 Task 15c: proves {@see ApprovedLegacyWidgetRenderer}'s new
 * emergency-control read (checklist item 2) without touching the
 * read-only, frozen {@see \Cms\Widget\Tests\Feature\Rendering\ApprovedLegacyWidgetRendererTest}
 * (Task 9c) -- this is a SEPARATE test file, exercising the SAME renderer
 * through the SAME public render() signature.
 *
 * IMPORTANT DISCLOSED DESIGN NOTE (see {@see WidgetRuntimeControlReader}'s
 * class docblock "FOUR-STATE MODEL" for the full reasoning): a Manifest
 * row that is genuinely UNTRACKED (no row found, read succeeded) is
 * treated as SAFE TO RENDER, not a fail-soft case -- a deliberate,
 * disclosed narrowing of the plan's "missing...fails soft" language,
 * required because the read-only Task 9c suite constructs every "eligible"
 * attestation as a bare VO with no underlying row at all, and this task's
 * allowed_paths forbids editing that file. Only STATE_BLOCKED (row found,
 * blocked=true) and STATE_UNAVAILABLE (the read itself raised an
 * exception) fail soft. This file's own tests below prove BOTH: the
 * untracked-proceeds behavior (so this suite's own fixtures don't
 * silently rely on accidentally matching a row) AND the blocked/unavailable
 * fail-soft behavior the checklist centrally requires.
 */
final class WidgetManifestRuntimeBlockRenderTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    /**
     * The widget_artifact_manifests/widget_artifact_blobs tables (Task 13a)
     * live under cms/Widget/database/migrations, outside the default
     * RefreshDatabase migration path -- see WidgetManifestApprovalApiTest::beforeRefreshingDatabase()
     * (Task 15b) for the full explanation this mirrors.
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

        $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-runtime-block-render-' . uniqid('', true);
        File::ensureDirectoryExists($this->isolatedStoragePath . '/app/generated_widgets/default');
        $this->app->useStoragePath($this->isolatedStoragePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->isolatedStoragePath);

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers (mirrors ApprovedLegacyWidgetRendererTest's own
    // helpers, Task 9c -- duplicated here rather than shared because that
    // file is read-only and this suite has additional needs: real
    // WidgetArtifactManifest rows).
    // -----------------------------------------------------------------

    private function writeGeneratedTemplate(int $widgetId, int $themeId, string $content): void
    {
        $path = storage_path("app/generated_widgets/default/widget_{$widgetId}_theme_{$themeId}.blade.php");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
    }

    private function renderer(): ApprovedLegacyWidgetRenderer
    {
        return $this->app->make(ApprovedLegacyWidgetRenderer::class);
    }

    private function applicableProjectNames(mixed $projectName): array
    {
        if (!is_array($projectName)) {
            return !empty($projectName) ? [(string) $projectName] : ['Default'];
        }

        return array_values(array_map('strval', $projectName));
    }

    /**
     * @param array{policyVersion?: string, trustLane?: string, applicability?: list<string>} $overrides
     */
    private function attestationFor(Widget $widget, WidgetTheme $theme, bool $revoked = false, array $overrides = []): WidgetEligibilityAttestation
    {
        $schema = app(CanonicalWidgetSchemaResolver::class)->resolve($widget->id, $theme->id);
        $applicability = $this->applicableProjectNames($theme->project_name);

        $liveFingerprint = (new LegacyWidgetExecutableSurfaceAnalyzer())->analyze(
            widgetJs: (string) $widget->js,
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: $schema,
            policyVersion: ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: $applicability,
            artifactBytes: '',
        );

        return new WidgetEligibilityAttestation(
            projectName: 'Default',
            manifestHash: $liveFingerprint->manifestHash,
            policyVersion: $overrides['policyVersion'] ?? $liveFingerprint->policyVersion,
            trustLane: $overrides['trustLane'] ?? $liveFingerprint->trustLane,
            applicability: $overrides['applicability'] ?? $liveFingerprint->applicability,
            approvedBy: 'approver@example.test',
            approvedAt: new DateTimeImmutable('2026-07-17T00:00:00+00:00'),
            attestationVersion: 1,
            revoked: $revoked,
        );
    }

    /**
     * Creates a REAL widget_artifact_manifests row whose
     * executable_surface_fingerprint_hash matches the given attestation's
     * manifestHash -- the identity WidgetRuntimeControlReader looks up
     * (see its class docblock "MANIFEST IDENTITY BRIDGING").
     *
     * @param array<string, mixed> $overrides
     */
    private function trackManifest(WidgetEligibilityAttestation $attestation, array $overrides = []): WidgetArtifactManifestModel
    {
        $blob = WidgetArtifactBlob::query()->create([
            'project_name' => $attestation->projectName,
            'artifact_hash' => hash('sha256', 'blob-' . uniqid('', true)),
            'storage_disk' => 'local',
            'storage_key' => 'widget_artifacts/' . uniqid('', true) . '.blade.php',
            'byte_size' => 42,
            'media_type' => 'text/x-blade-template',
            'checksum' => hash('sha256', 'checksum-' . uniqid('', true)),
        ]);

        return WidgetArtifactManifestModel::query()->create(array_merge([
            'project_name' => $attestation->projectName,
            'manifest_hash' => hash('sha256', 'composite-manifest-' . uniqid('', true)),
            'artifact_blob_id' => $blob->id,
            'generator_version' => 'legacy-widget-artifact-set-publisher-v1',
            'renderer_key' => 'legacy-blade-widget',
            'renderer_version' => 'v1',
            'applicability' => $attestation->applicability,
            'executable_surface_fingerprint_hash' => $attestation->manifestHash,
            'canonical_schema_hash' => hash('sha256', 'schema-' . uniqid('', true)),
            'provider_contracts' => [],
            'trust_policy_version' => $attestation->policyVersion,
            'trust_lane' => $attestation->trustLane,
            'eligibility_state' => 'approved',
            'attestation_version' => 1,
            'control_version' => 1,
            'blocked' => false,
        ], $overrides));
    }

    /**
     * Simulates a brand new incoming HTTP request by rebinding a fresh
     * Illuminate\Http\Request into the container -- WidgetRuntimeControlReader's
     * per-request memo is keyed off the shared Request instance (see its
     * class docblock "MEMOIZATION"), so this is how a single test method
     * proves cross-request read-after-write visibility without a stale
     * within-request cache hit masking a real re-read.
     */
    private function simulateNewIncomingRequest(): void
    {
        $this->app->instance('request', Request::create('/'));
    }

    // -----------------------------------------------------------------
    // Fixture: one widget/theme, ready to render.
    // -----------------------------------------------------------------

    /**
     * @return array{0: Widget, 1: WidgetTheme}
     */
    private function widgetAndTheme(string $marker = 'render-ok'): array
    {
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => "<section data-marker=\"{$marker}\">{title}</section>"]);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $this->writeGeneratedTemplate($widget->id, $theme->id, "<section data-marker=\"{$marker}\">{title}</section>");

        return [$widget, $theme];
    }

    // ===================================================================
    // Core fail-soft matrix: blocked, unavailable, untracked, safe
    // ===================================================================

    public function test_render_succeeds_when_the_manifest_is_tracked_and_not_blocked(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);
        $this->trackManifest($attestation, ['blocked' => false]);

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Visible']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('Visible', $result->html);
    }

    public function test_render_fails_soft_when_the_tracked_manifest_is_blocked(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);
        $this->trackManifest($attestation, [
            'blocked' => true,
            'control_version' => 2,
            'blocked_by' => 1,
            'blocked_at' => now(),
            'control_reason' => 'Legal takedown in progress.',
        ]);

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Should Not Appear']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame(ApprovedLegacyWidgetRenderer::ERROR_RUNTIME_CONTROL_BLOCKED, $result->errorCode);
        $this->assertStringNotContainsString('Should Not Appear', $result->html);
    }

    public function test_render_succeeds_when_the_manifest_is_untracked_preserving_the_persistence_agnostic_contract(): void
    {
        // No WidgetArtifactManifest row created at all -- proves the
        // disclosed STATE_UNTRACKED-proceeds narrowing (class docblock)
        // that keeps this task from regressing the read-only Task 9c
        // suite's own persistence-agnostic attestations.
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Untracked But Fine']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('Untracked But Fine', $result->html);
    }

    public function test_render_fails_soft_when_the_control_backend_read_itself_fails(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);
        $this->trackManifest($attestation);

        // Force a genuine STATE_UNAVAILABLE (NOT a missing-table
        // STATE_UNTRACKED -- see WidgetRuntimeControlReader's class
        // docblock "SCHEMA-NOT-PROVISIONED ALSO COUNTS AS UNTRACKED, NOT
        // UNAVAILABLE"): a DB::listen() callback that throws for any query
        // touching this table simulates a genuine control-backend outage.
        // This is NOT a QueryException (so it correctly bypasses the
        // "missing table" carve-out entirely, unlike attempting to strip
        // columns from the table -- SQLite silently treats a double-quoted
        // unknown identifier as a harmless string-literal comparison rather
        // than erroring, which defeated an earlier column-stripping
        // attempt at simulating this scenario).
        DB::listen(static function ($query): void {
            if (str_contains($query->sql, 'widget_artifact_manifests')) {
                throw new RuntimeException('Simulated control backend outage.');
            }
        });

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Should Not Appear']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame(ApprovedLegacyWidgetRenderer::ERROR_RUNTIME_CONTROL_UNKNOWN, $result->errorCode);
    }

    public function test_a_missing_table_is_treated_as_untracked_not_unavailable(): void
    {
        // Companion proof to the disclosed "SCHEMA-NOT-PROVISIONED ALSO
        // COUNTS AS UNTRACKED" narrowing: a genuinely missing TABLE (as
        // opposed to a table that exists but errors) proceeds to render,
        // exactly like the read-only Task 9c suite's own environment.
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);

        Schema::drop('widget_artifact_manifests');

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Still Renders']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('Still Renders', $result->html);
    }

    // ===================================================================
    // Security finding 1: any blocked fingerprint-sibling forces a block.
    // executable_surface_fingerprint_hash is NOT unique per project, so the
    // render-path OR-lookup can match several rows; an unblocked sibling
    // must never mask a block set on the composite hash a Release promoted.
    // ===================================================================

    public function test_a_blocked_fingerprint_sibling_forces_a_block_even_when_an_unblocked_sibling_exists(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);

        // Two rows sharing the SAME executable_surface_fingerprint_hash (the
        // identity the render path keys on) but DIFFERENT composite
        // manifest_hash -- exactly what a generator/renderer-version bump
        // over a byte-identical surface produces. The UNBLOCKED sibling is
        // inserted FIRST (so a naive ->first() could return it and mask the
        // block); the BLOCKED composite-hash row second.
        $this->trackManifest($attestation, ['blocked' => false]);
        $this->trackManifest($attestation, ['blocked' => true, 'control_version' => 2]);

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Must Not Render']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertFalse($result->ok, 'Any blocked fingerprint-sibling must force a block -- an unblocked sibling must never mask it.');
        $this->assertSame('', $result->html);
        $this->assertSame(ApprovedLegacyWidgetRenderer::ERROR_RUNTIME_CONTROL_BLOCKED, $result->errorCode);
    }

    public function test_block_resolution_is_order_independent_with_the_blocked_sibling_inserted_first(): void
    {
        // Reversed insertion order from the test above, proving the "any
        // blocked wins" reduction is genuinely order-independent (not merely
        // relying on the blocked row happening to sort last/first).
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);

        $this->trackManifest($attestation, ['blocked' => true, 'control_version' => 2]);
        $this->trackManifest($attestation, ['blocked' => false]);

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Must Not Render']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertFalse($result->ok);
        $this->assertSame(ApprovedLegacyWidgetRenderer::ERROR_RUNTIME_CONTROL_BLOCKED, $result->errorCode);
    }

    public function test_all_unblocked_fingerprint_siblings_still_render_safely(): void
    {
        // The SAFE outcome must still be reachable: rows exist, none blocked.
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);

        $this->trackManifest($attestation, ['blocked' => false]);
        $this->trackManifest($attestation, ['blocked' => false, 'control_version' => 2]);

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Safe Render']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertTrue($result->ok, 'Multiple unblocked siblings and no blocked row must render.');
        $this->assertStringContainsString('Safe Render', $result->html);
    }

    // ===================================================================
    // Security finding 2: the undefined-table carve-out is SQLSTATE-gated,
    // not a broad "does not exist" substring. A column-missing (or any
    // non-undefined-TABLE) QueryException must fail soft as UNAVAILABLE,
    // never be misread as UNTRACKED (which renders).
    // ===================================================================

    public function test_an_undefined_column_query_exception_fails_soft_as_unavailable_not_untracked(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);
        $this->trackManifest($attestation);

        // The message deliberately contains "does not exist" -- the exact
        // broad substring the earlier carve-out matched -- to prove the
        // SQLSTATE-gated narrowing now correctly REJECTS a column fault. The
        // wrapped exception's code is 0 (not an undefined-table SQLSTATE),
        // and the message is a column error, so this must fail soft.
        DB::listen(static function ($query): void {
            if (str_contains($query->sql, 'widget_artifact_manifests')) {
                throw new QueryException(
                    'pgsql',
                    $query->sql,
                    $query->bindings,
                    new RuntimeException('SQLSTATE[42703]: Undefined column: 7 ERROR: column "blocked" does not exist'),
                );
            }
        });

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Should Not Render']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertFalse($result->ok, 'A column-missing fault (not an undefined TABLE) must fail soft, never render.');
        $this->assertSame('', $result->html);
        $this->assertSame(ApprovedLegacyWidgetRenderer::ERROR_RUNTIME_CONTROL_UNKNOWN, $result->errorCode);
    }

    public function test_a_postgres_undefined_table_sqlstate_still_untracks_despite_a_does_not_exist_message(): void
    {
        // Positive complement: a REAL undefined-TABLE fault on PostgreSQL
        // carries SQLSTATE 42P01 AND a "relation ... does not exist"
        // message. The SQLSTATE -- not the message -- is the discriminator,
        // so this must UNTRACK (render) even though its message contains the
        // same "does not exist" phrase the column fault above did.
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);
        $this->trackManifest($attestation);

        $previous = new PDOException('SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "widget_artifact_manifests" does not exist');
        $codeProperty = new ReflectionProperty(Exception::class, 'code');
        $codeProperty->setAccessible(true);
        $codeProperty->setValue($previous, '42P01');

        DB::listen(static function ($query) use ($previous): void {
            if (str_contains($query->sql, 'widget_artifact_manifests')) {
                throw new QueryException('pgsql', $query->sql, $query->bindings, $previous);
            }
        });

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Untracked Renders']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertTrue($result->ok, 'A real undefined-TABLE SQLSTATE (42P01) must untrack and render, discriminated by code not message.');
        $this->assertStringContainsString('Untracked Renders', $result->html);
    }

    // ===================================================================
    // No sensitive reason leakage publicly (checklist item 7)
    // ===================================================================

    public function test_blocked_reason_text_never_appears_anywhere_in_the_render_result(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);
        $sensitiveReason = 'CONFIDENTIAL-LEGAL-CASE-REF-99213-do-not-leak';
        $this->trackManifest($attestation, [
            'blocked' => true,
            'control_version' => 2,
            'control_reason' => $sensitiveReason,
        ]);

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Hidden']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertFalse($result->ok);
        $this->assertStringNotContainsString($sensitiveReason, $result->html);
        $this->assertStringNotContainsString($sensitiveReason, (string) $result->errorCode);
    }

    // ===================================================================
    // Active output unchanged after ordinary revoke (regression guard):
    // render() only ever consults the PASSED attestation for eligibility,
    // never re-reads eligibility_state from the database.
    // ===================================================================

    public function test_active_output_unchanged_after_an_ordinary_revoke_elsewhere_because_eligibility_trusts_the_passed_attestation(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);
        $manifest = $this->trackManifest($attestation, ['eligibility_state' => 'approved']);

        $spec = new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Still Here']);
        $context = new WidgetRenderContext(projectName: 'Default');

        $before = $this->renderer()->render($spec, $context, $attestation);
        $this->assertTrue($before->ok);

        // Ordinary revoke elsewhere (Task 15b's own endpoint would do
        // exactly this) -- does NOT touch the emergency control axis.
        $manifest->forceFill(['eligibility_state' => 'revoked', 'revoked_by' => 1, 'revoked_at' => now()])->save();

        // Same original attestation object, simulating an active Release's
        // own promotion-time snapshot -- must still render identically.
        $after = $this->renderer()->render($spec, $context, $attestation);

        $this->assertTrue($after->ok, 'An ordinary revoke elsewhere must not affect a render call using an already-promoted attestation.');
        $this->assertSame($before->html, $after->html);
    }

    // ===================================================================
    // Shared-store read-after-write visibility across "public nodes"
    // (simulated via a fresh incoming Request, since php-fpm is
    // request-per-process per Task 5a evidence -- every request is
    // effectively a fresh "node" observation in this proven topology).
    // ===================================================================

    public function test_shared_store_read_after_write_the_render_path_observes_a_block_set_directly_in_the_database(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);
        $manifest = $this->trackManifest($attestation, ['blocked' => false]);

        $spec = new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Before Block']);
        $context = new WidgetRenderContext(projectName: 'Default');

        $before = $this->renderer()->render($spec, $context, $attestation);
        $this->assertTrue($before->ok, 'Must render normally before any block.');

        // A DIFFERENT process (the admin block endpoint, in production)
        // writes directly to the shared row.
        $manifest->forceFill(['blocked' => true, 'control_version' => 2])->save();

        // Simulate the NEXT incoming public request (a fresh php-fpm
        // request-per-process, per the proven topology) observing the
        // freshly written state -- not a stale within-request memo.
        $this->simulateNewIncomingRequest();

        $after = $this->renderer()->render($spec, $context, $attestation);

        $this->assertFalse($after->ok, 'The very next request must observe the freshly written block -- shared read-after-write visibility.');
        $this->assertSame(ApprovedLegacyWidgetRenderer::ERROR_RUNTIME_CONTROL_BLOCKED, $after->errorCode);
    }

    // ===================================================================
    // One read per unique (project_name, manifest_hash) per request
    // ===================================================================

    public function test_one_database_read_per_unique_project_and_manifest_hash_per_request(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();
        $attestation = $this->attestationFor($widget, $theme);
        $this->trackManifest($attestation, ['blocked' => false]);

        $spec = new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Repeated Render']);
        $context = new WidgetRenderContext(projectName: 'Default');

        // Two separate "Slot" render calls for the SAME identity within
        // ONE simulated request (no simulateNewIncomingRequest() call
        // between them).
        $this->renderer()->render($spec, $context, $attestation)->ok;
        $this->renderer()->render($spec, $context, $attestation)->ok;

        $this->assertSame(
            1,
            app(WidgetRuntimeControlReader::class)->databaseReadCount(),
            'Two render() calls for the SAME (project_name, manifest_hash) within one request must reach the database only once.'
        );
    }

    public function test_two_different_manifests_are_isolated_blocking_one_does_not_affect_the_sibling(): void
    {
        [$widgetA, $themeA] = $this->widgetAndTheme('marker-a');
        [$widgetB, $themeB] = $this->widgetAndTheme('marker-b');

        $attestationA = $this->attestationFor($widgetA, $themeA);
        $attestationB = $this->attestationFor($widgetB, $themeB);

        $this->trackManifest($attestationA, ['blocked' => true, 'control_version' => 2]);
        $this->trackManifest($attestationB, ['blocked' => false]);

        $context = new WidgetRenderContext(projectName: 'Default');

        $resultA = $this->renderer()->render(
            new WidgetRenderSpec($widgetA->id, $themeA->id, ['title' => 'A']),
            $context,
            $attestationA
        );
        $resultB = $this->renderer()->render(
            new WidgetRenderSpec($widgetB->id, $themeB->id, ['title' => 'B']),
            $context,
            $attestationB
        );

        $this->assertFalse($resultA->ok, 'Manifest A is blocked and must fail soft.');
        $this->assertTrue($resultB->ok, 'Sibling Manifest B is untouched and must render normally -- isolation.');
        $this->assertStringContainsString('B', $resultB->html);

        $this->assertSame(
            2,
            app(WidgetRuntimeControlReader::class)->databaseReadCount(),
            'Two DISTINCT identities must each read once -- proves isolation is not achieved by accidentally sharing one cache entry.'
        );
    }

    // ===================================================================
    // Ordering: the control read only runs for an ELIGIBLE attestation,
    // never wasted on an already-ineligible one.
    // ===================================================================

    public function test_control_read_is_skipped_entirely_for_an_ineligible_attestation(): void
    {
        [$widget, $theme] = $this->widgetAndTheme();

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, []),
            new WidgetRenderContext(projectName: 'Default'),
            null
        );

        $this->assertFalse($result->ok);
        $this->assertSame('WIDGET_INELIGIBLE_NO_ATTESTATION', $result->errorCode);
        $this->assertSame(0, app(WidgetRuntimeControlReader::class)->databaseReadCount(), 'An ineligible attestation must never trigger a wasted emergency-control read.');
    }
}
