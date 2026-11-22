<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Feature\Rendering;

use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Support\Widget\LegacyWidgetRenderer;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetInput;
use Cms\Core\Models\WidgetTheme;
use Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetEligibilityAttestation;
use Cms\Widget\Rendering\LegacyWidgetRenderAdapter;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * Chunk 1 Task 9c: ApprovedLegacyWidgetRenderer requires an exact approved,
 * non-revoked manifest fingerprint before accepting a Site Layout render
 * spec (architecture.md "Legacy eligibility boundary"; D-010/D-034/D-039).
 *
 * Persistence-agnostic per the plan: attestations here are constructed
 * directly as immutable test doubles (Task 15a/15b own real
 * materialization/persistence once Task 13a creates storage). Every
 * attestation is built by independently recomputing a fingerprint from the
 * SAME live Widget/Theme database rows and the renderer's own frozen
 * CURRENT_POLICY_VERSION/CURRENT_TRUST_LANE constants, so a deliberately
 * mismatched override in a test is a genuine mismatch against what the
 * renderer will separately (re)compute at render() time -- never a
 * vacuous self-comparison.
 *
 * LegacyWidgetRenderAdapter (Task 8) is untouched and stays Inline-only;
 * this is a separate, structurally independent class.
 */
final class ApprovedLegacyWidgetRendererTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolatedStoragePath = sys_get_temp_dir() . '/ispa-widget-eligibility-' . uniqid('', true);
        File::ensureDirectoryExists($this->isolatedStoragePath . '/app/generated_widgets/default');
        $this->app->useStoragePath($this->isolatedStoragePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->isolatedStoragePath);

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers
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

    /** Mirrors Cms\Core\Console\Commands\AuditLegacyWidgetsCommand::applicableProjectNames() fan-out semantics. */
    private function applicableProjectNames(mixed $projectName): array
    {
        if (!is_array($projectName)) {
            return !empty($projectName) ? [(string) $projectName] : ['Default'];
        }

        return array_values(array_map('strval', $projectName));
    }

    /**
     * Builds an attestation from a fingerprint independently recomputed
     * against the CURRENT live Widget/Theme row -- exactly mirroring what
     * ApprovedLegacyWidgetRenderer::render() will separately recompute.
     * $overrides only change what the returned ATTESTATION *claims*; the
     * underlying recomputed fingerprint always reflects real DB/renderer
     * state, so a mismatch override here is a genuine, detectable drift.
     *
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

    // -----------------------------------------------------------------
    // Eligible path
    // -----------------------------------------------------------------

    public function test_eligible_attestation_delegates_to_the_real_legacy_render_path(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section data-marker="approved">{title}</section>');

        $attestation = $this->attestationFor($widget, $theme);

        $spec = new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Hello Eligible']);
        $context = new WidgetRenderContext(projectName: 'Default');

        $result = $this->renderer()->render($spec, $context, $attestation);

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('Hello Eligible', $result->html);
        $this->assertStringContainsString('data-marker="approved"', $result->html);

        // Byte-identical to the composed root LegacyWidgetRenderer default,
        // proving this is the same Task 8 legacy path, not a
        // reimplementation (design note: "compose root LegacyWidgetRenderer
        // like the adapter does").
        $expected = app(LegacyWidgetRenderer::class)->render($spec, $context);
        $this->assertSame($expected->html, $result->html);
    }

    public function test_eligible_render_output_contains_no_iframe_sandbox_wrapper(): void
    {
        // Site Layout MVP is client-script-free but this is NOT a
        // server-side sandbox (architecture.md "Legacy eligibility
        // boundary"). Proves the only protection is the attestation gate
        // itself, not a client-side iframe sandboxing substitute.
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>{title}</div>']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section>{title}</section>');

        $attestation = $this->attestationFor($widget, $theme);
        $spec = new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'No Sandbox']);
        $context = new WidgetRenderContext(projectName: 'Default');

        $result = $this->renderer()->render($spec, $context, $attestation);

        $this->assertTrue($result->ok);
        $this->assertStringNotContainsString('<iframe', $result->html);
        $this->assertStringNotContainsString('sandbox=', $result->html);
    }

    public function test_one_attestation_covers_different_input_values_because_values_are_instance_version_data(): void
    {
        // D-034 behavioral proof: ordinary Input Values remain
        // Instance-Version data and are NOT part of the manifest
        // fingerprint -- ONE approved attestation must cover renders with
        // different values, producing genuinely different HTML each time.
        // Only a source/CSS/JS/schema/artifact/policy/applicability change
        // requires a new Manifest.
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>{title}</div>']);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section>{title}</section>');

        $attestation = $this->attestationFor($widget, $theme);
        $context = new WidgetRenderContext(projectName: 'Default');

        $resultA = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Value A']),
            $context,
            $attestation
        );
        $resultB = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Value B']),
            $context,
            $attestation
        );

        $this->assertTrue($resultA->ok, 'The attestation must cover Input Values set A.');
        $this->assertTrue($resultB->ok, 'The SAME attestation must cover Input Values set B -- values are not fingerprinted.');
        $this->assertStringContainsString('Value A', $resultA->html);
        $this->assertStringContainsString('Value B', $resultB->html);
        $this->assertNotSame(
            $resultA->html,
            $resultB->html,
            'Different Input Values must actually flow through to different HTML, or this proof is vacuous.'
        );
    }

    public function test_scalar_project_name_wraps_to_a_single_applicability_entry(): void
    {
        // applicableProjectNames() scalar branch (mirroring
        // AuditLegacyWidgetsCommand): a non-array, non-empty project_name
        // wraps to [value]. The attestation below hardcodes ['Default']
        // WITHOUT deriving it through the test mirror helper, so this test
        // fails if the renderer's scalar fan-out did anything else (the
        // applicability/manifest hashes would mismatch and deny).
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Theme A1',
            'content' => '<div>{title}</div>',
            'project_name' => 'Default', // scalar, not array
        ]);
        WidgetInput::query()->create(['widget_id' => $widget->id, 'name' => 'title', 'type' => 'text']);
        $this->writeGeneratedTemplate($widget->id, $theme->id, '<section>{title}</section>');

        $schema = app(CanonicalWidgetSchemaResolver::class)->resolve($widget->id, $theme->id);
        $fingerprint = (new LegacyWidgetExecutableSurfaceAnalyzer())->analyze(
            widgetJs: '',
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: $schema,
            policyVersion: ApprovedLegacyWidgetRenderer::CURRENT_POLICY_VERSION,
            trustLane: ApprovedLegacyWidgetRenderer::CURRENT_TRUST_LANE,
            applicability: ['Default'],
            artifactBytes: '',
        );
        $attestation = new WidgetEligibilityAttestation(
            projectName: 'Default',
            manifestHash: $fingerprint->manifestHash,
            policyVersion: $fingerprint->policyVersion,
            trustLane: $fingerprint->trustLane,
            applicability: ['Default'],
            approvedBy: 'approver@example.test',
            approvedAt: new DateTimeImmutable('2026-07-17T00:00:00+00:00'),
            attestationVersion: 1,
        );

        $result = $this->renderer()->render(
            new WidgetRenderSpec($widget->id, $theme->id, ['title' => 'Scalar Project']),
            new WidgetRenderContext(projectName: 'Default'),
            $attestation
        );

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('Scalar Project', $result->html);
    }

    // -----------------------------------------------------------------
    // Denial matrix -- never renders, always a typed ineligible result
    // -----------------------------------------------------------------

    public function test_null_attestation_is_denied_without_rendering(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);

        $spec = new WidgetRenderSpec($widget->id, $theme->id, []);
        $context = new WidgetRenderContext(projectName: 'Default');

        $result = $this->renderer()->render($spec, $context, null);

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame('WIDGET_INELIGIBLE_NO_ATTESTATION', $result->errorCode);
    }

    public function test_revoked_attestation_is_denied_without_rendering(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);

        $attestation = $this->attestationFor($widget, $theme, revoked: true);

        $spec = new WidgetRenderSpec($widget->id, $theme->id, []);
        $context = new WidgetRenderContext(projectName: 'Default');

        $result = $this->renderer()->render($spec, $context, $attestation);

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame('WIDGET_INELIGIBLE_REVOKED', $result->errorCode);
    }

    public function test_theme_content_drift_after_approval_is_denied_without_rendering(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>Original</div>']);

        $attestation = $this->attestationFor($widget, $theme);

        // Theme content edited AFTER approval -- no approval inheritance.
        $theme->forceFill(['content' => '<div onclick="doEvil()">Edited</div>'])->save();

        $spec = new WidgetRenderSpec($widget->id, $theme->id, []);
        $context = new WidgetRenderContext(projectName: 'Default');

        $result = $this->renderer()->render($spec, $context, $attestation);

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame('WIDGET_INELIGIBLE_MANIFEST_HASH_MISMATCH', $result->errorCode);
    }

    public function test_policy_version_mismatch_is_denied_without_rendering(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);

        $attestation = $this->attestationFor($widget, $theme, overrides: ['policyVersion' => 'stale-policy-v0']);

        $spec = new WidgetRenderSpec($widget->id, $theme->id, []);
        $context = new WidgetRenderContext(projectName: 'Default');

        $result = $this->renderer()->render($spec, $context, $attestation);

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame('WIDGET_INELIGIBLE_POLICY_VERSION_MISMATCH', $result->errorCode);
    }

    public function test_trust_lane_mismatch_is_denied_without_rendering(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create(['widget_id' => $widget->id, 'name' => 'Theme A1', 'content' => '<div>A1</div>']);

        $attestation = $this->attestationFor($widget, $theme, overrides: ['trustLane' => 'legacy-experimental']);

        $spec = new WidgetRenderSpec($widget->id, $theme->id, []);
        $context = new WidgetRenderContext(projectName: 'Default');

        $result = $this->renderer()->render($spec, $context, $attestation);

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame('WIDGET_INELIGIBLE_TRUST_LANE_MISMATCH', $result->errorCode);
    }

    public function test_applicability_mismatch_is_denied_without_rendering(): void
    {
        $widget = Widget::query()->create(['name' => 'Widget A', 'js' => '']);
        $theme = WidgetTheme::query()->create([
            'widget_id' => $widget->id,
            'name' => 'Theme A1',
            'content' => '<div>A1</div>',
            'project_name' => ['Default'],
        ]);

        $attestation = $this->attestationFor($widget, $theme, overrides: ['applicability' => ['SomeOtherProject']]);

        $spec = new WidgetRenderSpec($widget->id, $theme->id, []);
        $context = new WidgetRenderContext(projectName: 'Default');

        $result = $this->renderer()->render($spec, $context, $attestation);

        $this->assertFalse($result->ok);
        $this->assertSame('', $result->html);
        $this->assertSame('WIDGET_INELIGIBLE_APPLICABILITY_MISMATCH', $result->errorCode);
    }

    public function test_invalid_widget_theme_pairing_still_returns_schema_invalid_before_checking_eligibility(): void
    {
        $widgetA = Widget::query()->create(['name' => 'Widget A']);
        $widgetB = Widget::query()->create(['name' => 'Widget B']);
        $themeB1 = WidgetTheme::query()->create(['widget_id' => $widgetB->id, 'name' => 'Theme B1', 'content' => '<div>B1</div>']);

        $spec = new WidgetRenderSpec($widgetA->id, $themeB1->id, []);
        $context = new WidgetRenderContext(projectName: 'Default');

        $result = $this->renderer()->render($spec, $context, null);

        $this->assertFalse($result->ok);
        $this->assertSame('WIDGET_SCHEMA_INVALID', $result->errorCode);
    }

    // -----------------------------------------------------------------
    // Structural guards
    // -----------------------------------------------------------------

    public function test_render_method_requires_an_explicit_attestation_argument(): void
    {
        // "Keep it structurally impossible to reach without an attestation
        // argument": the parameter must have no default value, so a caller
        // MUST explicitly pass an attestation (or explicit null) -- it
        // cannot be silently omitted.
        $method = (new ReflectionClass(ApprovedLegacyWidgetRenderer::class))->getMethod('render');
        $parameters = $method->getParameters();
        $attestationParameter = $parameters[array_key_last($parameters)];

        $this->assertSame('attestation', $attestationParameter->getName());
        $this->assertFalse($attestationParameter->isOptional());
        $this->assertTrue($attestationParameter->allowsNull());
    }

    public function test_approved_legacy_widget_renderer_is_final_and_unrelated_to_the_inline_only_adapter(): void
    {
        $reflection = new ReflectionClass(ApprovedLegacyWidgetRenderer::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertFalse(
            $reflection->isSubclassOf(LegacyWidgetRenderAdapter::class),
            'ApprovedLegacyWidgetRenderer must stay structurally independent of the Inline-only LegacyWidgetRenderAdapter.'
        );
    }
}
