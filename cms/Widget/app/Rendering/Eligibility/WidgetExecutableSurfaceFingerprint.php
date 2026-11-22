<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

/**
 * Immutable per-surface fingerprint of one legacy Widget/Theme's
 * executable surface, plus the single canonical composite `manifestHash`
 * (SHA-256 of the canonical JSON of every component below -- the same
 * canonical-JSON discipline as
 * \Cms\Core\Services\Widget\LegacyWidgetArtifactGenerationManifest).
 *
 * Always constructed by {@see LegacyWidgetExecutableSurfaceAnalyzer::analyze()},
 * never directly by callers.
 *
 * `policyVersion`, `trustLane`, and `applicability` are exposed as raw
 * fields (not only hashed) so WidgetSiteLayoutEligibility can compare them
 * individually against an attestation's claimed values for specific
 * denial reasons, in addition to the final composite-hash comparison.
 *
 * PSR-4 FILE SPLIT (Chunk 2 Task 15a DoD rider): originally co-located
 * inside LegacyWidgetExecutableSurfaceAnalyzer.php (Task 9c's allowed_paths
 * had no separate value-object file slot). That co-location relied on
 * Composer's classmap fallback scan to resolve this class in dev; a
 * production `--optimize-autoloader` build generates a strict PSR-4-only
 * map keyed by file path, which would never find a class living in a
 * differently-named file -- a real dev-vs-prod autoloader divergence.
 * Moved to its own properly-named file so PSR-4 resolves it directly in
 * every environment. No `use` imports elsewhere needed updating: same-
 * namespace callers never required one, and cross-namespace callers (e.g.
 * WidgetArtifactBuilder) already reference the fully-qualified class name,
 * which resolves identically regardless of which file the class lives in.
 *
 * @see \Cms\Widget\Rendering\Eligibility\LegacyWidgetExecutableSurfaceAnalyzer
 */
final readonly class WidgetExecutableSurfaceFingerprint
{
    /**
     * @param array{inline_event_handlers: int, script_tags: int, dangerous_urls: int, blade_includes: int, raw_echoes: int} $detections
     * @param array{query_keys: list<string>, has_list: bool, has_image: bool, has_upload: bool, has_iseo: bool, has_plain_input_placeholder: bool, repeat_names?: list<string>} $providerSyntax
     * @param list<string> $applicability
     */
    public function __construct(
        public string $jsHash,
        public string $themeContentHash,
        public string $themeCssHash,
        public array $detections,
        public string $detectionsHash,
        public int $requestSessionAuthServiceAccessCount,
        public string $requestSessionAuthServiceAccessHash,
        public array $providerSyntax,
        public string $providerSyntaxHash,
        public string $canonicalSchemaHash,
        public string $artifactBytesHash,
        public string $policyVersion,
        public string $trustLane,
        public array $applicability,
        public string $applicabilityHash,
        public string $manifestHash,
    ) {
    }
}
