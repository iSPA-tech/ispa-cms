<?php

declare(strict_types=1);

namespace Cms\Widget\Http\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Frozen Widget module API error envelope builder (Chunk 1 Task 12;
 * extended by the Chunk 2 Task 15a contract evolution).
 *
 * Pure and static: no HTTP coupling beyond returning a plain array shaped
 * for a controller to call `response()->json($envelope, $envelope['status'])`.
 * Every code/status/retryable triple below is this class's own
 * authoritative copy of the frozen contract; an independent copy also
 * lives in cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * for the human-readable doc and machine validator. Both copies are the
 * whole point of {@see \Cms\Widget\Tests\Unit\Http\WidgetSiteLayoutContractDefinitionTest},
 * which cross-checks them so they never silently drift apart.
 *
 * TASK 15a CONTRACT EVOLUTION (spec-review sanctioned): the original 20
 * domain-conflict codes remain the closed 409/503/500 core (plus the two
 * domain 422s). The fixture's materialize route always declared statuses
 * 403 and 422, but the original enum contained zero 403 codes and no 422
 * fitting request-level pairing/applicability/shape failures -- a hole in
 * the CONTRACT itself, surfaced when the first real controller landed.
 * Three codes now extend the 403/422 tiers:
 * {@see self::WIDGET_PERMISSION_DENIED} (403),
 * {@see self::WIDGET_MANIFEST_SOURCE_INVALID} (422, domain validation:
 * unknown/mismatched pairing, not-applicable project, stale source
 * fingerprint), and
 * {@see self::WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED} (422,
 * request-shape validation). All four contract surfaces (this enum, the
 * fixture, the validator, docs/contracts/widget-site-layout-api.md) moved
 * together in that change -- 23 codes total, still exact-set-checked in
 * both directions.
 *
 * TASK 15b CONTRACT EVOLUTION (same precedent, applied again): the
 * approval/revocation routes (`POST|DELETE .../{manifest_hash}/approval`)
 * always declared statuses 404 and 422, but the 23-code enum had ZERO 404
 * codes at all and no 422 fitting THIS route's own request-shape failures
 * (`WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED` is materialize-specific
 * by name/scope, not a generic Widget-module 422). Two more codes extend
 * the enum to 25: {@see self::WIDGET_MANIFEST_NOT_FOUND} (404 -- the first
 * code at this status; "resource genuinely absent inside authorized scope"
 * per architecture.md's Status matrix, covering both an unknown hash and a
 * hash belonging to a different project, since project isolation means the
 * latter is equally "absent inside THIS authorized scope") and
 * {@see self::WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED} (422, request-
 * shape validation for the approval/revocation payload, mirroring
 * WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED's naming/purpose one route
 * over). The 409 case (concurrent stale approve/revoke, or an attempt to
 * transition from a state the target action does not accept -- e.g.
 * revoking a still-`pending` manifest) reuses the EXISTING
 * {@see self::WIDGET_ATTESTATION_VERSION_STALE}: every eligibility
 * transition this service performs bumps `attestation_version` in lockstep
 * with `eligibility_state`, so a stale/wrong-state attempt is always also a
 * stale-version attempt under the hood -- no new 409 code was needed. All
 * four contract surfaces (this enum, the fixture, the validator,
 * docs/contracts/widget-site-layout-api.md) move together in this same
 * commit -- 25 codes total, still exact-set-checked in both directions.
 *
 * TASK 15c CONTRACT EVOLUTION (same precedent, third instance): the
 * emergency runtime-block routes (`POST|DELETE .../{manifest_hash}/runtime-block`)
 * were frozen at Task 12 WITHOUT 422 in their `statuses` at all (unlike
 * materialize/approval, which each declared 422 from Task 12 and only
 * lacked a matching code). This task's `reason` field is genuinely
 * `required` (not `nullable` like approval's -- see
 * `ApproveWidgetManifestRequest`'s own docblock disclosing that
 * architecture.md's "mandatory reason" language is scoped to THIS
 * endpoint), so a request-shape validation failure is unavoidable and, per
 * "every response uses this shape," must render through this envelope
 * like every other Widget controller rather than Laravel's raw default
 * 422. One code extends the enum to 26:
 * {@see self::WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED} (422,
 * request-shape validation for the block/clear payload, mirroring
 * WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED /
 * WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED's naming/purpose one route
 * over). The 409 case reuses the EXISTING
 * {@see self::WIDGET_RUNTIME_CONTROL_VERSION_STALE} (already part of the
 * original 20 -- this axis's CAS-staleness code was anticipated before
 * this task even existed); the 503 case reuses the EXISTING
 * {@see self::WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE} (also part of
 * the original 20). {@see self::WIDGET_MANIFEST_RUNTIME_BLOCKED} (409,
 * also part of the original 20) is DELIBERATELY left unused by this
 * task's own controller -- it is reserved for a future task (16+)
 * rejecting some OTHER mutation (e.g. Instance creation) against an
 * already-blocked Manifest, not for this endpoint's own block/clear
 * transition, which has no illegal FROM-state to report (see
 * {@see \Cms\Widget\Rendering\Eligibility\WidgetManifestRuntimeBlockService}'s
 * class docblock for the full disclosed reasoning). All four contract
 * surfaces move together in this same commit -- 26 codes total, still
 * exact-set-checked in both directions.
 *
 * Every status value is drawn from the closed status enum frozen by
 * architecture.md "Frozen API, Error, and Status Contract" > "Status
 * matrix" and addendum.md §5.5: 200/201, 403, 404, 409, 422, 500, 503.
 * There is no 202 anywhere in this contract (D-008: Site Layout MVP
 * defines no acknowledged partially completed operation).
 *
 * @see docs/architecture/adr-widget-mutation-idempotency.md decision 15 (status partition)
 * @see docs/architecture/adr-widget-project-authorization.md decisions 6, 8 (403/404, emergency separation)
 */
final class WidgetApiErrorResponse
{
    // Definition/Theme retained-version and module lifecycle conflicts
    // (architecture.md "Foreign keys and deletion" required conflict codes; D-025/D-044).
    public const WIDGET_DEFINITION_IN_USE = 'WIDGET_DEFINITION_IN_USE';
    public const WIDGET_TEMPLATE_IN_USE = 'WIDGET_TEMPLATE_IN_USE';
    public const WIDGET_INSTANCE_IN_USE = 'WIDGET_INSTANCE_IN_USE';
    public const WIDGET_MODULE_IN_USE = 'WIDGET_MODULE_IN_USE';

    // Draft/usage/idempotency conflicts (D-026, D-027, D-038).
    public const WIDGET_USAGE_CHANGED = 'WIDGET_USAGE_CHANGED';
    public const WIDGET_IDEMPOTENCY_IN_PROGRESS = 'WIDGET_IDEMPOTENCY_IN_PROGRESS';
    public const WIDGET_IDEMPOTENCY_MISMATCH = 'WIDGET_IDEMPOTENCY_MISMATCH';
    public const WIDGET_DRAFT_STALE = 'WIDGET_DRAFT_STALE';

    // Provider outage vs invalid input — distinct by design (architecture.md line ~744).
    public const WIDGET_PROVIDER_UNAVAILABLE = 'WIDGET_PROVIDER_UNAVAILABLE';
    public const WIDGET_PROVIDER_INVALID = 'WIDGET_PROVIDER_INVALID';

    // Manifest eligibility lifecycle (D-034, D-036, D-042).
    public const WIDGET_MANIFEST_MATERIALIZATION_FAILED = 'WIDGET_MANIFEST_MATERIALIZATION_FAILED';
    public const WIDGET_MANIFEST_APPROVAL_REQUIRED = 'WIDGET_MANIFEST_APPROVAL_REQUIRED';
    public const WIDGET_MANIFEST_REVOKED = 'WIDGET_MANIFEST_REVOKED';
    public const WIDGET_ATTESTATION_VERSION_STALE = 'WIDGET_ATTESTATION_VERSION_STALE';

    // Emergency runtime control — separate authority/topology from eligibility (D-039, D-045).
    public const WIDGET_RUNTIME_CONTROL_VERSION_STALE = 'WIDGET_RUNTIME_CONTROL_VERSION_STALE';
    public const WIDGET_MANIFEST_RUNTIME_BLOCKED = 'WIDGET_MANIFEST_RUNTIME_BLOCKED';
    public const WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE = 'WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE';

    // Deployment/integrity/preview.
    public const WIDGET_REGISTRY_COMPATIBILITY_DRIFT = 'WIDGET_REGISTRY_COMPATIBILITY_DRIFT';
    public const WIDGET_ARTIFACT_INTEGRITY_FAILED = 'WIDGET_ARTIFACT_INTEGRITY_FAILED';
    public const WIDGET_PREVIEW_SESSION_EXPIRED = 'WIDGET_PREVIEW_SESSION_EXPIRED';

    // Task 15a contract evolution: 403/422 request-level tiers (see class
    // docblock). Emitted first by the materialize endpoint; reusable by
    // every later Widget module controller for the same failure classes.
    public const WIDGET_PERMISSION_DENIED = 'WIDGET_PERMISSION_DENIED';
    public const WIDGET_MANIFEST_SOURCE_INVALID = 'WIDGET_MANIFEST_SOURCE_INVALID';
    public const WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED = 'WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED';

    // Task 15b contract evolution: the first 404 code in this enum, plus the
    // approval/revocation route's own request-shape 422 tier (see class
    // docblock "TASK 15b CONTRACT EVOLUTION").
    public const WIDGET_MANIFEST_NOT_FOUND = 'WIDGET_MANIFEST_NOT_FOUND';
    public const WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED = 'WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED';

    // Task 15c contract evolution: the emergency runtime-block route's own
    // request-shape 422 tier, needed because its `reason` field is
    // genuinely required (see class docblock "TASK 15c CONTRACT EVOLUTION").
    public const WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED = 'WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED';

    // Unified Widget Catalog Part 2 contract evolution: approval is automatic, and the Core
    // emergency block (`widgets.blocked_at`) is the only stop. Placing a blocked widget
    // (instance create/clone/update, draft repin) is refused with this code.
    public const WIDGET_BLOCKED = 'WIDGET_BLOCKED';

    /**
     * Authoritative code -> {status, retryable} map. Keep in lockstep with
     * the "errors" object in widget-site-layout-api-contract.json.
     *
     * @var array<string, array{status: int, retryable: bool}>
     */
    private const CODES = [
        self::WIDGET_DEFINITION_IN_USE => ['status' => 409, 'retryable' => false],
        self::WIDGET_TEMPLATE_IN_USE => ['status' => 409, 'retryable' => false],
        self::WIDGET_INSTANCE_IN_USE => ['status' => 409, 'retryable' => false],
        self::WIDGET_MODULE_IN_USE => ['status' => 409, 'retryable' => false],
        self::WIDGET_USAGE_CHANGED => ['status' => 409, 'retryable' => true],
        self::WIDGET_IDEMPOTENCY_IN_PROGRESS => ['status' => 409, 'retryable' => true],
        self::WIDGET_IDEMPOTENCY_MISMATCH => ['status' => 409, 'retryable' => false],
        self::WIDGET_DRAFT_STALE => ['status' => 409, 'retryable' => true],
        self::WIDGET_PROVIDER_UNAVAILABLE => ['status' => 503, 'retryable' => true],
        self::WIDGET_PROVIDER_INVALID => ['status' => 422, 'retryable' => false],
        self::WIDGET_MANIFEST_MATERIALIZATION_FAILED => ['status' => 503, 'retryable' => true],
        self::WIDGET_MANIFEST_APPROVAL_REQUIRED => ['status' => 422, 'retryable' => false],
        self::WIDGET_MANIFEST_REVOKED => ['status' => 409, 'retryable' => false],
        self::WIDGET_ATTESTATION_VERSION_STALE => ['status' => 409, 'retryable' => true],
        self::WIDGET_RUNTIME_CONTROL_VERSION_STALE => ['status' => 409, 'retryable' => true],
        self::WIDGET_MANIFEST_RUNTIME_BLOCKED => ['status' => 409, 'retryable' => false],
        self::WIDGET_RUNTIME_CONTROL_TOPOLOGY_UNAVAILABLE => ['status' => 503, 'retryable' => false],
        self::WIDGET_REGISTRY_COMPATIBILITY_DRIFT => ['status' => 409, 'retryable' => false],
        self::WIDGET_ARTIFACT_INTEGRITY_FAILED => ['status' => 500, 'retryable' => false],
        self::WIDGET_PREVIEW_SESSION_EXPIRED => ['status' => 409, 'retryable' => false],
        self::WIDGET_PERMISSION_DENIED => ['status' => 403, 'retryable' => false],
        self::WIDGET_MANIFEST_SOURCE_INVALID => ['status' => 422, 'retryable' => false],
        self::WIDGET_MANIFEST_MATERIALIZE_VALIDATION_FAILED => ['status' => 422, 'retryable' => false],
        self::WIDGET_MANIFEST_NOT_FOUND => ['status' => 404, 'retryable' => false],
        self::WIDGET_MANIFEST_APPROVAL_VALIDATION_FAILED => ['status' => 422, 'retryable' => false],
        self::WIDGET_MANIFEST_RUNTIME_BLOCK_VALIDATION_FAILED => ['status' => 422, 'retryable' => false],
        self::WIDGET_BLOCKED => ['status' => 409, 'retryable' => false],
    ];

    /**
     * Static-only helper; never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Build the frozen error envelope for one known code: {status, code,
     * message, correlation_id, details?, recovery?}. `details` and
     * `recovery` are omitted entirely (not sent as null) when not
     * applicable, keeping the envelope minimal per the frozen contract.
     *
     * Never pass raw exception/SQL text as $message for integrity (500) or
     * unexpected-error cases — architecture.md requires only an opaque
     * correlation ID plus a safe error code there.
     *
     * @param array<string, mixed>|null $details Field-path validation details, when relevant (422 cases).
     * @param array<string, mixed>|null $recovery Recovery metadata (e.g. approval inventory link, retry-after seconds).
     *
     * @return array{status: int, code: string, message: string, correlation_id: string, details?: array<string, mixed>, recovery?: array<string, mixed>}
     */
    public static function envelope(
        string $code,
        string $message,
        string $correlationId,
        ?array $details = null,
        ?array $recovery = null,
    ): array {
        $envelope = [
            'status' => self::statusFor($code),
            'code' => $code,
            'message' => $message,
            'correlation_id' => $correlationId,
        ];

        if ($details !== null) {
            $envelope['details'] = $details;
        }

        if ($recovery !== null) {
            $envelope['recovery'] = $recovery;
        }

        return $envelope;
    }

    /**
     * Convenience wrapper: build the frozen envelope for one known code and
     * return it as a ready-to-emit {@see JsonResponse}, with the HTTP status
     * always taken from the code's own frozen mapping. This is the ONE place
     * every Widget module controller (and the approval FormRequest base) turns
     * an error code into a response -- collapsing the previously copy-pasted
     * per-controller `errorResponse()` helpers onto a single builder.
     *
     * The correlation id is minted here when the caller supplies none; a
     * caller that already recorded a specific id server-side (the materialize
     * 503 path mints the id FIRST, logs it, THEN responds) passes that SAME id
     * so the logs and the response body agree (the 15a correlation-id-first
     * behavior is preserved by that explicit pass-through, not by this method
     * minting a second, divergent id).
     *
     * `envelope()` itself stays pure (a plain array) for callers that must
     * embed it elsewhere -- e.g. a FormRequest throwing an
     * `HttpResponseException` wraps THIS method's JsonResponse.
     *
     * @param array<string, mixed>|null $details Field-path validation details, when relevant (422 cases).
     */
    public static function jsonResponse(string $code, string $message, ?array $details = null, ?string $correlationId = null): JsonResponse
    {
        $envelope = self::envelope($code, $message, $correlationId ?? (string) Str::uuid(), $details);

        return response()->json($envelope, $envelope['status']);
    }

    /**
     * The frozen HTTP status for one known code.
     */
    public static function statusFor(string $code): int
    {
        return self::definition($code)['status'];
    }

    /**
     * Whether a client may safely retry this error without a payload change.
     */
    public static function isRetryable(string $code): bool
    {
        return self::definition($code)['retryable'];
    }

    /**
     * @return list<string> Every frozen code, for contract cross-checking.
     */
    public static function knownCodes(): array
    {
        return array_keys(self::CODES);
    }

    /**
     * @return array{status: int, retryable: bool}
     */
    private static function definition(string $code): array
    {
        return self::CODES[$code]
            ?? throw new InvalidArgumentException("Unknown Widget API error code: {$code}");
    }
}
