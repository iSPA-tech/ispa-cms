<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

use Cms\Widget\Rendering\Artifacts\WidgetArtifactManifest;

/**
 * Outcome of {@see WidgetLegacyManifestMaterializer::materialize()} (plan
 * Chunk 2 Task 15a).
 *
 * Deliberately given its own PSR-4 file from the start (not co-located
 * inside WidgetLegacyManifestMaterializer.php, even though this task's
 * allowed_paths names no separate slot for it): this exact task also fixes
 * a real dev-vs-prod autoloader hazard caused by two OTHER VOs that used to
 * be co-located (see WidgetExecutableSurfaceFingerprint.php,
 * WidgetEligibilityDecision.php), so introducing a brand-new co-located VO
 * here would reintroduce the very anti-pattern this task's own DoD rider
 * removes. A disclosed, narrow, necessary addition.
 *
 * Always constructed via the named static factories below, never directly.
 * `manifest`/`eligibilityState`/`attestationVersion`/`sourceFingerprintHash`/
 * `manifestRow` are populated only for the two persisted outcomes
 * (created/reused); every skip outcome (not-applicable, invalid pairing,
 * stale client expectation) carries no Manifest/attestation data because
 * none was read or written.
 *
 * `manifestRow` is the SINGLE safe-column snapshot the materializer read
 * back once (the same read that produced `eligibilityState`/
 * `attestationVersion`), carried here so the HTTP controller can project
 * the response WITHOUT re-querying the row -- the emergency-control and
 * approval columns (`control_version`, `blocked`, `approved_by`/`at`,
 * `revoked_by`/`at`) are not derivable from the persistence-agnostic
 * `manifest` VO, so a reused-of-previously-approved row's real state is
 * only faithful when read from the row itself. Raw column names (not
 * resource field names) keep this VO HTTP-agnostic. It is null in the
 * rare vanished-row race (the row was persisted by the builder but deleted
 * before the read-back), which the controller renders through the frozen
 * envelope rather than a raw 404.
 */
final readonly class WidgetLegacyManifestMaterializationResult
{
    /** A brand-new pending Manifest row was persisted for this exact source fingerprint. */
    public const STATUS_CREATED = 'created';

    /** An existing pending/approved/revoked Manifest already covers this exact source fingerprint; nothing new was written. */
    public const STATUS_REUSED = 'reused';

    /** The Widget/Theme pairing is canonically valid but does not apply to the requested project; nothing was read or written. */
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /** No such Widget/Theme pairing exists (missing row, or the Theme does not belong to the Widget); nothing was read or written. */
    public const STATUS_INVALID_PAIRING = 'invalid_pairing';

    /** The caller's expected_source_fingerprint no longer matches the freshly recomputed source; nothing was written. */
    public const STATUS_SOURCE_FINGERPRINT_STALE = 'source_fingerprint_stale';

    /**
     * @param array<string, mixed>|null $manifestRow Safe raw-column snapshot
     *        read back once by the materializer; null only in a vanished-row
     *        race (see class docblock).
     */
    private function __construct(
        public string $status,
        public int $widgetId,
        public int $themeId,
        public string $projectName,
        public ?WidgetArtifactManifest $manifest = null,
        public ?string $eligibilityState = null,
        public ?int $attestationVersion = null,
        public ?string $sourceFingerprintHash = null,
        public ?array $manifestRow = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $manifestRow
     */
    public static function created(
        int $widgetId,
        int $themeId,
        string $projectName,
        WidgetArtifactManifest $manifest,
        string $eligibilityState,
        int $attestationVersion,
        string $sourceFingerprintHash,
        ?array $manifestRow = null,
    ): self {
        return new self(self::STATUS_CREATED, $widgetId, $themeId, $projectName, $manifest, $eligibilityState, $attestationVersion, $sourceFingerprintHash, $manifestRow);
    }

    /**
     * @param array<string, mixed>|null $manifestRow
     */
    public static function reused(
        int $widgetId,
        int $themeId,
        string $projectName,
        WidgetArtifactManifest $manifest,
        string $eligibilityState,
        int $attestationVersion,
        string $sourceFingerprintHash,
        ?array $manifestRow = null,
    ): self {
        return new self(self::STATUS_REUSED, $widgetId, $themeId, $projectName, $manifest, $eligibilityState, $attestationVersion, $sourceFingerprintHash, $manifestRow);
    }

    public static function notApplicable(int $widgetId, int $themeId, string $projectName): self
    {
        return new self(self::STATUS_NOT_APPLICABLE, $widgetId, $themeId, $projectName);
    }

    public static function invalidPairing(int $widgetId, int $themeId, string $projectName): self
    {
        return new self(self::STATUS_INVALID_PAIRING, $widgetId, $themeId, $projectName);
    }

    public static function sourceFingerprintStale(int $widgetId, int $themeId, string $projectName, string $currentSourceFingerprintHash): self
    {
        return new self(self::STATUS_SOURCE_FINGERPRINT_STALE, $widgetId, $themeId, $projectName, sourceFingerprintHash: $currentSourceFingerprintHash);
    }

    public function isPersisted(): bool
    {
        return $this->status === self::STATUS_CREATED || $this->status === self::STATUS_REUSED;
    }

    public function wasCreated(): bool
    {
        return $this->status === self::STATUS_CREATED;
    }
}
