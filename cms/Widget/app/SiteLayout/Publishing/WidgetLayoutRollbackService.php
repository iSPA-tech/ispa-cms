<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Publishing;

use Cms\Widget\Audit\WidgetAuditWriter;
use Cms\Widget\Events\WidgetLayoutRolledBack;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryCompatibilityChecker;
use Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder;
use Cms\Widget\Support\Idempotency\WidgetIdempotencyClaimResult;
use Cms\Widget\Support\Idempotency\WidgetIdempotencyService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns `POST /layouts/{uuid}/rollback`, atomic rollback-by-copy (plan Chunk
 * 2 Task 21, lines 1155-1179; architecture.md "Rollback").
 *
 * This is Task 20's sibling and deliberately MIRRORS
 * {@see WidgetLayoutPublishService}'s structure/idioms verbatim wherever the
 * two actions share shape (idempotency claim, Layout lock order, version
 * pair allocation, promotion-eligibility gate, audit-in-transaction,
 * post-commit event dispatch), and diverges only where architecture.md's own
 * "Rollback" section diverges from "Publish":
 *
 * Canonical sequence (architecture.md "Rollback", steps 1-6): claim
 * idempotency -> resolve the SOURCE Release identified by
 * `source_release_version` (a RETAINED, immutable `state=release` row --
 * never a Draft/superseded row) -> recheck expected current Draft
 * version/lock_version -> lock Layout row FOR UPDATE -> re-recheck under the
 * lock -> read MAX(version) and reserve Release=MAX+1/Draft=MAX+2 -> copy
 * the SOURCE Release's Placements into the new immutable Release, carrying a
 * FRESH promotion-eligibility snapshot (re-evaluated NOW against the
 * source's retained `registry_contracts`/Manifests, never the source's own
 * old snapshot) -> supersede the prior Draft -> create the fresh Draft based
 * on the new Release -> switch BOTH pointers -> durable source/destination
 * audit IN the transaction -> markCommitted. The source Release row itself
 * is NEVER written to (architecture.md: "Rollback never reactivates or
 * edits a historical Release") -- only read.
 *
 * Every promotion-eligibility check (registry compatibility, per-Manifest
 * approval/revocation/block state, artifact checksum resolvability) runs
 * TWICE against the SOURCE Release's own retained `registry_contracts` and
 * Placements -- once as a PREFLIGHT before the Layout lock is even acquired,
 * and once again INSIDE the lock immediately before the pointer switch --
 * the identical two-pass shape {@see WidgetLayoutPublishService} already
 * established, reused verbatim here (coordinator brief: "the SAME
 * assessPromotionEligibility()-style gate Task 20 runs").
 *
 * DISCLOSED CONTRACT INTERPRETATIONS:
 * - `$sourceReleaseVersion` (the frozen `source_release_version` request
 *   field) identifies the source by its Layout-scoped VERSION NUMBER, the
 *   same "version number, never a raw row id" convention every other
 *   Layout-Version-identifying request field in this module already uses
 *   (Tasks 17/18/20's own disclosed interpretations).
 * - `based_on_version_id` AND `rollback_of_version_id` are BOTH set to the
 *   source Release's row id on the newly minted Release: mechanically, this
 *   Release's Placements are cloned FROM the source Release (identical
 *   reasoning to Publish setting `based_on_version_id` to the Draft it
 *   clones FROM), and `rollback_of_version_id` is the additional,
 *   rollback-specific lineage tag architecture.md step 5 names explicitly.
 *   architecture.md line 342 describes both columns as sharing "the same
 *   shape" rather than being mutually exclusive, so pointing both at the
 *   one true clone source for THIS action is the most mechanically honest
 *   reading, not an invented business rule.
 * - A `source_release_version` that does not resolve to a `state=release`
 *   row owned by this Layout (never existed, or names a draft/superseded
 *   version) is treated as "resource genuinely absent inside an already-
 *   authorized scope" (architecture.md Status matrix) -- the same minimal
 *   ad-hoc 404 body {@see WidgetLayoutPublishController::notFound()}
 *   already established for "layout not found", since no dedicated code
 *   exists in the frozen 26-code dictionary and none may be added.
 * - The durable audit's `source_release`/`destination_release` pair names
 *   the ROLLBACK source (the historical Release being restored) as
 *   `source_release` and the newly minted Release as `destination_release`
 *   -- DoD line 1171 ("source/destination release audit") read literally
 *   for THIS action, distinct from Publish's own audit where
 *   `source_release` instead names the previously-active published Release
 *   (Publish has no "rollback source" concept at all).
 * - WIDGET_DRAFT_STALE alone covers this action's one staleness axis
 *   (current Draft version/lock_version). WIDGET_USAGE_CHANGED is reserved
 *   for the Instance-usage-fingerprint staleness Task 18's repin route
 * introduced -- rollback has no
 *   Instance/usage-fingerprint concept, so that code is never emitted here.
 *
 * Deliberately does NOT consult
 * {@see \App\Contracts\Modules\ModuleLifecycleBarrier} -- identical
 * reasoning to {@see WidgetLayoutPublishService}'s own docblock: Layout
 * Release is module-owned, not Instance-Version creation / destructive
 * Definition-Theme mutation / Safe Import.
 *
 * Dispatches {@see WidgetLayoutRolledBack} ONLY after the transaction has
 * committed, and ONLY on a genuinely NEW commit (never on an idempotent
 * replay). A listener exception is caught and swallowed so a post-commit
 * side-effect failure can never turn an already-committed success into an
 * error response. This service calls no cache invalidation or telemetry
 * itself -- Task 24's listeners own both.
 */
final class WidgetLayoutRollbackService
{
    private const ACTION = 'layout_rollback';

    private const RESOURCE_TYPE = 'widget_layout_rollback_release';

    public function __construct(
        private readonly WidgetIdempotencyService $idempotency,
        private readonly WidgetAggregateLockOrder $lockOrder,
        private readonly WidgetRegistryCompatibilityChecker $compatibilityChecker,
        private readonly WidgetArtifactBlobRepository $blobs,
        private readonly WidgetAuditWriter $auditWriter,
    ) {
    }

    public function rollback(
        string $projectName,
        string $layoutUuid,
        ?int $actorId,
        string $idempotencyKey,
        int $sourceReleaseVersion,
        int $expectedDraftVersion,
        int $expectedLockVersion,
    ): WidgetLayoutRollbackResult {
        $layout = $this->findLayout($projectName, $layoutUuid);
        if ($layout === null) {
            return WidgetLayoutRollbackResult::layoutNotFound();
        }

        $requestHash = CanonicalJson::hash([
            'layout_uuid' => $layoutUuid,
            'source_release_version' => $sourceReleaseVersion,
            'expected_draft_id' => $expectedDraftVersion,
            'expected_lock_version' => $expectedLockVersion,
        ]);

        $claim = $this->idempotency->claim($projectName, self::ACTION, $layoutUuid, $actorId, $idempotencyKey, $requestHash);
        $replay = $this->resolveClaim($claim, $projectName, $layout);
        if ($replay !== null) {
            return $replay;
        }

        $sourceRelease = $this->findSourceRelease($layout, $sourceReleaseVersion);
        if ($sourceRelease === null) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetLayoutRollbackResult::sourceReleaseNotFound();
        }

        $currentDraft = $this->currentDraft($layout);
        if (!$this->matchesExpectedDraft($currentDraft, $expectedDraftVersion, $expectedLockVersion)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetLayoutRollbackResult::draftStale();
        }

        try {
            $preflight = $this->assessPromotionEligibility($projectName, $sourceRelease);
        } catch (Throwable) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetLayoutRollbackResult::providerUnavailable();
        }

        if ($preflight->failure !== null) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return $preflight->failure;
        }

        $outcome = $this->lockOrder->withLocks([$layout->id], [], function () use (
            $layout,
            $projectName,
            $actorId,
            $sourceRelease,
            $expectedDraftVersion,
            $expectedLockVersion,
            $claim,
            $requestHash,
        ): mixed {
            $lockedLayout = WidgetLayout::query()->findOrFail($layout->id);
            $draft = $this->currentDraft($lockedLayout);

            if (!$this->matchesExpectedDraft($draft, $expectedDraftVersion, $expectedLockVersion)) {
                return WidgetLayoutRollbackResult::draftStale();
            }

            $revalidated = $this->assessPromotionEligibility($projectName, $sourceRelease);
            if ($revalidated->failure !== null) {
                return $revalidated->failure;
            }

            $pair = $this->lockOrder->allocateLayoutVersionPair($lockedLayout->id);

            $release = WidgetLayoutVersion::query()->create([
                'layout_id' => $lockedLayout->id,
                'project_name' => $projectName,
                'version' => $pair['release'],
                'state' => WidgetLayoutVersion::STATE_RELEASE,
                'based_on_version_id' => $sourceRelease->id,
                'rollback_of_version_id' => $sourceRelease->id,
                'lock_version' => 0,
                'registry_contracts' => $sourceRelease->registry_contracts,
                'registry_contract_hash' => $sourceRelease->registry_contract_hash,
                'eligibility_snapshot' => ['manifests' => $revalidated->manifestSnapshot],
                'actor_id' => $actorId,
                'published_at' => now(),
                'published_by' => $actorId,
            ]);
            $this->clonePlacements($sourceRelease, $release);

            $draft->state = WidgetLayoutVersion::STATE_SUPERSEDED;
            $draft->save();

            $freshDraft = WidgetLayoutVersion::query()->create([
                'layout_id' => $lockedLayout->id,
                'project_name' => $projectName,
                'version' => $pair['draft'],
                'state' => WidgetLayoutVersion::STATE_DRAFT,
                'based_on_version_id' => $release->id,
                'lock_version' => 0,
                'registry_contracts' => $release->registry_contracts,
                'registry_contract_hash' => $release->registry_contract_hash,
            ]);
            $this->clonePlacements($release, $freshDraft);

            $lockedLayout->current_published_version_id = $release->id;
            $lockedLayout->current_draft_version_id = $freshDraft->id;
            $lockedLayout->save();

            $this->auditWriter->writeDurable('widget_layout.rollback', [
                'actor_id' => $actorId,
                'project' => $projectName,
                'target' => $lockedLayout,
                'source_release' => $sourceRelease->version,
                'destination_release' => $release->version,
                'result' => 'rolled_back',
                'idempotency_key_hash' => hash('sha256', $requestHash),
            ]);

            if (!$this->idempotency->markCommitted($claim->idempotencyKeyId, $requestHash, 200, self::RESOURCE_TYPE, $layout->uuid, (string) $release->version)) {
                throw new WidgetLayoutRollbackIdempotencyLostException();
            }

            return [$release->fresh(), $freshDraft->fresh()];
        });

        if ($outcome instanceof WidgetLayoutRollbackResult) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return $outcome;
        }

        /** @var array{0: WidgetLayoutVersion, 1: WidgetLayoutVersion} $outcome */
        [$release, $freshDraft] = $outcome;

        $this->dispatchRolledBackEvent($projectName, $layoutUuid, $sourceRelease, $release, $freshDraft, $actorId);

        // 200 (not 201): the frozen contract fixture declares this route's
        // statuses as [200,403,404,409,422,503] -- identical precedent to
        // WidgetLayoutPublishService's own corrected 200 return (commit
        // 943ca6b6d).
        return WidgetLayoutRollbackResult::rolledBack($release, $freshDraft, 200);
    }

    // -----------------------------------------------------------------
    // Claim resolution
    // -----------------------------------------------------------------

    private function resolveClaim(WidgetIdempotencyClaimResult $claim, string $projectName, WidgetLayout $layout): ?WidgetLayoutRollbackResult
    {
        if ($claim->isConflict()) {
            return WidgetLayoutRollbackResult::idempotencyConflict();
        }

        if ($claim->isInProgress()) {
            return WidgetLayoutRollbackResult::idempotencyInProgress();
        }

        if ($claim->isReplay()) {
            $releaseVersion = (int) $claim->resourceVersion;
            $release = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('version', $releaseVersion)->first();
            $freshDraft = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('version', $releaseVersion + 1)->first();

            if ($release === null || $freshDraft === null) {
                return WidgetLayoutRollbackResult::artifactIntegrityFailed();
            }

            return WidgetLayoutRollbackResult::rolledBack($release, $freshDraft, $claim->httpStatus ?? 200);
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Expected-state checks
    // -----------------------------------------------------------------

    private function findLayout(string $projectName, string $uuid): ?WidgetLayout
    {
        return WidgetLayout::query()->where('project_name', $projectName)->where('uuid', $uuid)->first();
    }

    private function findSourceRelease(WidgetLayout $layout, int $version): ?WidgetLayoutVersion
    {
        return WidgetLayoutVersion::query()
            ->where('layout_id', $layout->id)
            ->where('version', $version)
            ->where('state', WidgetLayoutVersion::STATE_RELEASE)
            ->first();
    }

    private function currentDraft(WidgetLayout $layout): ?WidgetLayoutVersion
    {
        return $layout->current_draft_version_id === null
            ? null
            : WidgetLayoutVersion::query()->find($layout->current_draft_version_id);
    }

    private function matchesExpectedDraft(?WidgetLayoutVersion $draft, int $expectedVersion, int $expectedLockVersion): bool
    {
        return $draft !== null && $draft->version === $expectedVersion && $draft->lock_version === $expectedLockVersion;
    }

    // -----------------------------------------------------------------
    // Promotion eligibility: registry compatibility, per-Manifest
    // approval/revocation/block state, and artifact checksum resolvability
    // -- evaluated against the SOURCE Release's own retained
    // registry_contracts/Placements, never the current draft's. Verbatim
    // reuse of WidgetLayoutPublishService::assessPromotionEligibility()'s
    // algorithm, parameterized on any WidgetLayoutVersion (draft OR
    // release) since both shapes expose registry_contracts + an id
    // Placements are keyed by.
    // -----------------------------------------------------------------

    private function assessPromotionEligibility(string $projectName, WidgetLayoutVersion $version): WidgetLayoutRollbackPreflight
    {
        $issues = $this->compatibilityChecker->compare((array) $version->registry_contracts);
        if ($issues !== []) {
            return WidgetLayoutRollbackPreflight::failed(WidgetLayoutRollbackResult::registryDrift($issues));
        }

        $manifestSnapshot = [];

        foreach ($this->distinctManifestIds($version->id) as $manifestId) {
            $manifest = WidgetArtifactManifestModel::query()
                ->where('project_name', $projectName)
                ->where('id', $manifestId)
                ->first();

            if ($manifest === null) {
                return WidgetLayoutRollbackPreflight::failed(WidgetLayoutRollbackResult::artifactIntegrityFailed());
            }

            if ($manifest->eligibility_state === WidgetManifestApprovalService::STATE_REVOKED) {
                return WidgetLayoutRollbackPreflight::failed(WidgetLayoutRollbackResult::manifestRevoked());
            }

            if ($manifest->eligibility_state !== WidgetManifestApprovalService::STATE_APPROVED) {
                return WidgetLayoutRollbackPreflight::failed(WidgetLayoutRollbackResult::manifestApprovalRequired());
            }

            if ((bool) $manifest->blocked) {
                return WidgetLayoutRollbackPreflight::failed(WidgetLayoutRollbackResult::manifestBlocked());
            }

            $blob = WidgetArtifactBlob::query()
                ->where('project_name', $projectName)
                ->where('id', $manifest->artifact_blob_id)
                ->first();

            if ($blob === null) {
                return WidgetLayoutRollbackPreflight::failed(WidgetLayoutRollbackResult::artifactIntegrityFailed());
            }

            $descriptor = $this->blobs->findByProjectAndHash($projectName, $blob->artifact_hash);
            if ($descriptor === null || !hash_equals($blob->artifact_hash, $descriptor->checksum)) {
                return WidgetLayoutRollbackPreflight::failed(WidgetLayoutRollbackResult::artifactIntegrityFailed());
            }

            $manifestSnapshot[] = [
                'manifest_hash' => $manifest->manifest_hash,
                'trust_policy_version' => $manifest->trust_policy_version,
                'trust_lane' => $manifest->trust_lane,
                'attestation_version' => (int) $manifest->attestation_version,
            ];
        }

        usort($manifestSnapshot, static fn (array $a, array $b): int => $a['manifest_hash'] <=> $b['manifest_hash']);

        return WidgetLayoutRollbackPreflight::ok($manifestSnapshot);
    }

    /**
     * @return list<int>
     */
    private function distinctManifestIds(int $layoutVersionId): array
    {
        $placements = WidgetPlacement::query()->where('layout_version_id', $layoutVersionId)->with('instanceVersion')->get();

        $ids = [];
        foreach ($placements as $placement) {
            $manifestId = $placement->instanceVersion?->artifact_manifest_id;
            if ($manifestId !== null) {
                $ids[(int) $manifestId] = true;
            }
        }

        return array_keys($ids);
    }

    // -----------------------------------------------------------------
    // Placement cloning (read-only against the source -- never writes to it)
    // -----------------------------------------------------------------

    private function clonePlacements(WidgetLayoutVersion $source, WidgetLayoutVersion $destination): void
    {
        $placements = WidgetPlacement::query()->where('layout_version_id', $source->id)->get();

        foreach ($placements as $placement) {
            WidgetPlacement::query()->create([
                'project_name' => $destination->project_name,
                'layout_version_id' => $destination->id,
                'widget_instance_version_id' => $placement->widget_instance_version_id,
                'slot_key' => $placement->slot_key,
                'scope_type' => $placement->scope_type,
                'scope_identity' => $placement->scope_identity,
                'scope_key' => $placement->scope_key,
                'target_type' => $placement->target_type,
                'target_id' => $placement->target_id,
                'position' => $placement->position,
                'enabled' => $placement->enabled,
                'rules' => $placement->rules ?? [],
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Post-commit event dispatch
    // -----------------------------------------------------------------

    private function dispatchRolledBackEvent(string $projectName, string $layoutUuid, WidgetLayoutVersion $sourceRelease, WidgetLayoutVersion $release, WidgetLayoutVersion $freshDraft, ?int $actorId): void
    {
        try {
            event(new WidgetLayoutRolledBack(
                projectName: $projectName,
                layoutUuid: $layoutUuid,
                sourceReleaseVersion: $sourceRelease->version,
                releaseVersion: $release->version,
                freshDraftVersion: $freshDraft->version,
                actorId: $actorId,
                correlationId: (string) Str::uuid(),
            ));
        } catch (Throwable) {
            // A post-commit listener failure must never turn the already-
            // committed rollback into an error response -- identical
            // reasoning to WidgetLayoutPublishService::dispatchPublishedEvent().
        }
    }
}

/**
 * Thrown when {@see WidgetIdempotencyService::markCommitted()} reports the
 * claim was lost DURING an otherwise successful rollback -- forces the
 * enclosing transaction to roll back the just-inserted Release/Draft pair
 * rather than committing an orphaned promotion nobody's idempotency result
 * points at. Co-located: this task's file list has no separate slot for it,
 * mirroring
 * {@see \Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishIdempotencyLostException}'s
 * identical co-location precedent.
 */
final class WidgetLayoutRollbackIdempotencyLostException extends \RuntimeException
{
}

/**
 * Outcome of {@see WidgetLayoutRollbackService::assessPromotionEligibility()}.
 * Co-located (see {@see WidgetLayoutRollbackIdempotencyLostException}'s
 * docblock for why).
 */
final readonly class WidgetLayoutRollbackPreflight
{
    /**
     * @param list<array{manifest_hash: string, trust_policy_version: string, trust_lane: string, attestation_version: int}> $manifestSnapshot
     */
    private function __construct(
        public array $manifestSnapshot,
        public ?WidgetLayoutRollbackResult $failure,
    ) {
    }

    /**
     * @param list<array{manifest_hash: string, trust_policy_version: string, trust_lane: string, attestation_version: int}> $manifestSnapshot
     */
    public static function ok(array $manifestSnapshot): self
    {
        return new self($manifestSnapshot, null);
    }

    public static function failed(WidgetLayoutRollbackResult $failure): self
    {
        return new self([], $failure);
    }
}

/**
 * Outcome of every {@see WidgetLayoutRollbackService::rollback()} call.
 * Co-located (see {@see WidgetLayoutRollbackIdempotencyLostException}'s
 * docblock for why), mirroring
 * {@see \Cms\Widget\SiteLayout\Publishing\WidgetLayoutPublishResult}'s
 * identical shape.
 */
final readonly class WidgetLayoutRollbackResult
{
    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_LAYOUT_NOT_FOUND = 'layout_not_found';

    public const STATUS_SOURCE_RELEASE_NOT_FOUND = 'source_release_not_found';

    public const STATUS_DRAFT_STALE = 'draft_stale';

    public const STATUS_REGISTRY_DRIFT = 'registry_drift';

    public const STATUS_MANIFEST_APPROVAL_REQUIRED = 'manifest_approval_required';

    public const STATUS_MANIFEST_REVOKED = 'manifest_revoked';

    public const STATUS_MANIFEST_BLOCKED = 'manifest_blocked';

    public const STATUS_ARTIFACT_INTEGRITY_FAILED = 'artifact_integrity_failed';

    public const STATUS_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const STATUS_IDEMPOTENCY_CONFLICT = 'idempotency_conflict';

    public const STATUS_IDEMPOTENCY_IN_PROGRESS = 'idempotency_in_progress';

    /**
     * @param list<string> $registryIssues
     */
    private function __construct(
        public string $status,
        public ?WidgetLayoutVersion $release = null,
        public ?WidgetLayoutVersion $draft = null,
        public ?int $httpStatus = null,
        public array $registryIssues = [],
    ) {
    }

    public static function rolledBack(WidgetLayoutVersion $release, WidgetLayoutVersion $draft, int $httpStatus = 200): self
    {
        return new self(self::STATUS_ROLLED_BACK, $release, $draft, $httpStatus);
    }

    public static function layoutNotFound(): self
    {
        return new self(self::STATUS_LAYOUT_NOT_FOUND);
    }

    public static function sourceReleaseNotFound(): self
    {
        return new self(self::STATUS_SOURCE_RELEASE_NOT_FOUND);
    }

    public static function draftStale(): self
    {
        return new self(self::STATUS_DRAFT_STALE);
    }

    /**
     * @param list<string> $issues
     */
    public static function registryDrift(array $issues): self
    {
        return new self(self::STATUS_REGISTRY_DRIFT, registryIssues: $issues);
    }

    public static function manifestApprovalRequired(): self
    {
        return new self(self::STATUS_MANIFEST_APPROVAL_REQUIRED);
    }

    public static function manifestRevoked(): self
    {
        return new self(self::STATUS_MANIFEST_REVOKED);
    }

    public static function manifestBlocked(): self
    {
        return new self(self::STATUS_MANIFEST_BLOCKED);
    }

    public static function artifactIntegrityFailed(): self
    {
        return new self(self::STATUS_ARTIFACT_INTEGRITY_FAILED);
    }

    public static function providerUnavailable(): self
    {
        return new self(self::STATUS_PROVIDER_UNAVAILABLE);
    }

    public static function idempotencyConflict(): self
    {
        return new self(self::STATUS_IDEMPOTENCY_CONFLICT);
    }

    public static function idempotencyInProgress(): self
    {
        return new self(self::STATUS_IDEMPOTENCY_IN_PROGRESS);
    }
}
