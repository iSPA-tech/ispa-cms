<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Publishing;

use Cms\Widget\Audit\WidgetAuditWriter;
use Cms\Widget\Events\WidgetLayoutPublished;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Rendering\Artifacts\WidgetArtifactBlobRepository;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\Eligibility\WidgetBlockedException;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\Eligibility\WidgetManifestAutoApprover;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryCompatibilityChecker;
use Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder;
use Cms\Widget\Support\Idempotency\WidgetIdempotencyClaimResult;
use Cms\Widget\Support\Idempotency\WidgetIdempotencyService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns `POST /layouts/{uuid}/publish`, atomic Release promotion (plan Chunk
 * 2 Task 20, lines 1126-1153; architecture.md "Publish").
 *
 * Canonical sequence (architecture.md "Publish", steps 1-10; coordinator
 * brief's own "publish transaction" paragraph, reproduced verbatim as this
 * class's own contract): claim idempotency -> lock Layout row FOR UPDATE ->
 * recheck expected Draft pointer + lock_version -> read MAX(version) ->
 * reserve Release = MAX+1 and fresh Draft = MAX+2 -> clone the immutable
 * Release carrying the exact Manifest approval/policy/attestation snapshot
 * -> supersede the prior Draft -> create the fresh Draft based on the
 * Release -> switch BOTH pointers -> durable audit IN the transaction ->
 * markCommitted. Every promotion-eligibility check (registry compatibility,
 * per-Manifest approval/revocation/block state, artifact checksum
 * resolvability) runs twice: once as a PREFLIGHT before the Layout lock is
 * even acquired (cheap early rejection), and once again INSIDE the lock
 * immediately before the pointer switch (closes the race window between
 * preflight and commit -- DoD line 1140: "Prove artifact checksum before
 * pointer switch").
 *
 * DISCLOSED CONTRACT INTERPRETATION: `$expectedDraftVersion` (the frozen
 * `expected_draft_id` request field) is compared against the Draft's
 * VERSION NUMBER, not a raw internal row id -- identical reasoning to
 * {@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService::save()} and
 * {@see \Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraft}'s own
 * disclosed interpretations of the same shape of field.
 *
 * IDEMPOTENCY ENCODING: only ONE `resource_id`/`resource_version` pair
 * exists per {@see WidgetIdempotencyService} claim row, but this action
 * commits TWO safe identities (Release + fresh Draft). Rather than inventing
 * a composite string encoding, this class relies on the allocator's own
 * invariant -- {@see WidgetAggregateLockOrder::allocateLayoutVersionPair()}
 * ALWAYS reserves the fresh Draft as `release + 1` -- so only the Release
 * version is persisted as `resource_version`; a replay derives the fresh
 * Draft version as `releaseVersion + 1` and re-reads both rows by
 * `(layout_id, version)`. `resource_id` is the Layout uuid (this action's
 * one aggregate).
 *
 * Deliberately does NOT consult
 * {@see \App\Contracts\Modules\ModuleLifecycleBarrier} (coordinator brief,
 * Task 20: "its scope is Instance-Version creation / destructive
 * Definition-Theme mutation / Safe Import, NOT Layout publish -- Releases
 * are module-owned"). No maintenance-window branch exists in this class.
 *
 * Dispatches {@see WidgetLayoutPublished} ONLY after the transaction has
 * committed, and ONLY on a genuinely NEW commit (never on an idempotent
 * replay -- the original commit already announced it once). A listener
 * exception is caught and swallowed so a post-commit side-effect failure can
 * never turn an already-committed success into an error response
 * (architecture.md: "Failure of non-durable after-commit work cannot turn
 * the committed response into 503"). This service calls no cache
 * invalidation or telemetry itself -- Task 24's listeners own both.
 */
final class WidgetLayoutPublishService
{
    private const ACTION = 'layout_publish';

    private const RESOURCE_TYPE = 'widget_layout_release';

    public function __construct(
        private readonly WidgetIdempotencyService $idempotency,
        private readonly WidgetAggregateLockOrder $lockOrder,
        private readonly WidgetRegistryCompatibilityChecker $compatibilityChecker,
        private readonly WidgetArtifactBlobRepository $blobs,
        private readonly WidgetAuditWriter $auditWriter,
        private readonly WidgetManifestAutoApprover $autoApprover,
        private readonly WidgetPlacementInstanceArchiver $instanceArchiver,
    ) {
    }

    public function publish(
        string $projectName,
        string $layoutUuid,
        ?int $actorId,
        string $idempotencyKey,
        int $expectedDraftVersion,
        int $expectedLockVersion,
    ): WidgetLayoutPublishResult {
        $layout = $this->findLayout($projectName, $layoutUuid);
        if ($layout === null) {
            return WidgetLayoutPublishResult::layoutNotFound();
        }

        $requestHash = CanonicalJson::hash([
            'layout_uuid' => $layoutUuid,
            'expected_draft_id' => $expectedDraftVersion,
            'expected_lock_version' => $expectedLockVersion,
        ]);

        $claim = $this->idempotency->claim($projectName, self::ACTION, $layoutUuid, $actorId, $idempotencyKey, $requestHash);
        $replay = $this->resolveClaim($claim, $projectName, $layout);
        if ($replay !== null) {
            return $replay;
        }

        $currentDraft = $this->currentDraft($layout);
        if (!$this->matchesExpectedDraft($currentDraft, $expectedDraftVersion, $expectedLockVersion)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetLayoutPublishResult::draftStale();
        }

        if ($actorId !== null) {
            $this->autoApproveDraftManifests($projectName, $currentDraft, $actorId);
        }

        try {
            $preflight = $this->assessPromotionEligibility($projectName, $currentDraft);
        } catch (Throwable) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetLayoutPublishResult::providerUnavailable();
        }

        if ($preflight->failure !== null) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return $preflight->failure;
        }

        $outcome = $this->lockOrder->withLocks([$layout->id], [], function () use (
            $layout,
            $projectName,
            $actorId,
            $expectedDraftVersion,
            $expectedLockVersion,
            $claim,
            $requestHash,
        ): mixed {
            $lockedLayout = WidgetLayout::query()->findOrFail($layout->id);
            $draft = $this->currentDraft($lockedLayout);

            if (!$this->matchesExpectedDraft($draft, $expectedDraftVersion, $expectedLockVersion)) {
                return WidgetLayoutPublishResult::draftStale();
            }

            $revalidated = $this->assessPromotionEligibility($projectName, $draft);
            if ($revalidated->failure !== null) {
                return $revalidated->failure;
            }

            $pair = $this->lockOrder->allocateLayoutVersionPair($lockedLayout->id);

            $previousPublishedVersion = $lockedLayout->current_published_version_id === null
                ? null
                : WidgetLayoutVersion::query()->find($lockedLayout->current_published_version_id)?->version;

            $release = WidgetLayoutVersion::query()->create([
                'layout_id' => $lockedLayout->id,
                'project_name' => $projectName,
                'version' => $pair['release'],
                'state' => WidgetLayoutVersion::STATE_RELEASE,
                'based_on_version_id' => $draft->id,
                'lock_version' => 0,
                'registry_contracts' => $draft->registry_contracts,
                'registry_contract_hash' => $draft->registry_contract_hash,
                'eligibility_snapshot' => ['manifests' => $revalidated->manifestSnapshot],
                'actor_id' => $actorId,
                'published_at' => now(),
                'published_by' => $actorId,
            ]);
            $this->clonePlacements($draft, $release);

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

            $previousReleaseId = $lockedLayout->current_published_version_id;
            $lockedLayout->current_published_version_id = $release->id;
            $lockedLayout->current_draft_version_id = $freshDraft->id;
            $lockedLayout->save();

            // Placements own their instances: a placement this publish removed archives its
            // instance once no current release or draft shows it (Part 2 Task 3).
            $this->instanceArchiver->archiveUnplaced($projectName, $previousReleaseId === null ? null : (int) $previousReleaseId);

            $this->auditWriter->writeDurable('widget_layout.publish', [
                'actor_id' => $actorId,
                'project' => $projectName,
                'target' => $lockedLayout,
                'source_release' => $previousPublishedVersion,
                'destination_release' => $release->version,
                'result' => 'published',
                'idempotency_key_hash' => hash('sha256', $requestHash),
            ]);

            if (!$this->idempotency->markCommitted($claim->idempotencyKeyId, $requestHash, 200, self::RESOURCE_TYPE, $layout->uuid, (string) $release->version)) {
                throw new WidgetLayoutPublishIdempotencyLostException();
            }

            return [$release->fresh(), $freshDraft->fresh()];
        });

        if ($outcome instanceof WidgetLayoutPublishResult) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return $outcome;
        }

        /** @var array{0: WidgetLayoutVersion, 1: WidgetLayoutVersion} $outcome */
        [$release, $freshDraft] = $outcome;

        $this->dispatchPublishedEvent($projectName, $layoutUuid, $release, $freshDraft, $actorId);

        // 200 (not 201): the frozen contract fixture declares this route's
        // statuses as [200,403,404,409,422,503]; architecture.md permits a
        // committed mutation to return 200 or 201, and the machine contract
        // pinned 200 for publish/rollback. Fresh and replay both return 200.
        return WidgetLayoutPublishResult::published($release, $freshDraft, 200);
    }

    // -----------------------------------------------------------------
    // Claim resolution
    // -----------------------------------------------------------------

    private function resolveClaim(WidgetIdempotencyClaimResult $claim, string $projectName, WidgetLayout $layout): ?WidgetLayoutPublishResult
    {
        if ($claim->isConflict()) {
            return WidgetLayoutPublishResult::idempotencyConflict();
        }

        if ($claim->isInProgress()) {
            return WidgetLayoutPublishResult::idempotencyInProgress();
        }

        if ($claim->isReplay()) {
            $releaseVersion = (int) $claim->resourceVersion;
            $release = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('version', $releaseVersion)->first();
            $freshDraft = WidgetLayoutVersion::query()->where('layout_id', $layout->id)->where('version', $releaseVersion + 1)->first();

            if ($release === null || $freshDraft === null) {
                return WidgetLayoutPublishResult::artifactIntegrityFailed();
            }

            return WidgetLayoutPublishResult::published($release, $freshDraft, $claim->httpStatus ?? 200);
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Expected-state check
    // -----------------------------------------------------------------

    private function findLayout(string $projectName, string $uuid): ?WidgetLayout
    {
        return WidgetLayout::query()->where('project_name', $projectName)->where('uuid', $uuid)->first();
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
    // approval/revocation/block state, and artifact checksum resolvability.
    // -----------------------------------------------------------------

    private function assessPromotionEligibility(string $projectName, WidgetLayoutVersion $draft): WidgetLayoutPublishPreflight
    {
        $issues = $this->compatibilityChecker->compare((array) $draft->registry_contracts);
        if ($issues !== []) {
            return WidgetLayoutPublishPreflight::failed(WidgetLayoutPublishResult::registryDrift($issues));
        }

        $manifestSnapshot = [];

        foreach ($this->distinctManifestIds($draft->id) as $manifestId) {
            $manifest = WidgetArtifactManifestModel::query()
                ->where('project_name', $projectName)
                ->where('id', $manifestId)
                ->first();

            if ($manifest === null) {
                return WidgetLayoutPublishPreflight::failed(WidgetLayoutPublishResult::artifactIntegrityFailed());
            }

            if ($manifest->eligibility_state === WidgetManifestApprovalService::STATE_REVOKED) {
                return WidgetLayoutPublishPreflight::failed(WidgetLayoutPublishResult::manifestRevoked());
            }

            if ($manifest->eligibility_state !== WidgetManifestApprovalService::STATE_APPROVED) {
                return WidgetLayoutPublishPreflight::failed(WidgetLayoutPublishResult::manifestApprovalRequired());
            }

            if ((bool) $manifest->blocked) {
                return WidgetLayoutPublishPreflight::failed(WidgetLayoutPublishResult::manifestBlocked());
            }

            $blob = WidgetArtifactBlob::query()
                ->where('project_name', $projectName)
                ->where('id', $manifest->artifact_blob_id)
                ->first();

            if ($blob === null) {
                return WidgetLayoutPublishPreflight::failed(WidgetLayoutPublishResult::artifactIntegrityFailed());
            }

            $descriptor = $this->blobs->findByProjectAndHash($projectName, $blob->artifact_hash);
            if ($descriptor === null || !hash_equals($blob->artifact_hash, $descriptor->checksum)) {
                return WidgetLayoutPublishPreflight::failed(WidgetLayoutPublishResult::artifactIntegrityFailed());
            }

            $manifestSnapshot[] = [
                'manifest_hash' => $manifest->manifest_hash,
                'trust_policy_version' => $manifest->trust_policy_version,
                'trust_lane' => $manifest->trust_lane,
                'attestation_version' => (int) $manifest->attestation_version,
            ];
        }

        usort($manifestSnapshot, static fn (array $a, array $b): int => $a['manifest_hash'] <=> $b['manifest_hash']);

        return WidgetLayoutPublishPreflight::ok($manifestSnapshot);
    }

    /**
     * No approval step any more: approve, for the publishing user, every still-pending manifest
     * the draft pins, and re-approve the CURRENT manifest of each pinned widget/theme pair (a
     * deploy sync may have changed a hub widget since it was placed). A blocked widget is skipped:
     * blocking means that widget renders nothing (Core block check), never that the rest of the
     * layout cannot be published. Any other failure is left to the promotion preflight, which
     * reports the precise reason.
     */
    private function autoApproveDraftManifests(string $projectName, WidgetLayoutVersion $draft, int $actorId): void
    {
        $placements = WidgetPlacement::query()->where('layout_version_id', $draft->id)->with('instanceVersion')->get();
        $pairs = [];

        foreach ($placements as $placement) {
            $version = $placement->instanceVersion;
            if ($version === null) {
                continue;
            }

            $pairs[$version->widget_id . ':' . $version->widget_theme_id] = [(int) $version->widget_id, (int) $version->widget_theme_id];

            try {
                $this->autoApprover->ensurePinnedApproved($version, $actorId);
            } catch (WidgetBlockedException) {
                continue;
            } catch (Throwable $e) {
                report($e);
            }
        }

        foreach ($pairs as [$widgetId, $themeId]) {
            try {
                $this->autoApprover->ensureApproved($widgetId, $themeId, $actorId, $projectName);
            } catch (WidgetBlockedException) {
                continue;
            } catch (Throwable $e) {
                // The pinned snapshot is what this release promotes; a failure to refresh the
                // current manifest must not fail the publish.
                report($e);
            }
        }
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
    // Placement cloning
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

    private function dispatchPublishedEvent(string $projectName, string $layoutUuid, WidgetLayoutVersion $release, WidgetLayoutVersion $freshDraft, ?int $actorId): void
    {
        try {
            event(new WidgetLayoutPublished(
                projectName: $projectName,
                layoutUuid: $layoutUuid,
                releaseVersion: $release->version,
                freshDraftVersion: $freshDraft->version,
                actorId: $actorId,
                correlationId: (string) Str::uuid(),
            ));
        } catch (Throwable) {
            // A post-commit listener failure must never turn the already-
            // committed publish into an error response (architecture.md:
            // "Failure of non-durable after-commit work cannot turn the
            // committed response into 503"). Task 24's listeners own their
            // own error handling/retries; this service's obligation ends at
            // "announce the commit".
        }
    }
}

/**
 * Thrown when {@see WidgetIdempotencyService::markCommitted()} reports the
 * claim was lost DURING an otherwise successful publish -- forces the
 * enclosing transaction to roll back the just-inserted Release/Draft pair
 * rather than committing an orphaned promotion nobody's idempotency result
 * points at. Co-located: this task's file list has no separate slot for it,
 * mirroring
 * {@see \Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraftIdempotencyLostException}'s
 * identical co-location precedent.
 */
final class WidgetLayoutPublishIdempotencyLostException extends \RuntimeException
{
}

/**
 * Outcome of {@see WidgetLayoutPublishService::assessPromotionEligibility()}.
 * Co-located (see {@see WidgetLayoutPublishIdempotencyLostException}'s
 * docblock for why).
 */
final readonly class WidgetLayoutPublishPreflight
{
    /**
     * @param list<array{manifest_hash: string, trust_policy_version: string, trust_lane: string, attestation_version: int}> $manifestSnapshot
     */
    private function __construct(
        public array $manifestSnapshot,
        public ?WidgetLayoutPublishResult $failure,
    ) {
    }

    /**
     * @param list<array{manifest_hash: string, trust_policy_version: string, trust_lane: string, attestation_version: int}> $manifestSnapshot
     */
    public static function ok(array $manifestSnapshot): self
    {
        return new self($manifestSnapshot, null);
    }

    public static function failed(WidgetLayoutPublishResult $failure): self
    {
        return new self([], $failure);
    }
}

/**
 * Outcome of every {@see WidgetLayoutPublishService::publish()} call.
 * Co-located (see {@see WidgetLayoutPublishIdempotencyLostException}'s
 * docblock for why), mirroring
 * {@see \Cms\Widget\SiteLayout\Drafts\UpdateInstanceAndRepinDraftResult}'s
 * identical shape: a single result carrying either the two committed
 * identities (Release + fresh Draft) or a terminal failure status.
 */
final readonly class WidgetLayoutPublishResult
{
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_LAYOUT_NOT_FOUND = 'layout_not_found';

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

    public static function published(WidgetLayoutVersion $release, WidgetLayoutVersion $draft, int $httpStatus = 200): self
    {
        return new self(self::STATUS_PUBLISHED, $release, $draft, $httpStatus);
    }

    public static function layoutNotFound(): self
    {
        return new self(self::STATUS_LAYOUT_NOT_FOUND);
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
