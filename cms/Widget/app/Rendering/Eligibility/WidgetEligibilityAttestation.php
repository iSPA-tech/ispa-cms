<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

use DateTimeImmutable;

/**
 * Immutable eligibility attestation pinned to one exact project-scoped
 * Manifest identity (plan Chunk 1 Task 9c; architecture.md "Legacy
 * eligibility boundary"; D-034).
 *
 * A TEST-DOUBLE shape for this persistence-agnostic slice: Task 15a
 * materializes/persists pending Manifest candidates and Task 15b persists
 * real approval rows, both only after Task 13a creates storage. Until
 * then, callers construct this VO directly (see
 * WidgetSiteLayoutEligibilityTest / ApprovedLegacyWidgetRendererTest
 * fixture helpers) -- there is no repository or Eloquent model behind it
 * yet, by design (YAGNI: Task 15a/15b introduce those).
 *
 * `manifestHash` pins the EXACT
 * LegacyWidgetExecutableSurfaceAnalyzer::analyze() composite hash this
 * attestation was approved for -- ANY change to any fingerprinted
 * component (source, CSS, JavaScript, provider executable contract,
 * schema, artifact bytes, policy, or applicability) produces a different
 * manifestHash with no inherited approval (D-034).
 *
 * `policyVersion`, `trustLane`, and `applicability` are also recorded
 * directly (not only folded into manifestHash) so
 * WidgetSiteLayoutEligibility can report a specific denial reason when one
 * of them drifts, per this task's design notes.
 */
final readonly class WidgetEligibilityAttestation
{
    /**
     * @param list<string> $applicability
     */
    public function __construct(
        public string $projectName,
        public string $manifestHash,
        public string $policyVersion,
        public string $trustLane,
        public array $applicability,
        public string $approvedBy,
        public DateTimeImmutable $approvedAt,
        public int $attestationVersion,
        public bool $revoked = false,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
    }
}
