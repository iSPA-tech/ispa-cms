<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

/**
 * Decision service gating legacy Site Layout eligibility (plan Chunk 1
 * Task 9c; architecture.md "Legacy eligibility boundary"; D-010, D-034,
 * D-039).
 *
 * Requires an EXACT approved, non-revoked WidgetEligibilityAttestation
 * match against a freshly computed WidgetExecutableSurfaceFingerprint.
 * There is no partial/inherited approval: revocation, any pinned-field
 * drift (policy version, trust lane, applicability), or any composite
 * manifest_hash drift (source/CSS/JavaScript/provider-contract/schema/
 * artifact change) all deny.
 *
 * `evaluate()` takes exactly two parameters -- the fingerprint and an
 * optional attestation -- with no client-sandbox/iframe escape hatch:
 * Site Layout MVP is client-script-free but this is NOT a server-side
 * sandbox (theme content reaches Blade::render() verbatim), so no
 * parameter here can ever substitute for an exact attestation match.
 */
final class WidgetSiteLayoutEligibility
{
    /**
     * Field-specific checks run before the final composite-hash fallback
     * so callers get the most actionable denial reason. This is a
     * diagnostics upgrade only, not a security weakening: policyVersion,
     * trustLane, and applicability are themselves inputs to manifestHash,
     * so the final hash_equals() check still independently catches every
     * other fingerprinted dimension (source, CSS, JavaScript, provider
     * syntax, schema, artifact bytes) that has no dedicated named reason.
     */
    public function evaluate(
        WidgetExecutableSurfaceFingerprint $fingerprint,
        ?WidgetEligibilityAttestation $attestation
    ): WidgetEligibilityDecision {
        if ($attestation === null) {
            return WidgetEligibilityDecision::ineligible(WidgetEligibilityDecision::REASON_NO_ATTESTATION);
        }

        if ($attestation->revoked) {
            return WidgetEligibilityDecision::ineligible(WidgetEligibilityDecision::REASON_REVOKED);
        }

        if ($attestation->policyVersion !== $fingerprint->policyVersion) {
            return WidgetEligibilityDecision::ineligible(WidgetEligibilityDecision::REASON_POLICY_VERSION_MISMATCH);
        }

        if ($attestation->trustLane !== $fingerprint->trustLane) {
            return WidgetEligibilityDecision::ineligible(WidgetEligibilityDecision::REASON_TRUST_LANE_MISMATCH);
        }

        if ($this->normalizedApplicability($attestation->applicability) !== $this->normalizedApplicability($fingerprint->applicability)) {
            return WidgetEligibilityDecision::ineligible(WidgetEligibilityDecision::REASON_APPLICABILITY_MISMATCH);
        }

        if (!hash_equals($attestation->manifestHash, $fingerprint->manifestHash)) {
            return WidgetEligibilityDecision::ineligible(WidgetEligibilityDecision::REASON_MANIFEST_HASH_MISMATCH);
        }

        return WidgetEligibilityDecision::eligible();
    }

    /**
     * Convenience boolean form of evaluate() for callers that only need
     * the yes/no outcome.
     */
    public function isEligible(WidgetExecutableSurfaceFingerprint $fingerprint, ?WidgetEligibilityAttestation $attestation): bool
    {
        return $this->evaluate($fingerprint, $attestation)->eligible;
    }

    /**
     * @param list<string> $applicability
     * @return list<string>
     */
    private function normalizedApplicability(array $applicability): array
    {
        $unique = array_values(array_unique(array_map('strval', $applicability)));
        sort($unique);

        return $unique;
    }
}
