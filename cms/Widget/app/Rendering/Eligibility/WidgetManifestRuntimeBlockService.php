<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

use Cms\Widget\Audit\WidgetAuditWriter;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactManifestRepository;
use Illuminate\Support\Facades\DB;

/**
 * Persists the emergency Manifest runtime block/clear compare-and-swap
 * (plan Chunk 2 Task 15c, 
 * lines 946-973; architecture.md "Emergency runtime response"; ADR
 * adr-widget-project-authorization.md decision 8; D-039/D-045).
 *
 * INDEPENDENT AXIS (checklist item 6): mutates ONLY `control_version`,
 * `blocked`, `blocked_by/at`, `cleared_by/at`, `control_reason` -- the
 * eligibility attestation columns (`eligibility_state`,
 * `attestation_version`, `approved_by/at`, `revoked_by/at`,
 * `eligibility_reason`) and every canonical provenance column are
 * structurally never present in either `$changes` array below, mirroring
 * {@see WidgetManifestApprovalService}'s own canonical-immutability
 * discipline for the opposite axis. Uses
 * {@see DatabaseWidgetArtifactManifestRepository::compareAndSwapControl()}
 * (Task 13b's primitive, purpose-built for this task per that method's own
 * docblock), never `compareAndSwapAttestation()`.
 *
 * DISCLOSED: NO ADDITIONAL "CURRENT STATE" PRECONDITION, UNLIKE
 * {@see WidgetManifestApprovalService}'s dual guard. That service needed a
 * state check IN ADDITION to the attestation_version CAS because a
 * version-only check could not catch an attempt to run a transition from a
 * FROM-state the target verb does not accept (e.g. revoking a still-
 * pending, never-approved row whose version was never bumped for that
 * illegal edge) -- eligibility is a 3-state directed graph
 * (pending/approved/revoked) with edges that are only legal from specific
 * states. `blocked` here is a plain boolean with no such directed graph:
 * `block()` unconditionally means "ensure blocked=true, by me, now, for
 * this reason" and `clear()` unconditionally means "ensure blocked=false,
 * by me, now, for this reason", regardless of the row's CURRENT boolean
 * value -- there is no illegal FROM-state for either verb, and EVERY call
 * to either verb uniformly bumps the SAME `control_version` counter. This
 * makes block()/clear() safely idempotent and convergent by design (an
 * operator may re-block with an updated reason without first checking
 * current state, exactly the property an emergency control should have),
 * so plain CAS-on-`control_version` is both correct and sufficient; adding
 * a redundant state guard here would be an untested, unrequested rule with
 * no frozen error code to back it (the same restraint
 * {@see WidgetManifestApprovalService}'s own docblock already applied
 * elsewhere to a different invented gate). `WIDGET_MANIFEST_RUNTIME_BLOCKED`
 * (409) therefore stays UNUSED by this service -- it is reserved for a
 * future task (16+) that rejects some OTHER mutation (e.g. Instance
 * creation) against an already-blocked Manifest, not for this endpoint's
 * own block/clear transition.
 *
 * FIELD MUTATION SEMANTICS (mirrors {@see WidgetManifestApprovalService}'s
 * own disclosed approve()/revoke() asymmetry, mapped onto this axis):
 * `block()` ALWAYS sets `blocked_by/at` to the current actor/time and
 * PRESERVES any prior `cleared_by/at` unchanged (a blocked manifest still
 * shows who last cleared it, historically). `clear()` sets `cleared_by/at`
 * and CLEARS `blocked_by/at` to null (no longer "currently blocked" --
 * the boolean flag itself is the current-state source of truth, and
 * `blocked_by/at`'s null-ness mirrors it). `control_reason` is one column
 * reflecting the most recent transition's reason, same as
 * `eligibility_reason`; the full historical trail lives in the durable
 * AuditLog via {@see WidgetAuditWriter}, never on this row.
 *
 * NEVER CALLED WITHOUT A PRIOR {@see WidgetRuntimeControlTopologyGuard}
 * PREFLIGHT: this service has no topology awareness of its own by design
 * -- {@see \Cms\Widget\Http\Controllers\V2\Admin\WidgetManifestRuntimeBlockController}
 * checks the guard BEFORE ever calling this service, so that an
 * unavailable topology writes nothing and never even reaches this class
 * (checklist item 3: "the endpoint checks this guard before opening the
 * mutation transaction").
 */
final class WidgetManifestRuntimeBlockService
{
    /** Outcome discriminator for block()/clear()'s return shape. */
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_NOT_FOUND = 'not_found';
    public const OUTCOME_STALE = 'stale';

    public function __construct(
        private readonly DatabaseWidgetArtifactManifestRepository $repository,
        private readonly WidgetAuditWriter $auditWriter,
    ) {
    }

    /**
     * @return array{outcome: string, row: array<string, mixed>|null}
     */
    public function block(string $projectName, string $manifestHash, int $expectedControlVersion, int $actorId, string $reason): array
    {
        return $this->transition(
            $projectName,
            $manifestHash,
            $expectedControlVersion,
            blocked: true,
            actorId: $actorId,
            reason: $reason,
            auditAction: 'widget_template.block',
        );
    }

    /**
     * @return array{outcome: string, row: array<string, mixed>|null}
     */
    public function clear(string $projectName, string $manifestHash, int $expectedControlVersion, int $actorId, string $reason): array
    {
        return $this->transition(
            $projectName,
            $manifestHash,
            $expectedControlVersion,
            blocked: false,
            actorId: $actorId,
            reason: $reason,
            auditAction: 'widget_template.clear',
        );
    }

    /**
     * @return array{outcome: string, row: array<string, mixed>|null}
     */
    private function transition(
        string $projectName,
        string $manifestHash,
        int $expectedControlVersion,
        bool $blocked,
        int $actorId,
        string $reason,
        string $auditAction,
    ): array {
        $row = $this->findRow($projectName, $manifestHash);
        if ($row === null) {
            return ['outcome' => self::OUTCOME_NOT_FOUND, 'row' => null];
        }

        if ((int) $row->control_version !== $expectedControlVersion) {
            return ['outcome' => self::OUTCOME_STALE, 'row' => null];
        }

        $newVersion = $expectedControlVersion + 1;
        $now = now();

        $changes = $blocked
            ? [
                'blocked' => true,
                'control_version' => $newVersion,
                'blocked_by' => $actorId,
                'blocked_at' => $now,
                'control_reason' => $reason,
            ]
            : [
                'blocked' => false,
                'control_version' => $newVersion,
                'cleared_by' => $actorId,
                'cleared_at' => $now,
                'blocked_by' => null,
                'blocked_at' => null,
                'control_reason' => $reason,
            ];

        $updatedRow = DB::transaction(function () use (
            $projectName,
            $manifestHash,
            $expectedControlVersion,
            $changes,
            $actorId,
            $reason,
            $auditAction,
            $row,
        ): ?WidgetArtifactManifestModel {
            $success = $this->repository->compareAndSwapControl($projectName, $manifestHash, $expectedControlVersion, $changes);

            if (!$success) {
                // Concurrent stale block/clear: write NO audit row (checklist
                // item 6, mirroring the approval service's identical rule).
                return null;
            }

            $this->auditWriter->writeDurable($auditAction, [
                'actor_id' => $actorId,
                'project' => $projectName,
                'target_type' => WidgetArtifactManifestModel::class,
                // Cast to int explicitly: see WidgetManifestApprovalService's
                // identical cast for the cross-engine (mysql/pgsql string PK)
                // reason.
                'target_id' => (int) $row->id,
                'note' => $reason,
                'metadata' => [
                    'manifest_hash' => $manifestHash,
                    'control_version' => $changes['control_version'],
                ],
            ]);

            return $this->findRow($projectName, $manifestHash);
        });

        if ($updatedRow === null) {
            return ['outcome' => self::OUTCOME_STALE, 'row' => null];
        }

        return ['outcome' => self::OUTCOME_SUCCESS, 'row' => $this->safeRowSnapshot($updatedRow)];
    }

    private function findRow(string $projectName, string $manifestHash): ?WidgetArtifactManifestModel
    {
        return WidgetArtifactManifestModel::query()
            ->where('project_name', $projectName)
            ->where('manifest_hash', $manifestHash)
            ->first();
    }

    /**
     * Same safe raw-column snapshot shape as
     * {@see WidgetManifestApprovalService::safeRowSnapshot()} -- both feed
     * the same {@see \Cms\Widget\Http\Resources\V2\Admin\WidgetArtifactManifestResource::fromManifestRow()}
     * projection, which never exposes `control_reason` (checklist item 7:
     * "no sensitive reason leakage publicly").
     *
     * @return array{manifest_hash: string, project_name: string, eligibility_state: string, attestation_version: int, trust_policy_version: string, trust_lane: string, control_version: int, blocked: bool, approved_by: ?int, approved_at: mixed, revoked_by: ?int, revoked_at: mixed}
     */
    private function safeRowSnapshot(WidgetArtifactManifestModel $row): array
    {
        return [
            'manifest_hash' => (string) $row->manifest_hash,
            'project_name' => (string) $row->project_name,
            'eligibility_state' => (string) $row->eligibility_state,
            'attestation_version' => (int) $row->attestation_version,
            'trust_policy_version' => (string) $row->trust_policy_version,
            'trust_lane' => (string) $row->trust_lane,
            'control_version' => (int) $row->control_version,
            'blocked' => (bool) $row->blocked,
            'approved_by' => $row->approved_by,
            'approved_at' => $row->approved_at,
            'revoked_by' => $row->revoked_by,
            'revoked_at' => $row->revoked_at,
        ];
    }
}
