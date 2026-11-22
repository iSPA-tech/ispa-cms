<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

/**
 * Outcome of {@see WidgetSiteLayoutEligibility::evaluate()}: either eligible
 * with no reason, or ineligible with exactly one of the frozen reason codes
 * below. Always constructed via the private eligible()/ineligible()
 * factories, never directly.
 *
 * Reason codes double as ready-to-use WidgetRenderResult::errorCode values
 * for ApprovedLegacyWidgetRenderer's typed denial result.
 *
 * PSR-4 FILE SPLIT (Chunk 2 Task 15a DoD rider): originally co-located
 * inside WidgetSiteLayoutEligibility.php (Task 9c's allowed_paths had no
 * separate value-object file slot). That co-location relied on Composer's
 * classmap fallback scan to resolve this class in dev; a production
 * `--optimize-autoloader` build generates a strict PSR-4-only map keyed by
 * file path, which would never find a class living in a differently-named
 * file -- a real dev-vs-prod autoloader divergence. Moved to its own
 * properly-named file so PSR-4 resolves it directly in every environment.
 * No `use` imports elsewhere needed updating: same-namespace callers never
 * required one, and cross-namespace callers already reference the
 * fully-qualified class name, which resolves identically regardless of
 * which file the class lives in.
 *
 * @see \Cms\Widget\Rendering\Eligibility\WidgetSiteLayoutEligibility
 */
final readonly class WidgetEligibilityDecision
{
    public const REASON_NO_ATTESTATION = 'WIDGET_INELIGIBLE_NO_ATTESTATION';
    public const REASON_REVOKED = 'WIDGET_INELIGIBLE_REVOKED';
    public const REASON_POLICY_VERSION_MISMATCH = 'WIDGET_INELIGIBLE_POLICY_VERSION_MISMATCH';
    public const REASON_TRUST_LANE_MISMATCH = 'WIDGET_INELIGIBLE_TRUST_LANE_MISMATCH';
    public const REASON_APPLICABILITY_MISMATCH = 'WIDGET_INELIGIBLE_APPLICABILITY_MISMATCH';
    public const REASON_MANIFEST_HASH_MISMATCH = 'WIDGET_INELIGIBLE_MANIFEST_HASH_MISMATCH';

    private function __construct(
        public bool $eligible,
        public ?string $reasonCode,
    ) {
    }

    public static function eligible(): self
    {
        return new self(true, null);
    }

    public static function ineligible(string $reasonCode): self
    {
        return new self(false, $reasonCode);
    }
}
