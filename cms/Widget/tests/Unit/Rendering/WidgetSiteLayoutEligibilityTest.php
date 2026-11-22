<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\Rendering;

use Cms\Core\Console\Commands\AuditLegacyWidgetsCommand;
use Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer;
use Cms\Widget\Rendering\Eligibility\WidgetEligibilityAttestation;
use Cms\Widget\Rendering\Eligibility\WidgetEligibilityDecision;
use Cms\Widget\Rendering\Eligibility\WidgetExecutableSurfaceFingerprint;
use Cms\Widget\Rendering\Eligibility\WidgetSiteLayoutEligibility;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * Chunk 1 Task 9c: freezes the legacy Site Layout eligibility gate.
 *
 * Both collaborators under test are pure and persistence-free (plan "Keep
 * this slice persistence-agnostic"; Task 15a/15b own real materialization
 * and persistence once Task 13a creates storage):
 *
 * - LegacyWidgetExecutableSurfaceAnalyzer fingerprints every executable
 *   surface named by plan line 572 (Widget JavaScript; Theme content/CSS;
 *   Blade/PHP directives/includes/components; request/session/auth/service
 *   access; query/template/provider syntax; scripts; inline handlers;
 *   dangerous URLs; markup/CSS gadget surfaces; canonical schema; artifact
 *   bytes; policy version; trust lane; project/template applicability)
 *   into one deterministic composite manifest_hash. Detection regex for
 *   scripts / inline handlers / dangerous URLs / Blade includes / raw
 *   echoes are reimplemented (not imported) from the read-only
 *   Cms\Core\Console\Commands\AuditLegacyWidgetsCommand, keeping the exact
 *   same patterns per this task's design notes.
 * - WidgetSiteLayoutEligibility is the decision service that requires an
 *   exact, non-revoked WidgetEligibilityAttestation match before granting
 *   eligibility (D-010, D-034, D-039; architecture.md "Legacy eligibility
 *   boundary").
 *
 * @see \Cms\Widget\Tests\Feature\Rendering\ApprovedLegacyWidgetRendererTest
 *      for the composed render-time gate proof.
 */
final class WidgetSiteLayoutEligibilityTest extends TestCase
{
    private function analyzer(): LegacyWidgetExecutableSurfaceAnalyzer
    {
        return new LegacyWidgetExecutableSurfaceAnalyzer();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function fingerprint(array $overrides = []): WidgetExecutableSurfaceFingerprint
    {
        $defaults = [
            'widgetJs' => 'console.log("hello");',
            'themeContent' => '<div class="card">{title}</div>',
            'themeCss' => '.card { color: red; }',
            'canonicalSchema' => ['title' => ['type' => 'text']],
            'policyVersion' => 'policy-v1',
            'trustLane' => 'legacy-approved',
            'applicability' => ['Default'],
            'artifactBytes' => '<div class="card">Hello</div>',
        ];

        // Typo tripwire: a mistyped override key would silently fall back
        // to the baseline value, letting a mutation-matrix test pass
        // vacuously (e.g. an "eligible" assertion that never exercised its
        // intended variation). Fail loudly instead.
        $unknownKeys = array_diff_key($overrides, $defaults);
        self::assertSame(
            [],
            $unknownKeys,
            'Unknown fingerprint() override key(s): ' . implode(', ', array_keys($unknownKeys))
        );

        $args = array_merge($defaults, $overrides);

        return $this->analyzer()->analyze(
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

    private function matchingAttestation(
        WidgetExecutableSurfaceFingerprint $fingerprint,
        bool $revoked = false,
        ?string $policyVersionOverride = null,
        ?string $trustLaneOverride = null,
        ?array $applicabilityOverride = null,
    ): WidgetEligibilityAttestation {
        return new WidgetEligibilityAttestation(
            projectName: 'Default',
            manifestHash: $fingerprint->manifestHash,
            policyVersion: $policyVersionOverride ?? $fingerprint->policyVersion,
            trustLane: $trustLaneOverride ?? $fingerprint->trustLane,
            applicability: $applicabilityOverride ?? $fingerprint->applicability,
            approvedBy: 'approver@example.test',
            approvedAt: new DateTimeImmutable('2026-07-17T00:00:00+00:00'),
            attestationVersion: 1,
            revoked: $revoked,
        );
    }

    // -----------------------------------------------------------------
    // LegacyWidgetExecutableSurfaceAnalyzer: determinism
    // -----------------------------------------------------------------

    public function test_analyze_is_deterministic_for_identical_inputs(): void
    {
        $first = $this->fingerprint();
        $second = $this->fingerprint();

        $this->assertSame($first->manifestHash, $second->manifestHash);
    }

    // -----------------------------------------------------------------
    // No approval inheritance: every named dimension changes the hash
    // -----------------------------------------------------------------

    public function test_changing_any_single_fingerprinted_dimension_produces_a_different_manifest_hash(): void
    {
        $baseline = $this->fingerprint();

        $variants = [
            'widget JavaScript' => ['widgetJs' => 'console.log("changed");'],
            'theme content' => ['themeContent' => '<div class="card">{title} changed</div>'],
            'theme CSS' => ['themeCss' => '.card { color: blue; }'],
            'canonical schema' => ['canonicalSchema' => ['title' => ['type' => 'text'], 'subtitle' => ['type' => 'text']]],
            'artifact bytes' => ['artifactBytes' => '<div class="card">Changed</div>'],
            'policy version' => ['policyVersion' => 'policy-v2'],
            'trust lane' => ['trustLane' => 'legacy-experimental'],
            'project/template applicability' => ['applicability' => ['Default', 'SecondProject']],
        ];

        foreach ($variants as $label => $override) {
            $changed = $this->fingerprint($override);

            $this->assertNotSame(
                $baseline->manifestHash,
                $changed->manifestHash,
                "Changing {$label} alone must produce a different manifest_hash (no approval inheritance)."
            );
        }
    }

    // -----------------------------------------------------------------
    // Pattern parity with the Core read-only inventory command
    // -----------------------------------------------------------------

    public function test_detection_patterns_stay_byte_identical_to_the_core_audit_command_constants(): void
    {
        // The analyzer REIMPLEMENTS (does not import) the detection regex
        // from Cms\Core\Console\Commands\AuditLegacyWidgetsCommand: the Core
        // command stays a read-only inventory with no eligibility semantics,
        // and production module code must not couple to it. This TEST-ONLY
        // reflection assertion is the drift tripwire — if either side's
        // pattern changes, this fails and forces a deliberate re-sync
        // decision. Reading Core from a cms/Widget test is the allowed
        // dependency direction (the module-boundary ADR forbids the
        // reverse: Core/root importing Cms\Widget).
        $command = new ReflectionClass(AuditLegacyWidgetsCommand::class);
        $analyzer = new ReflectionClass(LegacyWidgetExecutableSurfaceAnalyzer::class);

        $parity = [
            // analyzer constant => command constant
            'INLINE_HANDLER_PATTERN' => 'INLINE_HANDLER_PATTERN',
            'SCRIPT_TAG_PATTERN' => 'SCRIPT_TAG_PATTERN',
            'DANGEROUS_URL_PATTERN' => 'DANGEROUS_URL_PATTERN',
            'BLADE_INCLUDE_PATTERN' => 'BLADE_INCLUDE_PATTERN',
            'RAW_ECHO_PATTERN' => 'RAW_ECHO_PATTERN',
            'QUERY_PLACEHOLDER_PATTERN' => 'PROVIDER_PLACEHOLDER_PATTERN',
        ];

        foreach ($parity as $analyzerConstant => $commandConstant) {
            $this->assertSame(
                $command->getConstant($commandConstant),
                $analyzer->getConstant($analyzerConstant),
                "Analyzer {$analyzerConstant} must stay byte-identical to "
                    . "AuditLegacyWidgetsCommand::{$commandConstant}; a deliberate pattern change "
                    . 'must update both sides (or record why they diverge).'
            );
        }
    }

    // -----------------------------------------------------------------
    // Detection surfaces (patterns kept consistent with
    // Cms\Core\Console\Commands\AuditLegacyWidgetsCommand)
    // -----------------------------------------------------------------

    public function test_inline_event_handler_in_theme_content_is_detected_and_changes_the_hash(): void
    {
        $baseline = $this->fingerprint();
        $withHandler = $this->fingerprint(['themeContent' => '<div onclick="doEvil()">{title}</div>']);

        $this->assertSame(0, $baseline->detections['inline_event_handlers']);
        $this->assertSame(1, $withHandler->detections['inline_event_handlers']);
        $this->assertNotSame($baseline->manifestHash, $withHandler->manifestHash);
    }

    public function test_script_tag_in_theme_content_is_detected_and_changes_the_hash(): void
    {
        $baseline = $this->fingerprint();
        $withScript = $this->fingerprint(['themeContent' => '<div>{title}</div><script>alert(1)</script>']);

        $this->assertSame(0, $baseline->detections['script_tags']);
        $this->assertSame(1, $withScript->detections['script_tags']);
        $this->assertNotSame($baseline->manifestHash, $withScript->manifestHash);
    }

    public function test_dangerous_url_scheme_is_detected_and_changes_the_hash(): void
    {
        $baseline = $this->fingerprint();
        $withDangerousUrl = $this->fingerprint(['themeContent' => '<a href="javascript:alert(1)">{title}</a>']);

        $this->assertSame(0, $baseline->detections['dangerous_urls']);
        $this->assertSame(1, $withDangerousUrl->detections['dangerous_urls']);
        $this->assertNotSame($baseline->manifestHash, $withDangerousUrl->manifestHash);
    }

    public function test_dangerous_url_scheme_inside_css_is_also_detected(): void
    {
        // Theme content/JS/CSS all end up inside the same compiled artifact
        // (evidence doc §1.2: CSS is inlined as a leading <style> block), so
        // a CSS-only gadget must still be caught.
        $withDangerousCss = $this->fingerprint(['themeCss' => '.card { background: url(javascript:alert(1)); }']);

        $this->assertSame(1, $withDangerousCss->detections['dangerous_urls']);
    }

    public function test_blade_include_directive_is_detected_and_changes_the_hash(): void
    {
        $baseline = $this->fingerprint();
        $withInclude = $this->fingerprint(['themeContent' => '<div>{title}</div>@include("evil")']);

        $this->assertSame(0, $baseline->detections['blade_includes']);
        $this->assertSame(1, $withInclude->detections['blade_includes']);
        $this->assertNotSame($baseline->manifestHash, $withInclude->manifestHash);
    }

    public function test_raw_echo_is_detected_and_changes_the_hash(): void
    {
        $baseline = $this->fingerprint();
        $withRawEcho = $this->fingerprint(['themeContent' => '<div>{!! $dangerous !!}</div>']);

        $this->assertSame(0, $baseline->detections['raw_echoes']);
        $this->assertSame(1, $withRawEcho->detections['raw_echoes']);
        $this->assertNotSame($baseline->manifestHash, $withRawEcho->manifestHash);
    }

    public function test_request_session_auth_service_access_is_detected_and_changes_the_hash(): void
    {
        $baseline = $this->fingerprint();
        $withAuthAccess = $this->fingerprint(['themeContent' => '<div>{{ auth()->user()->email }}</div>']);

        $this->assertSame(0, $baseline->requestSessionAuthServiceAccessCount);
        $this->assertSame(1, $withAuthAccess->requestSessionAuthServiceAccessCount);
        $this->assertNotSame($baseline->manifestHash, $withAuthAccess->manifestHash);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requestSessionAuthServiceAccessTokenProvider(): array
    {
        return [
            'request()' => ['{{ request()->ip() }}', 'request('],
            'session()' => ['@php session("x"); @endphp', 'session('],
            'auth()' => ['{{ auth()->check() }}', 'auth('],
            'cookie()' => ['{{ cookie("x") }}', 'cookie('],
            'app()' => ['{{ app("translator") }}', 'app('],
            'resolve()' => ['@php resolve("x"); @endphp', 'resolve('],
            'Request facade' => ['{{ Request::ip() }}', 'Request::'],
            'Auth facade' => ['{{ Auth::user() }}', 'Auth::'],
            'server superglobal' => ['{{ $_SERVER["HTTP_HOST"] }}', '$_SERVER'],
        ];
    }

    public function test_every_request_session_auth_service_access_token_is_detected(): void
    {
        foreach (self::requestSessionAuthServiceAccessTokenProvider() as $label => [$snippet, $needle]) {
            $fingerprint = $this->fingerprint(['themeContent' => "<div>{$snippet}</div>"]);

            $this->assertGreaterThan(
                0,
                $fingerprint->requestSessionAuthServiceAccessCount,
                "Token '{$needle}' ({$label}) must be detected as request/session/auth/service access."
            );
        }
    }

    // -----------------------------------------------------------------
    // query/template/provider syntax grammar (evidence doc §1.3)
    // -----------------------------------------------------------------

    public function test_query_provider_placeholder_is_extracted_and_changes_the_hash(): void
    {
        $baseline = $this->fingerprint();
        $withQuery = $this->fingerprint(['themeContent' => '{query:post.latest limit="3"}{/query}']);

        $this->assertSame([], $baseline->providerSyntax['query_keys']);
        $this->assertSame(['post.latest'], $withQuery->providerSyntax['query_keys']);
        $this->assertNotSame($baseline->manifestHash, $withQuery->manifestHash);
    }

    public function test_list_placeholder_is_detected(): void
    {
        $fingerprint = $this->fingerprint(['themeContent' => '{list:menu variable="$m"}{/list}']);

        $this->assertTrue($fingerprint->providerSyntax['has_list']);
    }

    public function test_image_placeholder_is_detected(): void
    {
        $fingerprint = $this->fingerprint(['themeContent' => '{image name="banner" source="local"}']);

        $this->assertTrue($fingerprint->providerSyntax['has_image']);
    }

    public function test_upload_placeholder_is_detected(): void
    {
        $fingerprint = $this->fingerprint(['themeContent' => '{upload name="file"}']);

        $this->assertTrue($fingerprint->providerSyntax['has_upload']);
    }

    public function test_iseo_placeholder_is_detected(): void
    {
        $fingerprint = $this->fingerprint(['themeContent' => '{iSeo:title}']);

        $this->assertTrue($fingerprint->providerSyntax['has_iseo']);
    }

    public function test_plain_input_name_placeholder_is_detected(): void
    {
        $fingerprint = $this->fingerprint(['themeContent' => '<div>{title}</div>']);

        $this->assertTrue($fingerprint->providerSyntax['has_plain_input_placeholder']);
    }

    public function test_a_theme_with_repeat_is_analysed_like_the_rest_of_the_theme(): void
    {
        $baseline = $this->fingerprint(['themeContent' => '<div class="card">{title}</div>']);
        $withRepeat = $this->fingerprint([
            'themeContent' => '<section>{repeat:feedback template=`<div class="card">{image name="feedback.image"}'
                . '<p onclick="x()">{feedback.quote}</p>@php echo 1; @endphp</div>`}</section>',
            'canonicalSchema' => ['feedback' => ['type' => 'repeater', 'options' => ['fields' => [['name' => 'quote', 'type' => 'textarea']]]]],
        ]);

        $this->assertArrayNotHasKey('repeat_names', $baseline->providerSyntax);
        $this->assertSame(['feedback'], $withRepeat->providerSyntax['repeat_names']);
        $this->assertTrue($withRepeat->providerSyntax['has_plain_input_placeholder']);
        $this->assertTrue($withRepeat->providerSyntax['has_image']);
        $this->assertSame(1, $withRepeat->detections['inline_event_handlers']);
        $this->assertSame(1, $withRepeat->detections['blade_includes']);
        $this->assertNotSame($baseline->manifestHash, $withRepeat->manifestHash);
    }

    // -----------------------------------------------------------------
    // Empty Widget JavaScript alone never grants eligibility
    // -----------------------------------------------------------------

    public function test_empty_widget_javascript_still_fingerprints_dangerous_theme_content(): void
    {
        // architecture.md "Legacy eligibility boundary": "Current Theme
        // content reaches Blade::render(), so empty Widget JavaScript does
        // not make a Theme eligible." An empty js field must not collapse
        // the fingerprint to some universal "safe empty" value -- theme
        // content risk signals must still surface independent of js.
        $allTrivial = $this->fingerprint(['widgetJs' => '', 'themeContent' => '', 'themeCss' => '', 'artifactBytes' => '']);
        $emptyJsButDangerousContent = $this->fingerprint([
            'widgetJs' => '',
            'themeContent' => '<div onclick="doEvil()"><script>alert(1)</script></div>',
        ]);

        $this->assertSame(1, $emptyJsButDangerousContent->detections['inline_event_handlers']);
        $this->assertSame(1, $emptyJsButDangerousContent->detections['script_tags']);
        $this->assertNotSame($allTrivial->manifestHash, $emptyJsButDangerousContent->manifestHash);
    }

    public function test_empty_widget_javascript_alone_never_grants_eligibility_without_a_matching_attestation(): void
    {
        $fingerprint = $this->fingerprint(['widgetJs' => '']);

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($fingerprint, null);

        $this->assertFalse($decision->eligible);
        $this->assertSame(WidgetEligibilityDecision::REASON_NO_ATTESTATION, $decision->reasonCode);
    }

    // -----------------------------------------------------------------
    // iframe sandboxing is not server-side Blade isolation: the decision
    // method structurally has no such escape-hatch parameter.
    // -----------------------------------------------------------------

    public function test_eligibility_decision_has_no_iframe_or_client_sandbox_escape_hatch_parameter(): void
    {
        // docs/evidence/widget-site-layout-preflight.md §1.3: stored theme
        // content is compiled and executed as Blade/PHP SERVER-SIDE
        // (Blade::render()). Client-side iframe sandboxing happens (if at
        // all) in the browser, strictly AFTER the server has already
        // executed arbitrary Blade/PHP -- it can never retroactively make
        // that server-side execution safe. Pinning the exact parameter list
        // proves no such bypass parameter exists to smuggle that claim in.
        $method = (new ReflectionClass(WidgetSiteLayoutEligibility::class))->getMethod('evaluate');
        $parameterNames = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters()
        );

        $this->assertSame(['fingerprint', 'attestation'], $parameterNames);
    }

    // -----------------------------------------------------------------
    // D-034 structural freeze: Input Values / provider business arguments
    // cannot enter the fingerprint.
    // -----------------------------------------------------------------

    public function test_analyze_signature_structurally_excludes_input_values_and_provider_business_arguments(): void
    {
        // D-034: ordinary provider business arguments and Input Values
        // remain Instance-Version data. Pinning analyze()'s exact parameter
        // list (symmetric with the evaluate()/render() pins) proves there
        // is no parameter through which per-instance values could enter the
        // manifest fingerprint — adding one would fail this test and force
        // an explicit executable/trust-affecting classification decision.
        $method = (new ReflectionClass(LegacyWidgetExecutableSurfaceAnalyzer::class))->getMethod('analyze');
        $parameterNames = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters()
        );

        $this->assertSame(
            [
                'widgetJs',
                'themeContent',
                'themeCss',
                'canonicalSchema',
                'policyVersion',
                'trustLane',
                'applicability',
                'artifactBytes',
            ],
            $parameterNames
        );
    }

    // -----------------------------------------------------------------
    // WidgetSiteLayoutEligibility denial matrix
    // -----------------------------------------------------------------

    public function test_no_attestation_is_ineligible(): void
    {
        $decision = (new WidgetSiteLayoutEligibility())->evaluate($this->fingerprint(), null);

        $this->assertFalse($decision->eligible);
        $this->assertSame(WidgetEligibilityDecision::REASON_NO_ATTESTATION, $decision->reasonCode);
    }

    public function test_revoked_attestation_is_ineligible_even_with_a_matching_hash(): void
    {
        $fingerprint = $this->fingerprint();
        $attestation = $this->matchingAttestation($fingerprint, revoked: true);

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($fingerprint, $attestation);

        $this->assertFalse($decision->eligible);
        $this->assertSame(WidgetEligibilityDecision::REASON_REVOKED, $decision->reasonCode);
    }

    public function test_policy_version_mismatch_is_ineligible(): void
    {
        $fingerprint = $this->fingerprint();
        $attestation = $this->matchingAttestation($fingerprint, policyVersionOverride: 'policy-v0-stale');

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($fingerprint, $attestation);

        $this->assertFalse($decision->eligible);
        $this->assertSame(WidgetEligibilityDecision::REASON_POLICY_VERSION_MISMATCH, $decision->reasonCode);
    }

    public function test_trust_lane_mismatch_is_ineligible(): void
    {
        $fingerprint = $this->fingerprint();
        $attestation = $this->matchingAttestation($fingerprint, trustLaneOverride: 'legacy-experimental');

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($fingerprint, $attestation);

        $this->assertFalse($decision->eligible);
        $this->assertSame(WidgetEligibilityDecision::REASON_TRUST_LANE_MISMATCH, $decision->reasonCode);
    }

    public function test_applicability_mismatch_is_ineligible(): void
    {
        $fingerprint = $this->fingerprint(['applicability' => ['Default']]);
        $attestation = $this->matchingAttestation($fingerprint, applicabilityOverride: ['OtherProject']);

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($fingerprint, $attestation);

        $this->assertFalse($decision->eligible);
        $this->assertSame(WidgetEligibilityDecision::REASON_APPLICABILITY_MISMATCH, $decision->reasonCode);
    }

    public function test_applicability_comparison_is_order_and_duplicate_insensitive(): void
    {
        $fingerprint = $this->fingerprint(['applicability' => ['Default', 'ProjectA']]);
        $attestation = $this->matchingAttestation($fingerprint, applicabilityOverride: ['ProjectA', 'Default', 'ProjectA']);

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($fingerprint, $attestation);

        $this->assertTrue($decision->eligible);
    }

    public function test_manifest_hash_mismatch_is_ineligible_when_source_drifts_after_approval(): void
    {
        $approvedFingerprint = $this->fingerprint();
        $attestation = $this->matchingAttestation($approvedFingerprint);

        $driftedFingerprint = $this->fingerprint(['themeContent' => '<div>{title} edited after approval</div>']);

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($driftedFingerprint, $attestation);

        $this->assertFalse($decision->eligible);
        $this->assertSame(WidgetEligibilityDecision::REASON_MANIFEST_HASH_MISMATCH, $decision->reasonCode);
    }

    public function test_exact_matching_non_revoked_attestation_is_eligible(): void
    {
        $fingerprint = $this->fingerprint();
        $attestation = $this->matchingAttestation($fingerprint);

        $decision = (new WidgetSiteLayoutEligibility())->evaluate($fingerprint, $attestation);

        $this->assertTrue($decision->eligible);
        $this->assertNull($decision->reasonCode);
        $this->assertTrue((new WidgetSiteLayoutEligibility())->isEligible($fingerprint, $attestation));
    }
}
