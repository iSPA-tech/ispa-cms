<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering;

use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\DTO\WidgetRenderResult;
use App\Contracts\Widget\DTO\WidgetRenderSpec;
use App\Support\Widget\LegacyWidgetRenderer;
use Cms\Core\Models\Widget;
use Cms\Core\Models\WidgetTheme;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetEligibilityAttestation;
use Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlReader;
use Cms\Widget\Rendering\Eligibility\WidgetSiteLayoutEligibility;

/**
 * Site-Layout-eligible legacy Widget renderer (plan Chunk 1 Task 9c).
 *
 * Renders a legacy Widget/Theme Site Layout render spec ONLY after
 * WidgetSiteLayoutEligibility confirms an exact, non-revoked attestation
 * match against a fingerprint freshly recomputed from the CURRENT
 * Widget/Theme database state -- so any drift since approval (edited
 * source/CSS/JS/schema, or a bumped policy/trust-lane requirement) denies.
 * Denial returns a typed ineligible WidgetRenderResult (ok:false plus a
 * WIDGET_INELIGIBLE_* errorCode) and NEVER reaches rendering.
 *
 * `render()`'s $attestation parameter is required (no default value, so it
 * cannot be silently omitted) though its TYPE is nullable -- callers must
 * explicitly decide there is no attestation rather than being able to skip
 * the argument. This is deliberately structural, not merely conventional:
 * see
 * ApprovedLegacyWidgetRendererTest::test_render_method_requires_an_explicit_attestation_argument().
 * That reflection test structurally LOCKS render()'s signature (exact
 * parameter count/order/name/nullability), which is why Task 15c's
 * emergency-control check below could not be added as a new parameter --
 * see {@see WidgetRuntimeControlReader}'s class docblock "MANIFEST IDENTITY
 * BRIDGING" for the full reasoning.
 *
 * On eligibility, rendering delegates to the SAME composed root
 * App\Support\Widget\LegacyWidgetRenderer the Task 8
 * LegacyWidgetRenderAdapter uses -- this class does not reimplement
 * canonical-schema resolution, sanitization, or the legacy render pipeline
 * (docs/architecture/adr-widget-module-boundary.md decision 4). It is a
 * SEPARATE class from LegacyWidgetRenderAdapter, which stays Inline-only
 * per that class's own frozen docblock; this class is not wired into
 * WidgetRenderService/WidgetServiceProvider routing yet (a later task owns
 * Placement-eligible dispatch).
 *
 * EMERGENCY RUNTIME CONTROL (plan Chunk 2 Task 15c checklist item 2):
 * AFTER eligibility passes -- never before, since there is nothing worth
 * an extra database read for an attestation that was already going to be
 * denied -- this class additionally consults {@see WidgetRuntimeControlReader}
 * for `($context->projectName, $attestation->manifestHash)`. A blocked, or
 * missing/unavailable/unknown, control state fails soft: the SAME typed
 * ineligible-shaped `WidgetRenderResult` (ok:false, empty html) the
 * eligibility denial matrix already returns, so a public
 * `<x-widget-slot>`-style caller falls back to its own supplied fallback
 * content exactly as it already does for every other denial reason. This
 * is a CURRENT-STATE-ONLY override: eligibility itself still trusts
 * whatever attestation the caller passed in (an active Release's own
 * promotion-time snapshot, per architecture.md -- later eligibility
 * revoke/policy bumps elsewhere are ignored here, unchanged from Task 9c);
 * emergency control is the ONLY axis this class re-reads fresh from the
 * database on every eligible render.
 */
final class ApprovedLegacyWidgetRenderer
{
    /**
     * Policy/trust lane currently enforced by this MVP slice. Task 9c has
     * no real policy registry yet; a future task may source these from
     * one. Frozen here (not borrowed from the attestation under test) so
     * that a stale attestation claiming an old policy version or trust
     * lane is actually detectable -- see WidgetSiteLayoutEligibility's
     * POLICY_VERSION_MISMATCH / TRUST_LANE_MISMATCH reasons.
     */
    public const CURRENT_POLICY_VERSION = 'legacy-eligibility-policy-v1';
    public const CURRENT_TRUST_LANE = 'legacy-approved';

    /** Fail-soft render errorCode: emergency control reports blocked=true. */
    public const ERROR_RUNTIME_CONTROL_BLOCKED = 'WIDGET_RUNTIME_CONTROL_BLOCKED';

    /** Fail-soft render errorCode: emergency control state could not be determined. */
    public const ERROR_RUNTIME_CONTROL_UNKNOWN = 'WIDGET_RUNTIME_CONTROL_UNKNOWN';

    public function __construct(
        private readonly LegacyWidgetRenderer $legacyRenderer,
        private readonly LegacyWidgetExecutableSurfaceAnalyzer $analyzer,
        private readonly WidgetSiteLayoutEligibility $eligibility,
        private readonly CanonicalWidgetSchemaResolver $schemaResolver,
        private readonly WidgetRuntimeControlReader $controlReader,
    ) {
    }

    public function render(
        WidgetRenderSpec $spec,
        WidgetRenderContext $context,
        ?WidgetEligibilityAttestation $attestation
    ): WidgetRenderResult {
        if (!$this->schemaResolver->isValidPairing($spec->widgetId, $spec->themeId)) {
            return new WidgetRenderResult(html: '', ok: false, errorCode: 'WIDGET_SCHEMA_INVALID');
        }

        $widget = Widget::query()->find($spec->widgetId);
        $theme = WidgetTheme::query()->find($spec->themeId);

        if ($widget === null || $theme === null) {
            return new WidgetRenderResult(html: '', ok: false, errorCode: 'WIDGET_SCHEMA_INVALID');
        }

        $fingerprint = $this->analyzer->analyze(
            widgetJs: (string) $widget->js,
            themeContent: (string) $theme->content,
            themeCss: (string) $theme->css,
            canonicalSchema: $this->schemaResolver->resolve($spec->widgetId, $spec->themeId),
            policyVersion: self::CURRENT_POLICY_VERSION,
            trustLane: self::CURRENT_TRUST_LANE,
            applicability: $this->applicableProjectNames($theme->project_name),
            // Task 10 owns real generated-artifact bytes (Blob storage);
            // artifact-byte sensitivity is proven directly against the
            // Analyzer in WidgetSiteLayoutEligibilityTest.
            artifactBytes: '',
        );

        $decision = $this->eligibility->evaluate($fingerprint, $attestation);

        if (!$decision->eligible) {
            return new WidgetRenderResult(html: '', ok: false, errorCode: $decision->reasonCode);
        }

        // $attestation is guaranteed non-null here: evaluate() only reaches
        // eligible=true after its own null check above already denied a
        // null attestation. See WidgetRuntimeControlReader's class docblock
        // "MANIFEST IDENTITY BRIDGING" for why $attestation->manifestHash
        // (the Task 9c fingerprint identity) is the correct, and only
        // available, key here. ONE call, so this stays within the "at most
        // once per unique (project_name, manifest_hash) per request"
        // budget on its own, without depending on the reader's memo cache
        // to absorb a second redundant call.
        //
        // Only STATE_BLOCKED (a row confirmed blocked=true) and
        // STATE_UNAVAILABLE (the read itself failed) fail soft here.
        // STATE_UNTRACKED (no row found, read succeeded) proceeds to
        // render -- see WidgetRuntimeControlReader's class docblock
        // "FOUR-STATE MODEL" for the disclosed reasoning: a genuinely
        // eligible, non-null attestation in real usage always correlates
        // to a real tracked row, so this only matters for the read-only,
        // persistence-agnostic Task 9c test suite this class must not
        // regress.
        $controlState = $this->controlReader->readState($context->projectName, $attestation->manifestHash);

        if ($controlState === WidgetRuntimeControlReader::STATE_BLOCKED || $controlState === WidgetRuntimeControlReader::STATE_UNAVAILABLE) {
            return new WidgetRenderResult(
                html: '',
                ok: false,
                errorCode: $controlState === WidgetRuntimeControlReader::STATE_BLOCKED
                    ? self::ERROR_RUNTIME_CONTROL_BLOCKED
                    : self::ERROR_RUNTIME_CONTROL_UNKNOWN,
            );
        }

        return $this->legacyRenderer->render($spec, $context);
    }

    /**
     * Project/template applicability fan-out. Mirrors
     * \Cms\Core\Console\Commands\AuditLegacyWidgetsCommand::applicableProjectNames()
     * exactly (reimplemented locally per module ownership boundary): a
     * non-array, non-empty scalar wraps to one name; a non-array empty
     * value falls back to ['Default']; an array (including empty) is used
     * as-is.
     *
     * @return list<string>
     */
    private function applicableProjectNames(mixed $projectName): array
    {
        if (!is_array($projectName)) {
            return !empty($projectName) ? [(string) $projectName] : ['Default'];
        }

        return array_values(array_map('strval', $projectName));
    }
}
