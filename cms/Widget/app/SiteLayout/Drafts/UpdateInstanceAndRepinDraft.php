<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Drafts;

use App\Contracts\Modules\ModuleLifecycleBarrier;
use Cms\Widget\Definition\WidgetDefinitionGateway;
use Cms\Widget\Instances\WidgetInstanceVersionAllocator;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializationResult;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Cms\Widget\Rendering\Eligibility\WidgetBlockedException;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\Eligibility\WidgetManifestAutoApprover;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryContractSnapshot;
use Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder;
use Cms\Widget\Support\Idempotency\WidgetIdempotencyClaimResult;
use Cms\Widget\Support\Idempotency\WidgetIdempotencyService;
use Illuminate\Contracts\Foundation\Application;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Throwable;

/**
 * Owns `POST /layouts/{uuid}/draft/instances/{instance_uuid}/versions-and-repin`,
 * the explicit shared-Instance repin orchestration (plan Chunk 2 Task 18,
 * lines 1053-1080; architecture.md "Instance create, clone, and update":
 * "'Update shared instance and repin this draft' belongs to a callable
 * WidgetLayoutOrchestrator endpoint after both services exist").
 *
 * Repin creates a new immutable {@see WidgetInstanceVersion} exactly like
 * {@see \Cms\Widget\Instances\WidgetInstanceService::update()} (Task 16), so
 * it goes through the SAME two Task-16 mechanisms rather than duplicating
 * them:
 *
 * - {@see WidgetInstanceVersionAllocator} (injected below) is REUSED
 *   verbatim -- it is, in its own words, "the production allocator" for
 *   existing-Instance updates/repin.
 * - The barrier-complement mutual-exclusion PATTERN in
 *   {@see self::runVersionCreatingTransaction()} below is a deliberate,
 *   disclosed MIRROR (not a shared call) of
 *   {@see \Cms\Widget\Instances\WidgetInstanceService::runVersionCreatingTransaction()}'s
 *   identical logic: WidgetInstanceService.php is outside this task's
 *   allowed_paths and that method is private, so it cannot be called
 *   directly. Both copies must be kept in lockstep by hand until a future
 *   task is scoped to extract a shared primitive.
 *
 * Canonical lock order (architecture.md "Instance create, clone, and
 * update": "idempotency claim; Layout row when the action owns one;
 * existing Instance row..."): {@see WidgetAggregateLockOrder::withLocks()}
 * locks the Layout row first; {@see WidgetInstanceVersionAllocator::allocate()}
 * is then called from INSIDE that same open transaction and itself calls
 * `withLocks([], [$instanceId], ...)`, which Laravel nests as a SAVEPOINT
 * (Connection::transaction() detects it is already inside a transaction) --
 * so the Instance row lock is acquired strictly AFTER the Layout row lock,
 * both inside the ONE outer transaction, with zero duplicated locking code.
 *
 * `usage_fingerprint` (frozen request field, distinct in scope from
 * {@see \Cms\Widget\Instances\WidgetInstanceService::usage()}'s
 * Instance-wide, all-Layouts `usage_fingerprint`) is a SERVER-COMPUTED,
 * OPAQUE comparison token over exactly the DoD-listed identity set --
 * this Draft's version/lock_version, the Instance's current version
 * NUMBER, and the sorted uuids of Placements in THIS Draft that currently
 * reference ANY Version of THIS Instance ("matching Placements"). It is
 * never parsed, only compared for equality (coordinator brief: "never
 * trust or parse a client value beyond equality"). Both this validation
 * and the `GET /layouts/{uuid}/draft` read projection
 * ({@see \Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutDraftController}'s
 * `instance_fingerprints` map) compute it through the ONE shared
 * {@see WidgetDraftUsageFingerprint} primitive, so the value the server
 * hands the client and the value it later validates can never drift.
 *
 * DISCLOSED INTERPRETATION: despite its literal name, `expected_draft_id`
 * (this route's OWN frozen field name, distinct from
 * `/layouts/{uuid}/draft`'s `expected_current_draft_id`) is compared
 * against the Draft's VERSION NUMBER, not a raw internal row id --
 * identical reasoning to
 * {@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService::save()}'s
 * own disclosed interpretation of `expected_current_draft_id` (Task 17):
 * `widget_layout_versions` carries no uuid, and the frozen
 * `WidgetLayoutResource.current_draft` nested fields are exactly
 * `[version, lock_version]`.
 *
 * "Matching Placements" (architecture.md: "repins only matching
 * Placements in that Draft") are every Placement row, in the NAMED Draft
 * only, whose `widget_instance_version_id` belongs to ANY Version of this
 * Instance -- the identical set-derivation
 * {@see \Cms\Widget\Instances\WidgetInstanceService::usageProjection()}
 * already uses for its own (differently-scoped) usage fingerprint. Never
 * another Draft, another Layout, or another Instance's Placements.
 *
 * "Slot trust lanes" (DoD checklist item 4) are captured for free by
 * reusing {@see WidgetRegistryContractSnapshot::build()} exactly like
 * {@see WidgetLayoutDraftService} does -- Slot semantic fields
 * (cardinality, supported scope types, fallback, `trust_lane`,
 * availability) are ALREADY part of every `slots.*` entry that method
 * writes into `registry_contracts`; this task adds no separate trust-lane
 * comparison because no such per-mutation gate exists anywhere else in
 * this module (Slot trust lane is registry/compatibility-hash metadata,
 * not an authorization predicate -- see
 * {@see \Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration} class
 * docblock).
 */
final class UpdateInstanceAndRepinDraft
{
    private const ACTION = 'layout_repin';

    private const RESOURCE_TYPE = 'widget_instance_draft_repin';

    private const CONFLICT_MODULE_DISABLED = 'WIDGET_MODULE_IN_USE';

    public function __construct(
        private readonly WidgetIdempotencyService $idempotency,
        private readonly WidgetAggregateLockOrder $lockOrder,
        private readonly WidgetInstanceVersionAllocator $allocator,
        private readonly WidgetDefinitionGateway $definitions,
        private readonly WidgetLegacyManifestMaterializer $materializer,
        private readonly WidgetManifestAutoApprover $autoApprover,
        private readonly WidgetRegistryContractSnapshot $registrySnapshot,
        private readonly ModuleLifecycleBarrier $barrier,
        private readonly Application $app,
    ) {
    }

    /**
     * @param array{widget_id: int, widget_theme_id: int, values?: array<string, mixed>, provider_contract?: array<string, mixed>} $input
     */
    public function handle(
        string $projectName,
        string $layoutUuid,
        string $instanceUuid,
        ?int $actorId,
        string $idempotencyKey,
        int $expectedCurrentInstanceVersion,
        int $expectedDraftId,
        int $expectedDraftLockVersion,
        string $usageFingerprint,
        array $input,
    ): UpdateInstanceAndRepinDraftResult {
        $layout = $this->findLayout($projectName, $layoutUuid);
        if ($layout === null) {
            return UpdateInstanceAndRepinDraftResult::layoutNotFound();
        }

        $instance = $this->findInstance($projectName, $instanceUuid);
        if ($instance === null) {
            return UpdateInstanceAndRepinDraftResult::instanceNotFound();
        }

        $widgetId = (int) $input['widget_id'];
        $themeId = (int) $input['widget_theme_id'];
        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        $providerContract = is_array($input['provider_contract'] ?? null) ? $input['provider_contract'] : [];

        $requestHash = CanonicalJson::hash([
            'layout_uuid' => $layoutUuid,
            'instance_uuid' => $instanceUuid,
            'expected_current_instance_version' => $expectedCurrentInstanceVersion,
            'expected_draft_id' => $expectedDraftId,
            'expected_draft_lock_version' => $expectedDraftLockVersion,
            'usage_fingerprint' => $usageFingerprint,
            'widget_id' => $widgetId,
            'widget_theme_id' => $themeId,
            'values' => $values,
            'provider_contract' => $providerContract,
        ]);

        $resourceScope = $layoutUuid . ':' . $instanceUuid;
        $claim = $this->idempotency->claim($projectName, self::ACTION, $resourceScope, $actorId, $idempotencyKey, $requestHash);
        $replay = $this->resolveClaim($claim, $projectName, $layoutUuid, $instanceUuid);
        if ($replay !== null) {
            return $replay;
        }

        if (!$this->definitions->isValidPairing($widgetId, $themeId)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return UpdateInstanceAndRepinDraftResult::invalidPairing();
        }

        if (!$this->definitions->themeApplicableToProject($themeId, $projectName)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return UpdateInstanceAndRepinDraftResult::notApplicable();
        }

        try {
            $gate = $this->gateManifest($projectName, $widgetId, $themeId, $actorId);
        } catch (WidgetBlockedException $blocked) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            throw $blocked;
        }
        if (!$gate->ready) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return $gate->failure;
        }

        $outcome = $this->runVersionCreatingTransaction(function () use (
            $layout,
            $instance,
            $projectName,
            $actorId,
            $widgetId,
            $themeId,
            $values,
            $providerContract,
            $expectedCurrentInstanceVersion,
            $expectedDraftId,
            $expectedDraftLockVersion,
            $usageFingerprint,
            $gate,
            $claim,
            $requestHash,
        ): mixed {
            return $this->lockOrder->withLocks([$layout->id], [], function () use (
                $layout,
                $instance,
                $projectName,
                $actorId,
                $widgetId,
                $themeId,
                $values,
                $providerContract,
                $expectedCurrentInstanceVersion,
                $expectedDraftId,
                $expectedDraftLockVersion,
                $usageFingerprint,
                $gate,
                $claim,
                $requestHash,
            ): mixed {
                $lockedLayout = WidgetLayout::query()->findOrFail($layout->id);
                $draft = $lockedLayout->current_draft_version_id === null
                    ? null
                    : WidgetLayoutVersion::query()->find($lockedLayout->current_draft_version_id);

                if ($draft === null || $draft->version !== $expectedDraftId || $draft->lock_version !== $expectedDraftLockVersion) {
                    return UpdateInstanceAndRepinDraftResult::draftStale();
                }

                $matchingPlacementIds = $this->matchingPlacementIds($draft->id, $instance->id);
                $matchingUuids = WidgetPlacement::query()->whereIn('id', $matchingPlacementIds)->pluck('uuid')->all();

                $currentInstanceVersionNumber = $instance->current_version_id === null
                    ? null
                    : WidgetInstanceVersion::query()->whereKey($instance->current_version_id)->value('version');

                // Identical primitive the read path exposes on
                // WidgetLayoutDraftResource.instance_fingerprints, so a
                // client can echo the value we handed it and never trip a
                // false-positive WIDGET_USAGE_CHANGED. WidgetDraftUsageFingerprint
                // sorts the matching uuids, so no separate sort() here.
                $computedFingerprint = WidgetDraftUsageFingerprint::compute(
                    $draft->version,
                    $draft->lock_version,
                    $currentInstanceVersionNumber === null ? null : (int) $currentInstanceVersionNumber,
                    $matchingUuids,
                );

                if (!hash_equals($computedFingerprint, $usageFingerprint)) {
                    return UpdateInstanceAndRepinDraftResult::usageChanged();
                }

                $allocation = $this->allocator->allocate(
                    $instance->id,
                    $expectedCurrentInstanceVersion,
                    function (WidgetInstance $lockedInstance, int $nextVersion) use (
                        $projectName,
                        $actorId,
                        $widgetId,
                        $themeId,
                        $values,
                        $providerContract,
                        $gate,
                        $draft,
                        $matchingPlacementIds,
                    ): WidgetInstanceVersion {
                        $version = $this->insertVersion($lockedInstance, $nextVersion, $projectName, $widgetId, $themeId, $values, $providerContract, $gate->manifest, $actorId);
                        $lockedInstance->current_version_id = $version->id;
                        $lockedInstance->updated_by = $actorId;
                        $lockedInstance->save();

                        if ($matchingPlacementIds !== []) {
                            WidgetPlacement::query()->whereIn('id', $matchingPlacementIds)->update(['widget_instance_version_id' => $version->id]);
                        }

                        $referenceSet = $this->referenceSetFrom($draft->id);
                        $snapshot = $this->registrySnapshot->build(
                            $referenceSet['slot_keys'],
                            $referenceSet['template_context_keys'],
                            $referenceSet['target_context_types'],
                            $referenceSet['provider_refs'],
                        );

                        $draft->registry_contracts = $snapshot['contracts'];
                        $draft->registry_contract_hash = $snapshot['hash'];
                        $draft->lock_version = $draft->lock_version + 1;
                        $draft->save();

                        return $version;
                    }
                );

                if ($allocation->isNotFound()) {
                    return UpdateInstanceAndRepinDraftResult::instanceNotFound();
                }

                if ($allocation->isStale()) {
                    return UpdateInstanceAndRepinDraftResult::usageChanged();
                }

                /** @var WidgetInstanceVersion $version */
                $version = $allocation->value;

                if (!$this->idempotency->markCommitted($claim->idempotencyKeyId, $requestHash, 200, self::RESOURCE_TYPE, $instance->uuid, (string) $version->version)) {
                    throw new UpdateInstanceAndRepinDraftIdempotencyLostException();
                }

                return [$instance->fresh(), $draft->fresh()];
            });
        });

        if ($outcome instanceof UpdateInstanceAndRepinDraftResult) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return $outcome;
        }

        if (is_string($outcome)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return UpdateInstanceAndRepinDraftResult::moduleConflict();
        }

        /** @var array{0: WidgetInstance, 1: WidgetLayoutVersion} $outcome */
        [$freshInstance, $freshDraft] = $outcome;

        return UpdateInstanceAndRepinDraftResult::repinned($freshInstance, $freshDraft);
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    private function findLayout(string $projectName, string $uuid): ?WidgetLayout
    {
        return WidgetLayout::query()->where('project_name', $projectName)->where('uuid', $uuid)->first();
    }

    private function findInstance(string $projectName, string $uuid): ?WidgetInstance
    {
        return WidgetInstance::query()->where('project_name', $projectName)->where('uuid', $uuid)->first();
    }

    /**
     * Every Placement row in Draft `$draftId` whose pinned Instance Version
     * belongs to Instance `$instanceId` -- regardless of WHICH of that
     * Instance's (possibly historical) Versions it currently references
     * (see class docblock, "Matching Placements").
     *
     * @return list<int>
     */
    private function matchingPlacementIds(int $draftId, int $instanceId): array
    {
        $versionIds = WidgetInstanceVersion::query()->where('instance_id', $instanceId)->pluck('id');

        return WidgetPlacement::query()
            ->where('layout_version_id', $draftId)
            ->whereIn('widget_instance_version_id', $versionIds)
            ->pluck('id')
            ->all();
    }

    /**
     * Resolves a fresh idempotency claim into either null (proceed) or a
     * terminal result the caller must return immediately -- mirrors
     * {@see \Cms\Widget\Instances\WidgetInstanceService}'s own
     * `resolveClaim()`, specialized to re-fetch BOTH the Instance and the
     * owning Layout's current Draft (this route's two "both normalized
     * Resources") using the layout/instance uuids already known from THIS
     * request, rather than re-deriving them from the stored claim's single
     * `resourceId` (unlike WidgetInstanceService's create/clone, where the
     * server-generated uuid is not otherwise available to the caller).
     */
    private function resolveClaim(WidgetIdempotencyClaimResult $claim, string $projectName, string $layoutUuid, string $instanceUuid): ?UpdateInstanceAndRepinDraftResult
    {
        if ($claim->isConflict()) {
            return UpdateInstanceAndRepinDraftResult::idempotencyConflict();
        }

        if ($claim->isInProgress()) {
            return UpdateInstanceAndRepinDraftResult::idempotencyInProgress();
        }

        if ($claim->isReplay()) {
            $instance = $this->findInstance($projectName, $instanceUuid);
            $layout = $this->findLayout($projectName, $layoutUuid);
            $draft = $layout === null || $layout->current_draft_version_id === null
                ? null
                : WidgetLayoutVersion::query()->find($layout->current_draft_version_id);

            if ($instance === null || $draft === null) {
                return UpdateInstanceAndRepinDraftResult::integrityFailure();
            }

            return UpdateInstanceAndRepinDraftResult::repinned($instance, $draft, $claim->httpStatus ?? 200);
        }

        return null;
    }

    /**
     * DISCLOSED MIRROR of
     * {@see \Cms\Widget\Instances\WidgetInstanceService::runVersionCreatingTransaction()} --
     * see this class's own docblock for why it could not be called
     * directly. Same adjudicated COMPLEMENTARY-WINDOWS semantics: live HTTP
     * mutation proceeds IFF the application is NOT down for maintenance
     * (refusal surfaces the same frozen WIDGET_MODULE_IN_USE conflict);
     * console callers (tests, future CLI tooling) go through
     * `$this->barrier->transition()` verbatim, so a future real
     * cross-process adapter supersedes both call sites identically with
     * zero console call-site change.
     *
     * @template TReturn
     * @param callable(): TReturn $body
     * @return TReturn|string a stable conflict code string on failure.
     */
    private function runVersionCreatingTransaction(callable $body): mixed
    {
        if (!$this->app->runningInConsole()) {
            if ($this->app->isDownForMaintenance()) {
                return self::CONFLICT_MODULE_DISABLED;
            }

            if (!$this->widgetModuleEnabled()) {
                return self::CONFLICT_MODULE_DISABLED;
            }

            return $body();
        }

        $result = null;

        $conflict = $this->barrier->transition('Widget', function () use ($body, &$result): ?string {
            if (!$this->widgetModuleEnabled()) {
                return self::CONFLICT_MODULE_DISABLED;
            }

            $result = $body();

            return null;
        });

        return $conflict !== null ? $conflict : $result;
    }

    private function widgetModuleEnabled(): bool
    {
        return ModuleFacade::find('Widget')?->isEnabled() === true;
    }

    /**
     * Derives the {@see WidgetRegistryContractSnapshot::build()} reference
     * set from the Draft's CURRENT, PERSISTED Placement rows (read fresh,
     * inside the same transaction, AFTER the repin update above) -- unlike
     * {@see WidgetLayoutDraftService::referenceSetFrom()} (private, and
     * therefore not callable from here), which derives it from in-memory
     * validated rows before they are persisted. Small, disclosed
     * duplication of the same small aggregation, operating on rows instead
     * of arrays.
     *
     * @return array{
     *     slot_keys: list<string>,
     *     template_context_keys: list<string>,
     *     target_context_types: list<string>,
     *     provider_refs: list<array{key: string, version: int}>
     * }
     */
    private function referenceSetFrom(int $draftId): array
    {
        $placements = WidgetPlacement::query()->where('layout_version_id', $draftId)->with('instanceVersion')->get();

        $slotKeys = [];
        $templateKeys = [];
        $targetTypes = [];
        $providerRefs = [];
        $seenProviderRefs = [];

        foreach ($placements as $placement) {
            $slotKeys[$placement->slot_key] = true;

            if ($placement->scope_key !== null) {
                $templateKeys[$placement->scope_key] = true;
            }

            if ($placement->target_type !== null) {
                $targetTypes[$placement->target_type] = true;
            }

            $refs = is_array($placement->instanceVersion?->provider_refs) ? $placement->instanceVersion->provider_refs : [];
            foreach ($refs as $ref) {
                if (!is_array($ref) || !isset($ref['key'], $ref['version']) || !is_string($ref['key'])) {
                    continue;
                }

                $providerKey = $ref['key'] . '@' . (int) $ref['version'];
                if (isset($seenProviderRefs[$providerKey])) {
                    continue;
                }

                $seenProviderRefs[$providerKey] = true;
                $providerRefs[] = ['key' => $ref['key'], 'version' => (int) $ref['version']];
            }
        }

        return [
            'slot_keys' => array_keys($slotKeys),
            'template_context_keys' => array_keys($templateKeys),
            'target_context_types' => array_keys($targetTypes),
            'provider_refs' => $providerRefs,
        ];
    }

    private function insertVersion(
        WidgetInstance $instance,
        int $version,
        string $projectName,
        int $widgetId,
        int $themeId,
        array $values,
        array $providerContract,
        WidgetArtifactManifestModel $manifest,
        ?int $actorId,
    ): WidgetInstanceVersion {
        $schema = $this->definitions->canonicalSchema($widgetId, $themeId);

        return WidgetInstanceVersion::query()->create([
            'instance_id' => $instance->id,
            'project_name' => $projectName,
            'version' => $version,
            'widget_id' => $widgetId,
            'widget_theme_id' => $themeId,
            'renderer_key' => WidgetLegacyManifestMaterializer::RENDERER_KEY,
            'renderer_version' => WidgetLegacyManifestMaterializer::RENDERER_VERSION,
            'trust_classification' => (string) $manifest->trust_lane,
            'schema' => $schema,
            'schema_hash' => CanonicalJson::hash($schema),
            'values' => $values,
            'provider_refs' => $providerContract,
            'artifact_manifest_id' => $manifest->id,
            'created_by' => $actorId,
        ]);
    }

    /**
     * DISCLOSED DUPLICATE of
     * {@see \Cms\Widget\Instances\WidgetInstanceService::gateManifest()} --
     * that method is private and WidgetInstanceService.php is outside this
     * task's allowed_paths, so the identical sequence is recomposed here
     * from the SAME public primitives (WidgetLegacyManifestMaterializer,
     * WidgetArtifactManifestModel, WidgetArtifactBlob,
     * WidgetManifestApprovalService::STATE_APPROVED) in the SAME order:
     * materialize (idempotent reuse-or-create) -> exact approved Manifest
     * (not pending/revoked) -> not currently emergency-blocked -> Blob
     * verified/readable.
     */
    private function gateManifest(string $projectName, int $widgetId, int $themeId, ?int $actorId): UpdateInstanceAndRepinManifestGate
    {
        // No approval step any more: approve the current manifest for the acting user first.
        // A blocked widget escapes as WidgetBlockedException (409 WIDGET_BLOCKED); any other
        // failure falls through so the gate below reports its precise reason.
        if ($actorId !== null) {
            try {
                $this->autoApprover->ensureApproved($widgetId, $themeId, $actorId, $projectName);
            } catch (WidgetBlockedException $blocked) {
                throw $blocked;
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $materialization = $this->materializer->materialize($widgetId, $themeId, $projectName);
        } catch (Throwable) {
            return UpdateInstanceAndRepinManifestGate::failed(UpdateInstanceAndRepinDraftResult::materializationFailed());
        }

        if ($materialization->status === WidgetLegacyManifestMaterializationResult::STATUS_INVALID_PAIRING) {
            return UpdateInstanceAndRepinManifestGate::failed(UpdateInstanceAndRepinDraftResult::invalidPairing());
        }

        if ($materialization->status === WidgetLegacyManifestMaterializationResult::STATUS_NOT_APPLICABLE) {
            return UpdateInstanceAndRepinManifestGate::failed(UpdateInstanceAndRepinDraftResult::notApplicable());
        }

        $row = $materialization->manifestRow;
        if ($row === null || !isset($row['manifest_hash'])) {
            return UpdateInstanceAndRepinManifestGate::failed(UpdateInstanceAndRepinDraftResult::materializationFailed());
        }

        $manifest = WidgetArtifactManifestModel::query()
            ->where('project_name', $projectName)
            ->where('manifest_hash', $row['manifest_hash'])
            ->first();

        if ($manifest === null) {
            return UpdateInstanceAndRepinManifestGate::failed(UpdateInstanceAndRepinDraftResult::materializationFailed());
        }

        if ($manifest->eligibility_state !== WidgetManifestApprovalService::STATE_APPROVED) {
            return UpdateInstanceAndRepinManifestGate::failed(UpdateInstanceAndRepinDraftResult::approvalRequired());
        }

        if ((bool) $manifest->blocked) {
            return UpdateInstanceAndRepinManifestGate::failed(UpdateInstanceAndRepinDraftResult::manifestBlocked());
        }

        $blobExists = WidgetArtifactBlob::query()
            ->where('project_name', $projectName)
            ->where('id', $manifest->artifact_blob_id)
            ->exists();

        if (!$blobExists) {
            return UpdateInstanceAndRepinManifestGate::failed(UpdateInstanceAndRepinDraftResult::integrityFailure());
        }

        return UpdateInstanceAndRepinManifestGate::ready($manifest);
    }
}

/**
 * Thrown when {@see WidgetIdempotencyService::markCommitted()} reports the
 * claim was lost DURING an otherwise successful mutation -- forces the
 * enclosing transaction to roll back the just-inserted Version/repin rather
 * than committing an orphaned mutation nobody's idempotency result points
 * at. Co-located: this task's allowed_paths has no separate file slot for
 * it, mirroring {@see \Cms\Widget\Instances\WidgetInstanceIdempotencyLostException}'s
 * identical co-location precedent.
 */
final class UpdateInstanceAndRepinDraftIdempotencyLostException extends \RuntimeException
{
}

/**
 * Outcome of {@see UpdateInstanceAndRepinDraft::gateManifest()}. Co-located
 * (see {@see UpdateInstanceAndRepinDraftIdempotencyLostException}'s
 * docblock for why), mirroring
 * {@see \Cms\Widget\Instances\WidgetInstanceManifestGate}'s identical
 * shape.
 */
final readonly class UpdateInstanceAndRepinManifestGate
{
    private function __construct(
        public bool $ready,
        public ?WidgetArtifactManifestModel $manifest = null,
        public ?UpdateInstanceAndRepinDraftResult $failure = null,
    ) {
    }

    public static function ready(WidgetArtifactManifestModel $manifest): self
    {
        return new self(true, $manifest);
    }

    public static function failed(UpdateInstanceAndRepinDraftResult $failure): self
    {
        return new self(false, null, $failure);
    }
}

/**
 * Outcome of every {@see UpdateInstanceAndRepinDraft::handle()} call.
 * Co-located (see {@see UpdateInstanceAndRepinDraftIdempotencyLostException}'s
 * docblock for why), mirroring
 * {@see \Cms\Widget\Instances\WidgetInstanceMutationResult}'s identical
 * shape, extended with the second normalized Draft resource this route
 * returns alongside the Instance.
 */
final readonly class UpdateInstanceAndRepinDraftResult
{
    public const STATUS_REPINNED = 'repinned';

    public const STATUS_LAYOUT_NOT_FOUND = 'layout_not_found';

    public const STATUS_INSTANCE_NOT_FOUND = 'instance_not_found';

    public const STATUS_DRAFT_STALE = 'draft_stale';

    public const STATUS_USAGE_CHANGED = 'usage_changed';

    public const STATUS_INVALID_PAIRING = 'invalid_pairing';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUS_APPROVAL_REQUIRED = 'approval_required';

    public const STATUS_MATERIALIZATION_FAILED = 'materialization_failed';

    public const STATUS_MANIFEST_BLOCKED = 'manifest_blocked';

    public const STATUS_MODULE_CONFLICT = 'module_conflict';

    public const STATUS_IDEMPOTENCY_CONFLICT = 'idempotency_conflict';

    public const STATUS_IDEMPOTENCY_IN_PROGRESS = 'idempotency_in_progress';

    public const STATUS_INTEGRITY_FAILURE = 'integrity_failure';

    private function __construct(
        public string $status,
        public ?WidgetInstance $instance = null,
        public ?WidgetLayoutVersion $draft = null,
        public ?int $httpStatus = null,
    ) {
    }

    public static function repinned(WidgetInstance $instance, WidgetLayoutVersion $draft, int $httpStatus = 200): self
    {
        return new self(self::STATUS_REPINNED, $instance, $draft, $httpStatus);
    }

    public static function layoutNotFound(): self
    {
        return new self(self::STATUS_LAYOUT_NOT_FOUND);
    }

    public static function instanceNotFound(): self
    {
        return new self(self::STATUS_INSTANCE_NOT_FOUND);
    }

    public static function draftStale(): self
    {
        return new self(self::STATUS_DRAFT_STALE);
    }

    public static function usageChanged(): self
    {
        return new self(self::STATUS_USAGE_CHANGED);
    }

    public static function invalidPairing(): self
    {
        return new self(self::STATUS_INVALID_PAIRING);
    }

    public static function notApplicable(): self
    {
        return new self(self::STATUS_NOT_APPLICABLE);
    }

    public static function approvalRequired(): self
    {
        return new self(self::STATUS_APPROVAL_REQUIRED);
    }

    public static function materializationFailed(): self
    {
        return new self(self::STATUS_MATERIALIZATION_FAILED);
    }

    public static function manifestBlocked(): self
    {
        return new self(self::STATUS_MANIFEST_BLOCKED);
    }

    public static function moduleConflict(): self
    {
        return new self(self::STATUS_MODULE_CONFLICT);
    }

    public static function idempotencyConflict(): self
    {
        return new self(self::STATUS_IDEMPOTENCY_CONFLICT);
    }

    public static function idempotencyInProgress(): self
    {
        return new self(self::STATUS_IDEMPOTENCY_IN_PROGRESS);
    }

    public static function integrityFailure(): self
    {
        return new self(self::STATUS_INTEGRITY_FAILURE);
    }
}
