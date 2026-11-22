<?php

declare(strict_types=1);

namespace Cms\Widget\Instances;

use App\Contracts\Modules\ModuleLifecycleBarrier;
use Cms\Widget\Definition\WidgetDefinitionGateway;
use Cms\Widget\Models\WidgetArtifactBlob;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializationResult;
use Cms\Widget\Rendering\Eligibility\WidgetLegacyManifestMaterializer;
use Cms\Widget\Rendering\Eligibility\WidgetBlockedException;
use Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService;
use Cms\Widget\Rendering\Eligibility\WidgetManifestAutoApprover;
use Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder;
use Cms\Widget\Support\Idempotency\WidgetIdempotencyClaimResult;
use Cms\Widget\Support\Idempotency\WidgetIdempotencyService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use Throwable;

/**
 * Orchestrates Widget Instance identity + immutable Version mutation (plan
 * Chunk 2 Task 16; architecture.md "Widget Instance and Layout Lifecycles"
 * > "Instance create, clone, and update").
 *
 * Canonical sequence per action, mirroring {@see WidgetIdempotencyService}'s
 * own documented caller sequence:
 *
 *   claim() -> materialize/gate the Manifest -> enter the module-transition
 *   mutual-exclusion window (console: ModuleLifecycleBarrier verbatim;
 *   live HTTP: the MaintenanceOnly-era complementary not-in-maintenance
 *   window -- see runVersionCreatingTransaction()'s docblock)
 *   -> (create/clone: insert Instance+Version1 atomically |
 *       update: lock Instance row, recheck expected Version, allocate
 *       MAX+1 via {@see WidgetInstanceVersionAllocator})
 *   -> markCommitted()/release() inside the SAME transaction as the mutation.
 *
 * Never repins a Layout Draft (architecture.md: "WidgetInstanceService does
 * not mutate Layout Drafts" -- that is Task 18's WidgetLayoutOrchestrator).
 */
final class WidgetInstanceService
{
    private const ACTION_CREATE = 'instance_create';

    private const ACTION_UPDATE = 'instance_update';

    private const ACTION_CLONE = 'instance_clone';

    private const RESOURCE_TYPE = 'widget_instance';

    /** Instance identity status values. No dedicated constant exists yet on WidgetInstance (out of this task's allowed_paths -- the model stays shape-only per its own docblock). */
    private const INSTANCE_STATUS_ACTIVE = 'active';

    private const INSTANCE_STATUS_ARCHIVED = 'archived';

    /** Conflict code this service's own barrier callback returns when the module has been disabled; ALWAYS surfaced to the API as WIDGET_MODULE_IN_USE regardless of which internal code produced it (see WidgetInstanceController). */
    private const CONFLICT_MODULE_DISABLED = 'WIDGET_MODULE_IN_USE';

    public function __construct(
        private readonly WidgetIdempotencyService $idempotency,
        private readonly WidgetAggregateLockOrder $lockOrder,
        private readonly WidgetInstanceVersionAllocator $allocator,
        private readonly WidgetDefinitionGateway $definitions,
        private readonly WidgetLegacyManifestMaterializer $materializer,
        private readonly WidgetManifestAutoApprover $autoApprover,
        private readonly ModuleLifecycleBarrier $barrier,
        private readonly Application $app,
    ) {
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    public function findByUuid(string $projectName, string $uuid): ?WidgetInstance
    {
        return WidgetInstance::query()
            ->where('project_name', $projectName)
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * @return array{in_use: bool, placement_count: int, usage_fingerprint: string}|null null when the Instance does not exist.
     */
    public function usage(string $projectName, string $uuid): ?array
    {
        $instance = $this->findByUuid($projectName, $uuid);

        if ($instance === null) {
            return null;
        }

        return $this->usageProjection($instance);
    }

    // -----------------------------------------------------------------
    // Create / Clone: no existing Instance row to lock (architecture.md:
    // "Create and clone have no target Instance row to lock").
    // -----------------------------------------------------------------

    /**
     * @param array{name: string, widget_id: int, widget_theme_id: int, values?: array<string, mixed>, provider_contract?: array<string, mixed>} $input
     */
    public function create(string $projectName, ?int $actorId, string $idempotencyKey, array $input): WidgetInstanceMutationResult
    {
        $widgetId = (int) $input['widget_id'];
        $themeId = (int) $input['widget_theme_id'];
        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        $providerContract = is_array($input['provider_contract'] ?? null) ? $input['provider_contract'] : [];

        $requestHash = CanonicalJson::hash([
            'name' => $input['name'],
            'widget_id' => $widgetId,
            'widget_theme_id' => $themeId,
            'values' => $values,
            'provider_contract' => $providerContract,
        ]);

        $claim = $this->idempotency->claim($projectName, self::ACTION_CREATE, 'instance', $actorId, $idempotencyKey, $requestHash);
        $replay = $this->resolveClaim($claim, $projectName, $requestHash);
        if ($replay !== null) {
            return $replay;
        }

        if (!$this->definitions->isValidPairing($widgetId, $themeId)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetInstanceMutationResult::invalidPairing();
        }

        if (!$this->definitions->themeApplicableToProject($themeId, $projectName)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetInstanceMutationResult::notApplicable();
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

        $outcome = $this->runVersionCreatingTransaction(function () use ($projectName, $actorId, $input, $widgetId, $themeId, $values, $providerContract, $gate, $claim, $requestHash): WidgetInstance {
            return DB::transaction(function () use ($projectName, $actorId, $input, $widgetId, $themeId, $values, $providerContract, $gate, $claim, $requestHash): WidgetInstance {
                $instance = WidgetInstance::query()->create([
                    'project_name' => $projectName,
                    'name' => (string) $input['name'],
                    'status' => self::INSTANCE_STATUS_ACTIVE,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                $version = $this->insertVersion($instance, 1, $projectName, $widgetId, $themeId, $values, $providerContract, $gate->manifest, $actorId);

                $instance->current_version_id = $version->id;
                $instance->save();

                if (!$this->idempotency->markCommitted($claim->idempotencyKeyId, $requestHash, 201, self::RESOURCE_TYPE, $instance->uuid, (string) $version->version)) {
                    throw new WidgetInstanceIdempotencyLostException();
                }

                return $instance->fresh();
            });
        });

        return $this->finishTransactionOutcome($outcome, $claim, $requestHash, static fn (WidgetInstance $instance) => WidgetInstanceMutationResult::created($instance));
    }

    /**
     * Clone reads an authorized source snapshot but creates a DISTINCT
     * identity (architecture.md). The source's CURRENT widget/theme pairing
     * and values/provider contract are copied; the Manifest gate is
     * re-verified fresh (source approval/blocked state may have changed).
     */
    public function cloneInstance(string $projectName, string $sourceUuid, ?int $actorId, string $idempotencyKey, string $newName): WidgetInstanceMutationResult
    {
        $source = $this->findByUuid($projectName, $sourceUuid);
        if ($source === null || $source->currentVersion === null) {
            return WidgetInstanceMutationResult::notFound();
        }

        $sourceVersion = $source->currentVersion;
        $widgetId = (int) $sourceVersion->widget_id;
        $themeId = (int) $sourceVersion->widget_theme_id;
        $values = is_array($sourceVersion->values) ? $sourceVersion->values : [];
        $providerContract = is_array($sourceVersion->provider_refs) ? $sourceVersion->provider_refs : [];

        $requestHash = CanonicalJson::hash([
            'source_uuid' => $sourceUuid,
            'name' => $newName,
        ]);

        $claim = $this->idempotency->claim($projectName, self::ACTION_CLONE, $sourceUuid, $actorId, $idempotencyKey, $requestHash);
        $replay = $this->resolveClaim($claim, $projectName, $requestHash);
        if ($replay !== null) {
            return $replay;
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

        $outcome = $this->runVersionCreatingTransaction(function () use ($projectName, $actorId, $newName, $widgetId, $themeId, $values, $providerContract, $gate, $claim, $requestHash): WidgetInstance {
            return DB::transaction(function () use ($projectName, $actorId, $newName, $widgetId, $themeId, $values, $providerContract, $gate, $claim, $requestHash): WidgetInstance {
                $instance = WidgetInstance::query()->create([
                    'project_name' => $projectName,
                    'name' => $newName,
                    'status' => self::INSTANCE_STATUS_ACTIVE,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                $version = $this->insertVersion($instance, 1, $projectName, $widgetId, $themeId, $values, $providerContract, $gate->manifest, $actorId);

                $instance->current_version_id = $version->id;
                $instance->save();

                if (!$this->idempotency->markCommitted($claim->idempotencyKeyId, $requestHash, 201, self::RESOURCE_TYPE, $instance->uuid, (string) $version->version)) {
                    throw new WidgetInstanceIdempotencyLostException();
                }

                return $instance->fresh();
            });
        });

        return $this->finishTransactionOutcome($outcome, $claim, $requestHash, static fn (WidgetInstance $instance) => WidgetInstanceMutationResult::created($instance));
    }

    // -----------------------------------------------------------------
    // Update: existing Instance row, row-locked allocation.
    // -----------------------------------------------------------------

    /**
     * Standalone PATCH: persists a ready Version and moves only the
     * Instance current pointer -- never repins any Draft (architecture.md).
     *
     * @param array{widget_id: int, widget_theme_id: int, values?: array<string, mixed>, provider_contract?: array<string, mixed>} $input
     */
    public function update(string $projectName, string $uuid, ?int $actorId, string $idempotencyKey, int $expectedCurrentVersion, array $input): WidgetInstanceMutationResult
    {
        $instance = $this->findByUuid($projectName, $uuid);
        if ($instance === null) {
            return WidgetInstanceMutationResult::notFound();
        }

        $widgetId = (int) $input['widget_id'];
        $themeId = (int) $input['widget_theme_id'];
        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        $providerContract = is_array($input['provider_contract'] ?? null) ? $input['provider_contract'] : [];

        $requestHash = CanonicalJson::hash([
            'expected_current_version' => $expectedCurrentVersion,
            'widget_id' => $widgetId,
            'widget_theme_id' => $themeId,
            'values' => $values,
            'provider_contract' => $providerContract,
        ]);

        $claim = $this->idempotency->claim($projectName, self::ACTION_UPDATE, $uuid, $actorId, $idempotencyKey, $requestHash);
        $replay = $this->resolveClaim($claim, $projectName, $requestHash);
        if ($replay !== null) {
            return $replay;
        }

        if (!$this->definitions->isValidPairing($widgetId, $themeId)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetInstanceMutationResult::invalidPairing();
        }

        if (!$this->definitions->themeApplicableToProject($themeId, $projectName)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetInstanceMutationResult::notApplicable();
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

        $outcome = $this->runVersionCreatingTransaction(function () use ($instance, $projectName, $actorId, $widgetId, $themeId, $values, $providerContract, $expectedCurrentVersion, $gate, $claim, $requestHash): WidgetInstance|WidgetInstanceMutationResult {
            return DB::transaction(function () use ($instance, $projectName, $actorId, $widgetId, $themeId, $values, $providerContract, $expectedCurrentVersion, $gate, $claim, $requestHash): WidgetInstance|WidgetInstanceMutationResult {
                $allocation = $this->allocator->allocate(
                    $instance->id,
                    $expectedCurrentVersion,
                    function (WidgetInstance $locked, int $nextVersion) use ($projectName, $actorId, $widgetId, $themeId, $values, $providerContract, $gate): WidgetInstanceVersion {
                        $version = $this->insertVersion($locked, $nextVersion, $projectName, $widgetId, $themeId, $values, $providerContract, $gate->manifest, $actorId);
                        $locked->current_version_id = $version->id;
                        $locked->updated_by = $actorId;
                        $locked->save();

                        return $version;
                    }
                );

                if ($allocation->isNotFound()) {
                    return WidgetInstanceMutationResult::notFound();
                }

                if ($allocation->isStale()) {
                    return WidgetInstanceMutationResult::staleVersion();
                }

                /** @var WidgetInstanceVersion $version */
                $version = $allocation->value;

                if (!$this->idempotency->markCommitted($claim->idempotencyKeyId, $requestHash, 200, self::RESOURCE_TYPE, $instance->uuid, (string) $version->version)) {
                    throw new WidgetInstanceIdempotencyLostException();
                }

                return $instance->fresh();
            });
        });

        if ($outcome instanceof WidgetInstanceMutationResult) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return $outcome;
        }

        return $this->finishTransactionOutcome($outcome, $claim, $requestHash, static fn (WidgetInstance $instance) => WidgetInstanceMutationResult::updated($instance));
    }

    // -----------------------------------------------------------------
    // Archive / Delete: no idempotency-key protocol (fixture:
    // idempotency_scope null for both), naturally idempotent status flips.
    // -----------------------------------------------------------------

    public function archive(string $projectName, string $uuid): WidgetInstanceMutationResult
    {
        $instance = $this->findByUuid($projectName, $uuid);
        if ($instance === null) {
            return WidgetInstanceMutationResult::notFound();
        }

        $instance->status = self::INSTANCE_STATUS_ARCHIVED;
        $instance->save();

        return WidgetInstanceMutationResult::archived($instance->fresh());
    }

    /**
     * Delete soft-deletes only an UNUSED identity (architecture.md).
     * "Unused" means no retained Placement references any Version of this
     * Instance; the immutable Versions themselves are never removed.
     */
    public function delete(string $projectName, string $uuid): WidgetInstanceMutationResult
    {
        $instance = $this->findByUuid($projectName, $uuid);
        if ($instance === null) {
            return WidgetInstanceMutationResult::notFound();
        }

        $projection = $this->usageProjection($instance);
        if ($projection['in_use']) {
            return WidgetInstanceMutationResult::instanceInUse();
        }

        $instance->delete();

        return WidgetInstanceMutationResult::deleted();
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    /**
     * @return array{in_use: bool, placement_count: int, usage_fingerprint: string}
     */
    private function usageProjection(WidgetInstance $instance): array
    {
        $versionIds = WidgetInstanceVersion::query()->where('instance_id', $instance->id)->pluck('id');

        $placements = WidgetPlacement::query()->whereIn('widget_instance_version_id', $versionIds);
        $count = (clone $placements)->count();
        $maxId = (int) (clone $placements)->max('id');

        return [
            'in_use' => $count > 0,
            'placement_count' => $count,
            'usage_fingerprint' => hash('sha256', sprintf('instance:%d:%d:%d', $instance->id, $count, $maxId)),
        ];
    }

    /**
     * Resolves a fresh idempotency claim into either null (proceed with the
     * mutation, status === created) or a terminal WidgetInstanceMutationResult
     * (conflict / in-progress / replay) the caller must return immediately
     * without touching the module barrier or inserting anything.
     */
    private function resolveClaim(WidgetIdempotencyClaimResult $claim, string $projectName, string $requestHash): ?WidgetInstanceMutationResult
    {
        if ($claim->isConflict()) {
            return WidgetInstanceMutationResult::idempotencyConflict();
        }

        if ($claim->isInProgress()) {
            return WidgetInstanceMutationResult::idempotencyInProgress();
        }

        if ($claim->isReplay()) {
            $instance = $claim->resourceId === null
                ? null
                : WidgetInstance::query()->where('project_name', $projectName)->where('uuid', $claim->resourceId)->first();

            if ($instance === null) {
                return WidgetInstanceMutationResult::integrityFailure();
            }

            return WidgetInstanceMutationResult::replayed($instance, $claim->httpStatus ?? 200);
        }

        return null;
    }

    /**
     * Mutual exclusion between Instance-Version creation and managed
     * module disable (architecture.md line ~69: "The predicate and status
     * change are one transition ... Instance-Version creation ...
     * acquire[s] it and recheck[s] module-enabled/usage state").
     *
     * ADJUDICATED COMPLEMENTARY-WINDOWS SEMANTICS (coordinator ruling on
     * the Task 16 review): with no proven cross-process fencing adapter in
     * this repository (Task 5a gap G-013), the bound ModuleLifecycleBarrier
     * default is MaintenanceOnlyModuleLifecycleBarrier, which refuses EVERY
     * non-console caller unconditionally -- routing live HTTP mutation
     * through it would make this whole API dead on arrival (every live
     * admin create/update/clone answering 409 forever, and the pilot could
     * never produce runtime evidence). Mutual exclusion is therefore
     * achieved by COMPLEMENTARY WINDOWS instead of both sides calling the
     * same refuse-everything method:
     *
     * - Disable side (unchanged, elsewhere): console + drained maintenance
     *   only -- ManagedModuleDisabler through the barrier as-is.
     * - Mutation side (HTTP, here): proceeds IFF the application is NOT
     *   down for maintenance; when isDownForMaintenance() is true it
     *   refuses with the same frozen WIDGET_MODULE_IN_USE conflict
     *   surface. Checking maintenance state in the SERVICE (not the
     *   middleware) closes the exact bypass-cookie hole the barrier's own
     *   docblock warns about: an admin holding a `php artisan down
     *   --secret` cookie reaches this code mid-disable-window and must
     *   still be refused.
     * - The two windows are DISJOINT (disable only DURING maintenance,
     *   mutation only OUTSIDE it), so the invariant "disable-wins => no
     *   Version created" holds without shared acquisition; the
     *   module-enabled recheck immediately before the transaction body is
     *   the second belt on both paths.
     * - Console callers (tests, future CLI tooling) keep going through
     *   $this->barrier->transition() VERBATIM, so when a real
     *   cross-process adapter replaces the bound default (post-G-013
     *   evidence), both sides converge on true shared acquisition with
     *   zero call-site change on the console path. This complement is the
     *   MaintenanceOnly-era DEGENERATE acquisition; a real adapter
     *   supersedes it.
     *
     * ANY conflict this returns (the maintenance-window refusal, the
     * console barrier's own refusal, or either path's module-disabled
     * detection) is surfaced identically by the caller as the ONE frozen
     * module-transition conflict, WIDGET_MODULE_IN_USE -- there is no
     * second code for "barrier could not even be attempted" in the frozen
     * 26-code contract.
     *
     * @template TReturn
     * @param callable(): TReturn $body
     * @return TReturn|string a stable conflict code string on failure.
     */
    private function runVersionCreatingTransaction(callable $body): mixed
    {
        if (!$this->app->runningInConsole()) {
            // Live HTTP window: complementary to the disable side's
            // console + maintenance window (see docblock).
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
     * Normalizes the three possible shapes `runVersionCreatingTransaction()`
     * can return (a barrier/module conflict code string, a
     * WidgetInstanceMutationResult the transaction body itself decided on,
     * or a successfully committed WidgetInstance) into the final result,
     * releasing the idempotency claim on every non-success path.
     *
     * @param callable(WidgetInstance): WidgetInstanceMutationResult $onSuccess
     */
    private function finishTransactionOutcome(mixed $outcome, WidgetIdempotencyClaimResult $claim, string $requestHash, callable $onSuccess): WidgetInstanceMutationResult
    {
        if (is_string($outcome)) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return WidgetInstanceMutationResult::moduleConflict();
        }

        if ($outcome instanceof WidgetInstanceMutationResult) {
            $this->idempotency->release($claim->idempotencyKeyId, $requestHash);

            return $outcome;
        }

        /** @var WidgetInstance $outcome */
        return $onSuccess($outcome);
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
     * Materialize-before-Version-mutation gate (plan checklist: "Invoke
     * Task 15a materialization before Version mutation when the source
     * fingerprint lacks a Manifest"). Always calling materialize() (rather
     * than a separate exists-check first) is safe and idempotent -- an
     * existing exact-hash Manifest is REUSED, never rewritten (materializer
     * docblock) -- so this single call satisfies "invoke before" whether or
     * not a Manifest already existed.
     *
     * Verifies, in order: materialization succeeded (pairing/applicability
     * already checked by the caller, so only integrity/storage failures
     * remain possible here) -> exact approved Manifest (not pending/
     * revoked) -> not currently emergency-blocked (WIDGET_MANIFEST_RUNTIME_BLOCKED,
     * reserved for "some OTHER mutation" by
     * WidgetApiErrorResponse's own Task 15c docblock) -> Blob is
     * verified/readable (existence of the row IS the verification --
     * WidgetArtifactBlobRepository::put() never makes a row visible before
     * checksum verification).
     */
    private function gateManifest(string $projectName, int $widgetId, int $themeId, ?int $actorId): WidgetInstanceManifestGate
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
            return WidgetInstanceManifestGate::failed(WidgetInstanceMutationResult::materializationFailed());
        }

        if ($materialization->status === WidgetLegacyManifestMaterializationResult::STATUS_INVALID_PAIRING) {
            return WidgetInstanceManifestGate::failed(WidgetInstanceMutationResult::invalidPairing());
        }

        if ($materialization->status === WidgetLegacyManifestMaterializationResult::STATUS_NOT_APPLICABLE) {
            return WidgetInstanceManifestGate::failed(WidgetInstanceMutationResult::notApplicable());
        }

        $row = $materialization->manifestRow;
        if ($row === null || !isset($row['manifest_hash'])) {
            return WidgetInstanceManifestGate::failed(WidgetInstanceMutationResult::materializationFailed());
        }

        $manifest = WidgetArtifactManifestModel::query()
            ->where('project_name', $projectName)
            ->where('manifest_hash', $row['manifest_hash'])
            ->first();

        if ($manifest === null) {
            return WidgetInstanceManifestGate::failed(WidgetInstanceMutationResult::materializationFailed());
        }

        if ($manifest->eligibility_state !== WidgetManifestApprovalService::STATE_APPROVED) {
            return WidgetInstanceManifestGate::failed(WidgetInstanceMutationResult::approvalRequired());
        }

        if ((bool) $manifest->blocked) {
            return WidgetInstanceManifestGate::failed(WidgetInstanceMutationResult::manifestBlocked());
        }

        $blobExists = WidgetArtifactBlob::query()
            ->where('project_name', $projectName)
            ->where('id', $manifest->artifact_blob_id)
            ->exists();

        if (!$blobExists) {
            return WidgetInstanceManifestGate::failed(WidgetInstanceMutationResult::integrityFailure());
        }

        return WidgetInstanceManifestGate::ready($manifest);
    }
}

/**
 * Thrown when {@see WidgetIdempotencyService::markCommitted()} reports the
 * claim was lost (reclaimed by a concurrent caller) DURING an otherwise
 * successful mutation -- forces the enclosing DB::transaction() to roll
 * back the just-inserted Instance/Version rather than committing an
 * orphaned identity nobody's idempotency result points at. Co-located: no
 * separate file slot exists in this task's allowed_paths.
 */
final class WidgetInstanceIdempotencyLostException extends \RuntimeException
{
}

/**
 * Outcome of {@see WidgetInstanceService::gateManifest()}. Co-located
 * (see WidgetInstanceIdempotencyLostException's docblock for why).
 */
final readonly class WidgetInstanceManifestGate
{
    private function __construct(
        public bool $ready,
        public ?WidgetArtifactManifestModel $manifest = null,
        public ?WidgetInstanceMutationResult $failure = null,
    ) {
    }

    public static function ready(WidgetArtifactManifestModel $manifest): self
    {
        return new self(true, $manifest);
    }

    public static function failed(WidgetInstanceMutationResult $failure): self
    {
        return new self(false, null, $failure);
    }
}

/**
 * Outcome of every {@see WidgetInstanceService} mutation entry point.
 * Co-located (see WidgetInstanceIdempotencyLostException's docblock for
 * why).
 */
final readonly class WidgetInstanceMutationResult
{
    public const STATUS_CREATED = 'created';

    public const STATUS_UPDATED = 'updated';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_DELETED = 'deleted';

    public const STATUS_REPLAYED = 'replayed';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_INVALID_PAIRING = 'invalid_pairing';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUS_APPROVAL_REQUIRED = 'approval_required';

    public const STATUS_MATERIALIZATION_FAILED = 'materialization_failed';

    public const STATUS_MANIFEST_BLOCKED = 'manifest_blocked';

    public const STATUS_MODULE_CONFLICT = 'module_conflict';

    public const STATUS_STALE_VERSION = 'stale_version';

    public const STATUS_INSTANCE_IN_USE = 'instance_in_use';

    public const STATUS_IDEMPOTENCY_CONFLICT = 'idempotency_conflict';

    public const STATUS_IDEMPOTENCY_IN_PROGRESS = 'idempotency_in_progress';

    public const STATUS_INTEGRITY_FAILURE = 'integrity_failure';

    private function __construct(
        public string $status,
        public ?WidgetInstance $instance = null,
        public ?int $httpStatus = null,
    ) {
    }

    public static function created(WidgetInstance $instance): self
    {
        return new self(self::STATUS_CREATED, $instance, 201);
    }

    public static function updated(WidgetInstance $instance): self
    {
        return new self(self::STATUS_UPDATED, $instance, 200);
    }

    public static function archived(WidgetInstance $instance): self
    {
        return new self(self::STATUS_ARCHIVED, $instance, 200);
    }

    public static function deleted(): self
    {
        return new self(self::STATUS_DELETED, null, 200);
    }

    public static function replayed(WidgetInstance $instance, int $httpStatus): self
    {
        return new self(self::STATUS_REPLAYED, $instance, $httpStatus);
    }

    public static function notFound(): self
    {
        return new self(self::STATUS_NOT_FOUND);
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

    public static function staleVersion(): self
    {
        return new self(self::STATUS_STALE_VERSION);
    }

    public static function instanceInUse(): self
    {
        return new self(self::STATUS_INSTANCE_IN_USE);
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
