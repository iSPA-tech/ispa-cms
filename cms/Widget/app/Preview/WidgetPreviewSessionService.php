<?php

declare(strict_types=1);

namespace Cms\Widget\Preview;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use App\Contracts\Widget\DTO\WidgetResolvedTarget;
use App\Contracts\Widget\DTO\WidgetTargetSearchQuery;
use App\Http\Middleware\PrepareAnonymousWidgetPreviewRequest;
use Cms\Widget\Models\WidgetArtifactManifest;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Observability\WidgetRuntimeTelemetry;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\Eligibility\WidgetBlockedException;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\Eligibility\WidgetManifestAutoApprover;
use Cms\Widget\SiteLayout\Resolution\WidgetPlacementResolver;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Owns `POST /layouts/{uuid}/preview-sessions` mint and the module-side of
 * atomic token consume (plan Chunk 2 Task 19; architecture.md "Signed
 * Full-Page Preview" > "Session creation"/"Public preview request";
 * docs/architecture/adr-widget-preview-session.md decisions 1-4, 6-9, 12).
 *
 * MINT sequence (architecture.md "Session creation" steps 1-6):
 * 1. resolve the Layout by (project, uuid) -- absent is 404 before anything
 *    else;
 * 2. authorize/resolve the target BEFORE loading anything else (step 2);
 * 3. validate the overlay EXACTLY like Draft Save, but persist nothing --
 *    {@see self::validateAndResolveOverlay()} deliberately hand-mirrors
 *    {@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService::validateAndResolvePlacements()}'s
 *    orchestration (that method is PRIVATE and its owning class is outside
 *    this task's allowed_paths) while reusing every PUBLIC shared piece
 *    verbatim: {@see WidgetPlacementResolver::scopeIdentity()} (static),
 *    and the Slot/Template/Target Context registries themselves. This is
 *    the SAME small-disclosed-duplication-over-file-boundary-widening
 *    choice Task 18 made for its own `gateManifest()`/`referenceSetFrom()`
 *    (see UpdateInstanceAndRepinDraft's class docblock) -- both classes'
 *    docblocks flag they must be kept in lockstep;
 * 4. gate every distinct referenced Manifest (revoked/pending/blocked),
 *    mirroring {@see \Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishService::assessPromotionEligibility()}'s
 *    own direct-row-inspection precedent against
 *    {@see WidgetManifestApprovalService::STATE_APPROVED}/`STATE_REVOKED`
 *    + `blocked` (Task 18/20's established pattern, reused verbatim here
 *    per the coordinator brief);
 * 5. encrypt the complete {@see WidgetPreviewContext} record and store it
 *    under a 256-bit single-use random token with a short TTL via
 *    {@see AtomicWidgetPreviewStore};
 * 6. sign the canonical destination URL with the token
 *    (`URL::temporarySignedRoute()`, the same working precedent
 *    `DigitalLibraryPublicController::signedAssetUrl()` already
 *    establishes in this codebase) and return it plus expiry/fingerprint/
 *    diagnostics.
 *
 * CONSUME re-validates EVERYTHING the mint bound -- route identity, project,
 * and manifest eligibility AGAIN (DoD 1106: "again after atomic consume")
 * -- after the atomic store has already single-use-deleted the record, so
 * a failed re-check still leaves the token permanently burned (no retry,
 * no replay).
 *
 * Deliberately consults no {@see \App\Contracts\Modules\ModuleLifecycleBarrier}
 * (preview creates no Instance Version and performs no destructive
 * Definition/Theme mutation) and no
 * {@see \Cms\Widget\Support\Idempotency\WidgetIdempotencyService} (frozen
 * fixture: `idempotency_scope: null` for the preview-sessions route).
 * Writes no audit row -- the DoD list for this task names no audit
 * requirement, unlike Tasks 15b/20's explicit ones.
 */
final class WidgetPreviewSessionService
{
    public const DEFAULT_TTL_SECONDS = 300;

    /** Public home route an untargeted preview opens on (root routes/web.php). */
    private const HOME_ROUTE_NAME = 'home';

    private const TOKEN_BYTES = 32; // 256-bit single-use random token (DoD 1103).

    private const GATE_REVOKED = 'revoked';

    private const GATE_APPROVAL_REQUIRED = 'approval_required';

    private const GATE_BLOCKED = 'blocked';

    private readonly WidgetRuntimeTelemetry $telemetry;

    /**
     * DELIBERATELY appends a nullable, self-defaulting telemetry argument
     * AFTER $ttlSeconds (Chunk 2 Task 24, DoD 1279: "preview telemetry into
     * session creation/consume") -- this task's allowed_paths does not
     * include WidgetServiceProvider.php, so the EXISTING 4-positional-
     * argument binding closure keeps working unmodified (both $ttlSeconds
     * and $telemetry fall back to their defaults) while a future rebind
     * still receives a real telemetry instance via reflection.
     */
    public function __construct(
        private readonly WidgetSlotRegistry $slots,
        private readonly WidgetTemplateContextRegistry $templateContexts,
        private readonly WidgetTargetContextRegistry $targetContexts,
        private readonly AtomicWidgetPreviewStore $store,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        ?WidgetRuntimeTelemetry $telemetry = null,
    ) {
        $this->telemetry = $telemetry ?? new WidgetRuntimeTelemetry();
    }

    /**
     * Thin telemetry wrapper around {@see self::mintInternal()} (DoD 1279)
     * -- records the outcome of EVERY mint call regardless of which
     * internal branch produced it, without touching that method's own
     * validation/authorization/encryption logic at all (zero regression
     * risk to the algorithm Task 19 already proved).
     *
     * @param list<array<string, mixed>> $overlay
     */
    public function mint(
        string $projectName,
        string $layoutUuid,
        ?int $actorId,
        array $overlay,
        string $locale,
        string $templateContext,
        string $targetType,
        string $targetId,
        ?string $viewport,
        ?string $editorNonce = null,
    ): WidgetPreviewMintResult {
        $result = $this->mintInternal($projectName, $layoutUuid, $actorId, $overlay, $locale, $templateContext, $targetType, $targetId, $viewport, $editorNonce);

        $this->telemetry->recordPreview($projectName, $layoutUuid, 'mint', $result->status === WidgetPreviewMintResult::STATUS_SUCCESS);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $overlay
     */
    private function mintInternal(
        string $projectName,
        string $layoutUuid,
        ?int $actorId,
        array $overlay,
        string $locale,
        string $templateContext,
        string $targetType,
        string $targetId,
        ?string $viewport,
        ?string $editorNonce = null,
    ): WidgetPreviewMintResult {
        $layout = WidgetLayout::query()
            ->where('project_name', $projectName)
            ->where('uuid', $layoutUuid)
            ->first();

        if ($layout === null) {
            return WidgetPreviewMintResult::layoutNotFound();
        }

        $authContext = new AuthorizedProjectContext($projectName);

        if ($targetType === '') {
            $entry = $this->untargetedEntry($authContext);
            if ($entry === null) {
                return WidgetPreviewMintResult::validationFailed('There is no published page to preview.');
            }
            [$templateContext, $targetType, $targetId, $resolvedTarget] = $entry;
        } else {
            if (!$this->targetContexts->isRegistered($targetType)) {
                return WidgetPreviewMintResult::validationFailed("Unknown Target Context type '{$targetType}'.");
            }

            $targetDefinition = $this->targetContexts->definitionFor($targetType);
            if ($targetDefinition === null || $targetDefinition->templateContextKey !== $templateContext) {
                return WidgetPreviewMintResult::validationFailed(
                    "Target type '{$targetType}' does not belong to Template Context '{$templateContext}'."
                );
            }

            try {
                $resolvedTarget = $this->targetContexts->resolve($targetType, $authContext, $targetId);
            } catch (ModelNotFoundException $exception) {
                return WidgetPreviewMintResult::targetNotFound($exception->getMessage());
            } catch (AuthorizationException $exception) {
                return WidgetPreviewMintResult::targetDenied($exception->getMessage());
            }
        }

        $resolution = $this->validateAndResolveOverlay($authContext, $projectName, $overlay);
        if ($resolution->failure !== null) {
            return $resolution->failure;
        }

        if ($actorId !== null) {
            $this->autoApprovePinnedManifests($projectName, $resolution->manifestIds, $actorId);
        }

        $gate = $this->gateManifests($projectName, $resolution->manifestIds);
        if ($gate !== null) {
            return $this->mintFailureFor($gate);
        }

        $releaseBaseline = $layout->current_published_version_id === null
            ? null
            : WidgetLayoutVersion::query()->find($layout->current_published_version_id)?->version;

        $mintedAt = (new DateTimeImmutable())->format(DATE_ATOM);

        $fingerprint = CanonicalJson::hash([
            'project_name' => $projectName,
            'layout_uuid' => $layoutUuid,
            'locale' => $locale,
            'template_context' => $templateContext,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'canonical_route_name' => $resolvedTarget->routeName,
            'canonical_route_parameters' => $resolvedTarget->routeParameters,
            'release_baseline' => $releaseBaseline,
            'overlay_placements' => $resolution->rows,
        ]);

        $previewContext = new WidgetPreviewContext(
            projectName: $projectName,
            layoutUuid: $layoutUuid,
            locale: $locale,
            templateContext: $templateContext,
            targetType: $targetType,
            targetId: $targetId,
            canonicalRouteName: $resolvedTarget->routeName,
            canonicalRouteParameters: $resolvedTarget->routeParameters,
            releaseBaseline: $releaseBaseline,
            overlayPlacements: $resolution->rows,
            manifestIds: $resolution->manifestIds,
            fingerprint: $fingerprint,
            mintedBy: $actorId,
            mintedAt: $mintedAt,
            viewport: $viewport,
            editorNonce: $editorNonce,
        );

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $ciphertext = Crypt::encryptString(json_encode($previewContext->toArray(), JSON_THROW_ON_ERROR));
        $this->store->put($token, $ciphertext, $this->ttlSeconds);

        $expiresAt = (new DateTimeImmutable())->modify(sprintf('+%d seconds', $this->ttlSeconds));

        $previewUrl = URL::temporarySignedRoute(
            $resolvedTarget->routeName,
            $expiresAt,
            array_merge($resolvedTarget->routeParameters, [
                PrepareAnonymousWidgetPreviewRequest::TOKEN_PARAMETER => $token,
            ]),
        );

        return WidgetPreviewMintResult::success($previewUrl, $expiresAt, $fingerprint, $previewContext);
    }

    /**
     * Where an untargeted preview opens (Unified Widget Catalog Part 2 Task 5; spec: "a preview of the
     * home page, or a neutral sample page when no page target exists").
     *
     * - The public `home` route when it exists: no Template Context and no target, so only global and
     *   area-template rows of the overlay apply (the resolver's normal scope rules).
     * - Otherwise the first page a registered Target Context's search() returns. search() lists readable
     *   rows only, so an unpublished page is never chosen; the preview is then an ordinary targeted one.
     * - Neither: null (the caller answers 422).
     *
     * @return array{0: string, 1: string, 2: string, 3: WidgetResolvedTarget}|null
     */
    private function untargetedEntry(AuthorizedProjectContext $authContext): ?array
    {
        if (Route::has(self::HOME_ROUTE_NAME)) {
            return ['', '', '', new WidgetResolvedTarget(
                targetId: '',
                targetType: '',
                label: '',
                routeName: self::HOME_ROUTE_NAME,
                routeParameters: [],
            )];
        }

        foreach ($this->targetContexts->types() as $type) {
            $definition = $this->targetContexts->definitionFor($type);
            $first = $this->targetContexts->search($authContext, new WidgetTargetSearchQuery($type, '', 1, 1))->items[0] ?? null;
            if ($definition === null || $first === null) {
                continue;
            }

            return [$definition->templateContextKey, $type, $first->targetId, new WidgetResolvedTarget(
                targetId: $first->targetId,
                targetType: $type,
                label: $first->label,
                routeName: $first->routeName,
                routeParameters: $first->routeParameters,
            )];
        }

        return null;
    }

    /**
     * Thin telemetry wrapper around {@see self::consumeInternal()} (DoD
     * 1279) -- identical reasoning to {@see self::mint()}'s own wrapper.
     * Never logs the token itself -- only project/layout/stage/outcome.
     *
     * @param array<string, mixed> $currentRouteParameters
     */
    public function consume(
        string $token,
        string $projectName,
        string $currentRouteName,
        array $currentRouteParameters,
    ): WidgetPreviewConsumeResult {
        $result = $this->consumeInternal($token, $projectName, $currentRouteName, $currentRouteParameters);

        $this->telemetry->recordPreview(
            $projectName,
            $result->context?->layoutUuid ?? $currentRouteName,
            'consume',
            $result->isSuccess(),
        );

        return $result;
    }

    /**
     * Atomically consumes $token (single-use, DoD 1103) and re-validates
     * route identity, project, and manifest eligibility (DoD 1106, 1111).
     *
     * @param array<string, mixed> $currentRouteParameters
     */
    private function consumeInternal(
        string $token,
        string $projectName,
        string $currentRouteName,
        array $currentRouteParameters,
    ): WidgetPreviewConsumeResult {
        $ciphertext = $this->store->consume($token);
        if ($ciphertext === null) {
            return WidgetPreviewConsumeResult::invalidOrExpired();
        }

        try {
            $json = Crypt::decryptString($ciphertext);
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $previewContext = WidgetPreviewContext::fromArray($data);
        } catch (Throwable) {
            // Corrupt/undecryptable stored record. The token is already
            // burned by the atomic consume above regardless -- no replay
            // is possible even on this defensive path.
            return WidgetPreviewConsumeResult::invalidOrExpired();
        }

        if (!hash_equals($previewContext->projectName, $projectName)) {
            return WidgetPreviewConsumeResult::invalidOrExpired();
        }

        if (!hash_equals($previewContext->canonicalRouteName, $currentRouteName)
            || $this->normalizeRouteParameters($previewContext->canonicalRouteParameters)
                !== $this->normalizeRouteParameters($currentRouteParameters)
        ) {
            return WidgetPreviewConsumeResult::invalidOrExpired();
        }

        $gate = $this->gateManifests($previewContext->projectName, $previewContext->manifestIds);
        if ($gate !== null) {
            return $this->consumeFailureFor($gate);
        }

        return WidgetPreviewConsumeResult::success($previewContext);
    }

    // -----------------------------------------------------------------
    // Overlay validation -- disclosed hand-mirror of
    // WidgetLayoutDraftService::validateAndResolvePlacements() (see class
    // docblock).
    // -----------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $overlay
     */
    private function validateAndResolveOverlay(AuthorizedProjectContext $authContext, string $projectName, array $overlay): WidgetPreviewOverlayResolution
    {
        $resolved = [];
        $manifestIds = [];

        foreach ($overlay as $row) {
            if (!is_array($row)) {
                return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::validationFailed('Every overlay row must be an object.'));
            }

            $slotKey = (string) ($row['slot_key'] ?? '');
            $scopeType = (string) ($row['scope_type'] ?? '');
            $scopeKey = $row['scope_key'] ?? null;
            $rowTargetType = $row['target_type'] ?? null;
            $rowTargetId = $row['target_id'] ?? null;

            $declaration = $this->slots->declarationFor($slotKey);
            if ($declaration === null) {
                return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::validationFailed("Unknown Slot key '{$slotKey}'."));
            }

            if (!in_array($scopeType, $declaration->supportedScopeTypes, true)) {
                return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::validationFailed("Slot '{$slotKey}' does not support scope type '{$scopeType}'."));
            }

            if ($scopeType === WidgetPlacementResolver::SCOPE_TEMPLATE) {
                if (!is_string($scopeKey) || !$this->templateContexts->isRegistered($scopeKey)) {
                    return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::validationFailed("Unknown Template Context key '{$scopeKey}'."));
                }
            } else {
                $scopeKey = null;
            }

            if ($scopeType === WidgetPlacementResolver::SCOPE_TARGET) {
                if (!is_string($rowTargetType) || !is_string($rowTargetId)) {
                    return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::validationFailed('A target-scoped overlay row requires target_type and target_id.'));
                }

                if (!$this->targetContexts->isRegistered($rowTargetType)) {
                    return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::validationFailed("Unknown Target Context type '{$rowTargetType}'."));
                }

                try {
                    $this->targetContexts->resolve($rowTargetType, $authContext, $rowTargetId);
                } catch (ModelNotFoundException $exception) {
                    return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::targetNotFound($exception->getMessage()));
                } catch (AuthorizationException $exception) {
                    return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::targetDenied($exception->getMessage()));
                }
            } else {
                $rowTargetType = null;
                $rowTargetId = null;
            }

            $rules = $row['rules'] ?? [];
            if (!is_array($rules) || $rules !== []) {
                return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::validationFailed("Slot '{$slotKey}': rules must be an empty object in MVP."));
            }

            $instanceVersion = $this->resolveInstanceVersion(
                $projectName,
                (string) ($row['widget_instance_uuid'] ?? ''),
                (int) ($row['widget_instance_version'] ?? 0),
            );
            if ($instanceVersion === null) {
                return WidgetPreviewOverlayResolution::failed(WidgetPreviewMintResult::validationFailed(
                    'Unknown or inaccessible widget_instance_uuid/widget_instance_version pairing.'
                ));
            }

            if ($instanceVersion->artifact_manifest_id !== null) {
                $manifestIds[(int) $instanceVersion->artifact_manifest_id] = true;
            }

            $resolved[] = [
                'slot_key' => $slotKey,
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
                'target_type' => $rowTargetType,
                'target_id' => $rowTargetId,
                'scope_identity' => WidgetPlacementResolver::scopeIdentity($scopeType, $scopeKey, $rowTargetType, $rowTargetId),
                'widget_instance_version_id' => $instanceVersion->id,
                'position' => (int) ($row['position'] ?? 0),
                'enabled' => (bool) ($row['enabled'] ?? true),
                'rules' => [],
            ];
        }

        return WidgetPreviewOverlayResolution::ok($resolved, array_keys($manifestIds));
    }

    private function resolveInstanceVersion(string $projectName, string $instanceUuid, int $version): ?WidgetInstanceVersion
    {
        if ($instanceUuid === '' || $version < 1) {
            return null;
        }

        $instance = WidgetInstance::query()
            ->where('project_name', $projectName)
            ->where('uuid', $instanceUuid)
            ->first();

        if ($instance === null) {
            return null;
        }

        return WidgetInstanceVersion::query()
            ->where('instance_id', $instance->id)
            ->where('version', $version)
            ->first();
    }

    // -----------------------------------------------------------------
    // Manifest eligibility gate -- direct row-inspection precedent
    // (WidgetLayoutPublishService::assessPromotionEligibility()).
    // -----------------------------------------------------------------

    /**
     * No approval step any more: approve every still-pending manifest the overlay pins for the
     * previewing user before the gate runs. A blocked widget is skipped (it renders nothing through
     * the Core block check; the rest of the preview still mints); any other failure is left to the
     * gate.
     * The approver is resolved here, not injected, because the provider binds this service with a
     * fixed positional constructor.
     *
     * @param list<int> $manifestIds
     */
    private function autoApprovePinnedManifests(string $projectName, array $manifestIds, int $actorId): void
    {
        if ($manifestIds === []) {
            return;
        }

        $approver = app(WidgetManifestAutoApprover::class);
        $versions = WidgetInstanceVersion::query()
            ->where('project_name', $projectName)
            ->whereIn('artifact_manifest_id', $manifestIds)
            ->get()
            ->unique('artifact_manifest_id');

        foreach ($versions as $version) {
            try {
                $approver->ensurePinnedApproved($version, $actorId);
            } catch (WidgetBlockedException) {
                continue;
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @param list<int> $manifestIds
     */
    private function gateManifests(string $projectName, array $manifestIds): ?string
    {
        foreach ($manifestIds as $manifestId) {
            $manifest = WidgetArtifactManifest::query()
                ->where('project_name', $projectName)
                ->where('id', $manifestId)
                ->first();

            if ($manifest === null || $manifest->eligibility_state === WidgetManifestApprovalService::STATE_REVOKED) {
                return self::GATE_REVOKED;
            }

            if ($manifest->eligibility_state !== WidgetManifestApprovalService::STATE_APPROVED) {
                return self::GATE_APPROVAL_REQUIRED;
            }

            if ((bool) $manifest->blocked) {
                return self::GATE_BLOCKED;
            }
        }

        return null;
    }

    private function mintFailureFor(string $gate): WidgetPreviewMintResult
    {
        return match ($gate) {
            self::GATE_REVOKED => WidgetPreviewMintResult::manifestRevoked(),
            self::GATE_APPROVAL_REQUIRED => WidgetPreviewMintResult::manifestApprovalRequired(),
            default => WidgetPreviewMintResult::manifestBlocked(),
        };
    }

    private function consumeFailureFor(string $gate): WidgetPreviewConsumeResult
    {
        return match ($gate) {
            self::GATE_REVOKED => WidgetPreviewConsumeResult::manifestRevoked(),
            self::GATE_APPROVAL_REQUIRED => WidgetPreviewConsumeResult::manifestApprovalRequired(),
            default => WidgetPreviewConsumeResult::manifestBlocked(),
        };
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, string>
     */
    private function normalizeRouteParameters(array $parameters): array
    {
        $normalized = array_map('strval', $parameters);
        ksort($normalized);

        return $normalized;
    }
}

/**
 * Outcome of {@see WidgetPreviewSessionService::mint()}. Co-located: this
 * task's file list has no separate result-value-object slot, mirroring the
 * module's established co-location precedent (e.g.
 * {@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftSaveResult}).
 */
final readonly class WidgetPreviewMintResult
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_LAYOUT_NOT_FOUND = 'layout_not_found';

    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    public const STATUS_TARGET_DENIED = 'target_denied';

    public const STATUS_TARGET_NOT_FOUND = 'target_not_found';

    public const STATUS_MANIFEST_REVOKED = 'manifest_revoked';

    public const STATUS_MANIFEST_APPROVAL_REQUIRED = 'manifest_approval_required';

    public const STATUS_MANIFEST_BLOCKED = 'manifest_blocked';

    private function __construct(
        public string $status,
        public ?string $previewUrl = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $fingerprint = null,
        public ?WidgetPreviewContext $context = null,
        public ?string $message = null,
    ) {
    }

    public static function success(string $previewUrl, DateTimeImmutable $expiresAt, string $fingerprint, WidgetPreviewContext $context): self
    {
        return new self(self::STATUS_SUCCESS, $previewUrl, $expiresAt, $fingerprint, $context);
    }

    public static function layoutNotFound(): self
    {
        return new self(self::STATUS_LAYOUT_NOT_FOUND);
    }

    public static function validationFailed(string $message): self
    {
        return new self(self::STATUS_VALIDATION_FAILED, message: $message);
    }

    public static function targetDenied(string $message): self
    {
        return new self(self::STATUS_TARGET_DENIED, message: $message);
    }

    public static function targetNotFound(string $message): self
    {
        return new self(self::STATUS_TARGET_NOT_FOUND, message: $message);
    }

    public static function manifestRevoked(): self
    {
        return new self(self::STATUS_MANIFEST_REVOKED);
    }

    public static function manifestApprovalRequired(): self
    {
        return new self(self::STATUS_MANIFEST_APPROVAL_REQUIRED);
    }

    public static function manifestBlocked(): self
    {
        return new self(self::STATUS_MANIFEST_BLOCKED);
    }
}

/**
 * Outcome of {@see WidgetPreviewSessionService::consume()}. Co-located (see
 * {@see WidgetPreviewMintResult}'s docblock for why).
 */
final readonly class WidgetPreviewConsumeResult
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_INVALID_OR_EXPIRED = 'invalid_or_expired';

    public const STATUS_MANIFEST_REVOKED = 'manifest_revoked';

    public const STATUS_MANIFEST_APPROVAL_REQUIRED = 'manifest_approval_required';

    public const STATUS_MANIFEST_BLOCKED = 'manifest_blocked';

    private function __construct(
        public string $status,
        public ?WidgetPreviewContext $context = null,
    ) {
    }

    public static function success(WidgetPreviewContext $context): self
    {
        return new self(self::STATUS_SUCCESS, $context);
    }

    public static function invalidOrExpired(): self
    {
        return new self(self::STATUS_INVALID_OR_EXPIRED);
    }

    public static function manifestRevoked(): self
    {
        return new self(self::STATUS_MANIFEST_REVOKED);
    }

    public static function manifestApprovalRequired(): self
    {
        return new self(self::STATUS_MANIFEST_APPROVAL_REQUIRED);
    }

    public static function manifestBlocked(): self
    {
        return new self(self::STATUS_MANIFEST_BLOCKED);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}

/**
 * Outcome of {@see WidgetPreviewSessionService::validateAndResolveOverlay()}.
 * Co-located (see {@see WidgetPreviewMintResult}'s docblock for why).
 */
final readonly class WidgetPreviewOverlayResolution
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<int> $manifestIds
     */
    private function __construct(
        public array $rows,
        public array $manifestIds,
        public ?WidgetPreviewMintResult $failure,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<int> $manifestIds
     */
    public static function ok(array $rows, array $manifestIds): self
    {
        return new self($rows, $manifestIds, null);
    }

    public static function failed(WidgetPreviewMintResult $failure): self
    {
        return new self([], [], $failure);
    }
}
