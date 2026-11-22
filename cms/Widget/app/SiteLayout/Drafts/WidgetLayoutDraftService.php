<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Drafts;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Cms\Widget\Models\WidgetPlacement;
use Cms\Widget\SiteLayout\Registry\WidgetRegistryContractSnapshot;
use Cms\Widget\SiteLayout\Resolution\WidgetPlacementResolver;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Owns `PUT /layouts/{uuid}/draft`, the deterministic full-document Draft
 * Save (plan Chunk 2 Task 17, lines 1020-1051; architecture.md "Draft
 * update").
 *
 * Sequence (architecture.md, steps 1-6 -- deliberately split around the
 * Layout row lock so the lock is held only for the actual mutation, not for
 * the potentially-slow target-resolver calls the first step performs):
 *
 * 1. Resolve the Layout by (project, uuid); an absent/cross-project uuid is
 *    404 before anything else is validated.
 * 2. Validate the COMPLETE Placement document OUTSIDE any lock: every
 *    slot_key is a registered Slot, every scope_type is one that Slot
 *    supports, every template scope_key/target scope target_type is a
 *    registered Template/Target Context, every target resolves (denied ->
 *    403, absent -> 404) in the authorized project, every pinned
 *    (widget_instance_uuid, widget_instance_version) pair resolves to a
 *    real Instance Version in the authorized project, and every `rules` is
 *    exactly `{}` (architecture.md: "MVP rejects rules != {} with 422").
 * 3. Reject a cardinality conflict: more than one row sharing a `single`/
 *    `replace` Slot's exact scope identity (architecture.md "Effective
 *    resolution": "allow at most one active Placement per exact scope
 *    identity").
 * 4. Normalize positions: group by (slot_key, scope_identity), stable-sort
 *    each group by SUBMITTED position, reassign 0..n-1.
 * 5. Lock the `widget_layouts` row FOR UPDATE via
 *    {@see WidgetAggregateLockOrder}, recheck the expected current draft
 *    (see this class's own {@see self::save()} docblock for the
 *    `expected_current_draft_id` interpretation), and -- only when NOT
 *    stale -- replace the Draft's Placements atomically, recompute
 *    `registry_contracts`/hash from the NORMALIZED reference set through
 *    {@see WidgetRegistryContractSnapshot} in the SAME transaction/lock,
 *    and increment `lock_version`. A stale check touches neither
 *    Placements nor the registry snapshot.
 *
 * Mutates the ONE current Draft row IN PLACE. Never allocates a
 * {@see WidgetLayoutVersion} row, never supersedes the Draft, never creates
 * a fresh Draft -- only initial Layout creation
 * ({@see WidgetLayoutBootstrapService}), publish, and rollback do that
 * (Tasks 20/21, not this class).
 *
 * Deliberately does NOT consult {@see \App\Contracts\Modules\ModuleLifecycleBarrier}
 * (coordinator brief, Task 17: reserved for Instance-Version creation,
 * destructive Definition/Theme mutation, and Safe Import) and does NOT use
 * {@see \Cms\Widget\Support\Idempotency\WidgetIdempotencyService} (frozen
 * contract fixture: this route's `idempotency_scope` is `null` with the
 * explicit note "optimistic lock_version recheck under row lock, NOT the
 * idempotency-key header protocol").
 */
final class WidgetLayoutDraftService
{
    public function __construct(
        private readonly WidgetAggregateLockOrder $lockOrder,
        private readonly WidgetRegistryContractSnapshot $registrySnapshot,
        private readonly WidgetSlotRegistry $slots,
        private readonly WidgetTemplateContextRegistry $templateContexts,
        private readonly WidgetTargetContextRegistry $targetContexts,
    ) {
    }

    /**
     * DISCLOSED CONTRACT INTERPRETATION: `$expectedCurrentDraftId` is
     * compared against the Draft's VERSION NUMBER, not its raw internal
     * `id`, despite the frozen request field's literal name
     * (`expected_current_draft_id`). `widget_layout_versions` carries no
     * uuid of its own, and the frozen `WidgetLayoutResource.current_draft`
     * nested field list is exactly `["version", "lock_version"]` -- no raw
     * row id is EVER exposed to a client anywhere in this frozen contract,
     * so a client literally cannot supply one. This mirrors the identical
     * shape-of-problem this module already resolved once:
     * {@see \Cms\Widget\Http\Requests\WidgetInstance\UpdateWidgetInstanceRequest}'s
     * `expected_current_version` is also a VERSION NUMBER for the exact
     * same reason (`widget_instance_versions` also carries no uuid). See
     * {@see \Cms\Widget\Tests\Feature\Http\WidgetLayoutDraftApiTest}'s class
     * docblock for the full disclosure.
     *
     * @param list<array{
     *     slot_key: string,
     *     scope_type: string,
     *     scope_key: string|null,
     *     target_type: string|null,
     *     target_id: string|null,
     *     widget_instance_uuid: string,
     *     widget_instance_version: int,
     *     position: int,
     *     enabled: bool,
     *     rules: array<string, mixed>
     * }> $placements
     */
    public function save(
        string $projectName,
        string $layoutUuid,
        int $expectedCurrentDraftId,
        int $expectedLockVersion,
        array $placements,
    ): WidgetLayoutDraftSaveResult {
        $layout = $this->findByUuid($projectName, $layoutUuid);
        if ($layout === null) {
            return WidgetLayoutDraftSaveResult::layoutNotFound();
        }

        $context = new AuthorizedProjectContext($projectName);

        $validation = $this->validateAndResolvePlacements($context, $projectName, $placements);
        if ($validation->failure !== null) {
            return $validation->failure;
        }

        $cardinalityViolation = $this->cardinalityViolation($validation->rows);
        if ($cardinalityViolation !== null) {
            return WidgetLayoutDraftSaveResult::validationFailed(
                "Slot '{$cardinalityViolation}' does not allow more than one active Placement for the same scope."
            );
        }

        $normalizedRows = $this->normalizePositions($validation->rows);

        return $this->lockOrder->withLocks([$layout->id], [], function () use ($layout, $expectedCurrentDraftId, $expectedLockVersion, $normalizedRows): WidgetLayoutDraftSaveResult {
            $lockedLayout = WidgetLayout::query()->findOrFail($layout->id);
            $draft = $lockedLayout->current_draft_version_id === null
                ? null
                : WidgetLayoutVersion::query()->find($lockedLayout->current_draft_version_id);

            if ($draft === null || $draft->version !== $expectedCurrentDraftId || $draft->lock_version !== $expectedLockVersion) {
                return WidgetLayoutDraftSaveResult::stale();
            }

            WidgetPlacement::query()->where('layout_version_id', $draft->id)->delete();

            foreach ($normalizedRows as $row) {
                WidgetPlacement::query()->create([
                    'project_name' => $draft->project_name,
                    'layout_version_id' => $draft->id,
                    'widget_instance_version_id' => $row['widget_instance_version_id'],
                    'slot_key' => $row['slot_key'],
                    'scope_type' => $row['scope_type'],
                    'scope_identity' => $row['scope_identity'],
                    'scope_key' => $row['scope_key'],
                    'target_type' => $row['target_type'],
                    'target_id' => $row['target_id'],
                    'position' => $row['position'],
                    'enabled' => $row['enabled'],
                    'rules' => $row['rules'],
                ]);
            }

            $referenceSet = $this->referenceSetFrom($normalizedRows);
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

            return WidgetLayoutDraftSaveResult::saved(
                $draft->fresh()->load('placements.instanceVersion.instance')
            );
        });
    }

    /**
     * `GET /layouts/{uuid}/draft` (disclosed post-freeze contract evolution,
     * routes 30->31): the pure-read counterpart to {@see self::save()},
     * reusing this SAME service (and its {@see self::findByUuid()} helper)
     * rather than a separate query path. Returns `null` when the Layout
     * itself is absent/cross-project, or -- defensively, since Bootstrap
     * always seeds an initial Draft and every later lifecycle step
     * (publish/rollback) always leaves a fresh one in place -- when the
     * resolved Layout somehow has no current Draft pointer yet. Either case
     * renders the SAME 404 at the controller; this method does not
     * distinguish them, mirroring
     * {@see \Cms\Widget\Http\Controllers\V2\Admin\WidgetLayoutBootstrapController::show()}'s
     * identical nullable-return precedent for a plain identity read. Eager
     * loads `placements.instanceVersion.instance.currentVersion` so the
     * controller's own `placementPayload()}` projection (shared verbatim
     * with {@see self::save()}'s success response) AND its
     * `instanceFingerprints()` projection (each referenced Instance's CURRENT
     * version number feeds the exposed repin usage_fingerprint) never N+1.
     */
    public function findCurrentDraft(string $projectName, string $uuid): ?WidgetLayoutVersion
    {
        $layout = $this->findByUuid($projectName, $uuid);
        if ($layout === null || $layout->current_draft_version_id === null) {
            return null;
        }

        return WidgetLayoutVersion::query()
            ->where('id', $layout->current_draft_version_id)
            ->with('placements.instanceVersion.instance.currentVersion')
            ->first();
    }

    private function findByUuid(string $projectName, string $uuid): ?WidgetLayout
    {
        return WidgetLayout::query()
            ->where('project_name', $projectName)
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * Validates and resolves every incoming placement OUTSIDE any lock.
     * Stops at the first failure -- the whole document is rejected atomically
     * (nothing is partially applied), matching "full-document save".
     *
     * @param list<array<string, mixed>> $placements
     */
    private function validateAndResolvePlacements(AuthorizedProjectContext $context, string $projectName, array $placements): WidgetLayoutDraftValidationOutcome
    {
        $resolved = [];

        foreach ($placements as $placement) {
            $slotKey = (string) $placement['slot_key'];
            $scopeType = (string) $placement['scope_type'];
            $scopeKey = $placement['scope_key'] ?? null;
            $targetType = $placement['target_type'] ?? null;
            $targetId = $placement['target_id'] ?? null;

            $declaration = $this->slots->declarationFor($slotKey);
            if ($declaration === null) {
                return WidgetLayoutDraftValidationOutcome::failed(
                    WidgetLayoutDraftSaveResult::validationFailed("Unknown Slot key '{$slotKey}'.")
                );
            }

            if (!in_array($scopeType, $declaration->supportedScopeTypes, true)) {
                return WidgetLayoutDraftValidationOutcome::failed(
                    WidgetLayoutDraftSaveResult::validationFailed("Slot '{$slotKey}' does not support scope type '{$scopeType}'.")
                );
            }

            if ($scopeType === WidgetPlacementResolver::SCOPE_TEMPLATE) {
                if (!is_string($scopeKey) || !$this->templateContexts->isRegistered($scopeKey)) {
                    return WidgetLayoutDraftValidationOutcome::failed(
                        WidgetLayoutDraftSaveResult::validationFailed("Unknown Template Context key '{$scopeKey}'.")
                    );
                }
            } else {
                $scopeKey = null;
            }

            if ($scopeType === WidgetPlacementResolver::SCOPE_TARGET) {
                if (!is_string($targetType) || !is_string($targetId)) {
                    return WidgetLayoutDraftValidationOutcome::failed(
                        WidgetLayoutDraftSaveResult::validationFailed('A target-scoped Placement requires target_type and target_id.')
                    );
                }

                if (!$this->targetContexts->isRegistered($targetType)) {
                    return WidgetLayoutDraftValidationOutcome::failed(
                        WidgetLayoutDraftSaveResult::validationFailed("Unknown Target Context type '{$targetType}'.")
                    );
                }

                try {
                    $this->targetContexts->resolve($targetType, $context, $targetId);
                } catch (ModelNotFoundException $exception) {
                    return WidgetLayoutDraftValidationOutcome::failed(WidgetLayoutDraftSaveResult::targetNotFound($exception->getMessage()));
                } catch (AuthorizationException $exception) {
                    return WidgetLayoutDraftValidationOutcome::failed(WidgetLayoutDraftSaveResult::targetDenied($exception->getMessage()));
                }
            } else {
                $targetType = null;
                $targetId = null;
            }

            $rules = $placement['rules'] ?? [];
            if (!is_array($rules) || $rules !== []) {
                return WidgetLayoutDraftValidationOutcome::failed(
                    WidgetLayoutDraftSaveResult::validationFailed("Slot '{$slotKey}': rules must be an empty object in MVP.")
                );
            }

            $instanceVersion = $this->resolveInstanceVersion(
                $projectName,
                (string) $placement['widget_instance_uuid'],
                (int) $placement['widget_instance_version'],
            );
            if ($instanceVersion === null) {
                return WidgetLayoutDraftValidationOutcome::failed(
                    WidgetLayoutDraftSaveResult::validationFailed('Unknown or inaccessible widget_instance_uuid/widget_instance_version pairing.')
                );
            }

            $resolved[] = [
                'slot_key' => $slotKey,
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'scope_identity' => WidgetPlacementResolver::scopeIdentity($scopeType, $scopeKey, $targetType, $targetId),
                'widget_instance_version_id' => $instanceVersion->id,
                'provider_refs' => is_array($instanceVersion->provider_refs) ? $instanceVersion->provider_refs : [],
                'position' => (int) $placement['position'],
                'enabled' => (bool) $placement['enabled'],
                'rules' => [],
                'cardinality' => $declaration->cardinality,
            ];
        }

        return WidgetLayoutDraftValidationOutcome::ok($resolved);
    }

    private function resolveInstanceVersion(string $projectName, string $instanceUuid, int $version): ?WidgetInstanceVersion
    {
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

    /**
     * Groups the resolved rows by (slot_key, scope_identity) and returns the
     * first Slot key with more than one row whose declared cardinality is
     * NOT `stack` (architecture.md "Effective resolution": "allow at most
     * one active Placement per exact scope identity").
     *
     * @param list<array<string, mixed>> $rows
     */
    private function cardinalityViolation(array $rows): ?string
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['slot_key'] . '|' . $row['scope_identity'];
            $groups[$key][] = $row;
        }

        foreach ($groups as $group) {
            if (count($group) > 1 && $group[0]['cardinality'] !== WidgetSlotDefinition::CARDINALITY_STACK) {
                return $group[0]['slot_key'];
            }
        }

        return null;
    }

    /**
     * Groups by (slot_key, scope_identity), stable-sorts each group by the
     * SUBMITTED position, and reassigns sequential 0..n-1 positions.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizePositions(array $rows): array
    {
        $groups = [];
        foreach ($rows as $index => $row) {
            $key = $row['slot_key'] . '|' . $row['scope_identity'];
            $groups[$key][] = ['row' => $row, 'original_index' => $index];
        }

        $normalized = [];
        foreach ($groups as $group) {
            usort($group, static function (array $a, array $b): int {
                return $a['row']['position'] <=> $b['row']['position']
                    ?: $a['original_index'] <=> $b['original_index'];
            });

            foreach (array_values($group) as $position => $entry) {
                $row = $entry['row'];
                $row['position'] = $position;
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * Derives the reference set {@see WidgetRegistryContractSnapshot::build()}
     * needs from the NORMALIZED Placement rows: every referenced Slot key,
     * every referenced Template Context key, every referenced Target
     * Context type, and every pinned Instance Version's provider references
     * (architecture.md line 1041: "recompute canonical registry_contracts/
     * hash ... from the normalized referenced Slots, Template Contexts,
     * Target Contexts, and providers").
     *
     * @param list<array<string, mixed>> $rows
     * @return array{
     *     slot_keys: list<string>,
     *     template_context_keys: list<string>,
     *     target_context_types: list<string>,
     *     provider_refs: list<array{key: string, version: int}>
     * }
     */
    private function referenceSetFrom(array $rows): array
    {
        $slotKeys = [];
        $templateKeys = [];
        $targetTypes = [];
        $providerRefs = [];
        $seenProviderRefs = [];

        foreach ($rows as $row) {
            $slotKeys[$row['slot_key']] = true;

            if ($row['scope_key'] !== null) {
                $templateKeys[$row['scope_key']] = true;
            }

            if ($row['target_type'] !== null) {
                $targetTypes[$row['target_type']] = true;
            }

            foreach ($row['provider_refs'] as $ref) {
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
}

/**
 * Outcome of {@see WidgetLayoutDraftService::validateAndResolvePlacements()}.
 * Co-located: this task's file list has no separate slot for it, mirroring
 * this module's established co-location precedent.
 */
final readonly class WidgetLayoutDraftValidationOutcome
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    private function __construct(
        public array $rows,
        public ?WidgetLayoutDraftSaveResult $failure,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function ok(array $rows): self
    {
        return new self($rows, null);
    }

    public static function failed(WidgetLayoutDraftSaveResult $failure): self
    {
        return new self([], $failure);
    }
}

/**
 * Outcome of {@see WidgetLayoutDraftService::save()}. Co-located (see
 * {@see WidgetLayoutDraftValidationOutcome}'s docblock for why).
 */
final readonly class WidgetLayoutDraftSaveResult
{
    public const STATUS_SAVED = 'saved';

    public const STATUS_LAYOUT_NOT_FOUND = 'layout_not_found';

    public const STATUS_STALE = 'stale';

    public const STATUS_VALIDATION_FAILED = 'validation_failed';

    public const STATUS_TARGET_DENIED = 'target_denied';

    public const STATUS_TARGET_NOT_FOUND = 'target_not_found';

    private function __construct(
        public string $status,
        public ?WidgetLayoutVersion $draft = null,
        public ?string $message = null,
    ) {
    }

    public static function saved(WidgetLayoutVersion $draft): self
    {
        return new self(self::STATUS_SAVED, $draft);
    }

    public static function layoutNotFound(): self
    {
        return new self(self::STATUS_LAYOUT_NOT_FOUND);
    }

    public static function stale(): self
    {
        return new self(self::STATUS_STALE);
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
}
