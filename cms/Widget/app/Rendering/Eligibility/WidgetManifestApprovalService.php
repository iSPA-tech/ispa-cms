<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

use Cms\Widget\Audit\WidgetAuditWriter;
use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactManifestRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists the manifest eligibility approval state machine (plan Chunk 2
 * Task 15b, 
 * lines 922-944; architecture.md "Legacy eligibility boundary"; ADR
 * adr-widget-project-authorization.md decision 7).
 *
 * FROZEN TRANSITIONS (checklist item 2, never any other edge): `pending` ->
 * `approved`; `approved` -> `revoked`; `revoked` -> `approved` (explicit
 * reapproval of the SAME unchanged `manifest_hash`, never a new row -- Task
 * 15a, not this class, owns creating new pending Manifests). `approve()`
 * accepts both the pending->approved and revoked->approved edges in ONE
 * method because both share an identical FROM-set-agnostic mutation shape;
 * `revoke()` only ever accepts approved->revoked.
 *
 * DISCLOSED READING OF THE CHECKLIST'S REQUIREMENT LIST (item 1: "Require
 * widget_template.approve, project/applicability access, exact current
 * executable-surface fingerprint, current policy version/trust lane,
 * expected state, and expected attestation_version") against the FROZEN
 * Task 12 HTTP contract, whose request_fields for both approval routes are
 * EXACTLY `{expected_attestation_version, reason}` -- no fingerprint/
 * policy/trust-lane/state field exists to add without a request-field
 * contract change this task's scope does not authorize:
 * - `widget_template.approve`: the FormRequest's authorize() (copies the
 *   Task 15a 403-before-422 precedent verbatim).
 * - project/applicability access: the row is looked up scoped to
 *   `(project_name = authorized project, manifest_hash)`; not found in that
 *   scope (wrong project, or truly unknown hash) is 404 -- architecture.md's
 *   Status matrix: "404 = resource genuinely absent inside authorized
 *   scope", and single-project MVP means a Manifest's own `project_name`
 *   column already IS its applicability boundary for this purpose (one
 *   independent row per named project; attestation never transfers between
 *   projects).
 * - exact current executable-surface fingerprint: the `{manifest_hash}` URL
 *   segment itself IS this pin, structurally. `manifest_hash` is computed
 *   FROM the fingerprint (WidgetLegacyManifestMaterializer::computeManifestHash
 *   folds in `executable_surface_fingerprint_hash`) and canonical columns
 *   never mutate once a row exists, so approving/revoking "this exact hash"
 *   inherently operates on "this exact fingerprint" with no separate
 *   recomputation possible or needed -- this service has no widget_id/
 *   theme_id to recompute from in the first place.
 * - current policy version/trust lane: the row's OWN already-recorded
 *   `trust_policy_version`/`trust_lane` (set once, immutably, at
 *   materialization time using whatever WAS current then) are what the
 *   transition operates under and echoes back in the response. This service
 *   deliberately does NOT gate approval on the row's policy/trust-lane
 *   still matching some SEPARATE "global current policy" pointer:
 *   architecture.md only says a policy bump materializes a NEW pending
 *   Manifest for future promotion and "leaves active Releases unchanged" --
 *   it never says an old still-pending/revoked row becomes unapprovable.
 *   Inventing that extra gate would be an untested, unrequested business
 *   rule with no frozen error code to back it; not implemented.
 * - expected state: NOT a request field. Implicit per HTTP verb: `approve()`
 *   requires current `eligibility_state` in {pending, revoked}; `revoke()`
 *   requires `approved`. See {@see self::transition()} for why this check
 *   is required IN ADDITION to the attestation_version CAS (version alone
 *   cannot catch e.g. revoking a still-pending, never-approved row, whose
 *   version was never bumped away from whatever the client expects).
 * - expected attestation_version: the one literal frozen request field,
 *   enforced via {@see DatabaseWidgetArtifactManifestRepository::compareAndSwapAttestation()}
 *   (Task 13b's primitive; this class is its first real consumer).
 *
 * FIELD MUTATION SEMANTICS (undocumented by the checklist verbatim, derived
 * from the DB schema having exactly one approved_by/at and one revoked_by/at
 * column pair -- not a history array -- and WidgetArtifactManifestResource's
 * frozen fields exposing all four simultaneously): `approve()` ALWAYS sets
 * approved_by/at to the current actor/time and CLEARS revoked_by/at to null
 * (no longer "currently revoked"). `revoke()` sets revoked_by/at and
 * PRESERVES approved_by/at unchanged (a revoked manifest still shows who
 * originally approved it). `eligibility_reason` is one column reflecting the
 * most recent transition's reason; full historical trail lives in the
 * durable AuditLog via {@see WidgetAuditWriter}, not on this row.
 *
 * CAS RACE-SAFETY: the pre-transaction state-precondition read is not a
 * pessimistic lock -- it is safe WITHOUT one because `eligibility_state` and
 * `attestation_version` are the ONLY two axes this class ever mutates,
 * ALWAYS together in the same compareAndSwapAttestation() $changes array
 * (Task 15c's `control_version` is a fully independent column set/axis, per
 * DatabaseWidgetArtifactManifestRepository's own docblock). So if the state
 * this method read is stale by the time the CAS attempts its atomic
 * `UPDATE ... WHERE attestation_version = ?`, the version will ALSO have
 * changed, and the CAS itself fails (0 affected rows) -- the state read is a
 * fast, correct pre-check, never the sole authority.
 *
 * Depends on the CONCRETE {@see DatabaseWidgetArtifactManifestRepository}
 * rather than the {@see \Cms\Widget\Rendering\Artifacts\WidgetArtifactManifestRepository}
 * interface: that interface deliberately excludes the CAS primitives (Task
 * 13b's own docblock: "Beyond the frozen interface... this class exposes
 * two EXTRA primitive methods"), built specifically for THIS task to
 * consume directly -- mirroring how WidgetLegacyManifestMaterializer/
 * WidgetProjectContextResolver are already depended on concretely elsewhere
 * in this module with no interface abstraction.
 */
final class WidgetManifestApprovalService
{
    /**
     * `eligibility_state` values. Task 15a's persistence-agnostic VO
     * ({@see \Cms\Widget\Rendering\Artifacts\WidgetArtifactManifest::STATUS_PENDING})
     * only names the pending literal; APPROVED/REVOKED are introduced here
     * because this service is the sole owner of ever writing them.
     */
    public const STATE_PENDING = 'pending';
    public const STATE_APPROVED = 'approved';
    public const STATE_REVOKED = 'revoked';

    /** Outcome discriminator for approve()/revoke()'s return shape. */
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_NOT_FOUND = 'not_found';
    public const OUTCOME_STALE = 'stale';

    public function __construct(
        private readonly DatabaseWidgetArtifactManifestRepository $repository,
        private readonly WidgetAuditWriter $auditWriter,
    ) {
    }

    /**
     * pending -> approved, or revoked -> approved (explicit reapproval).
     *
     * @return array{outcome: string, row: array<string, mixed>|null}
     */
    public function approve(string $projectName, string $manifestHash, int $expectedAttestationVersion, int $actorId, ?string $reason): array
    {
        return $this->transition(
            $projectName,
            $manifestHash,
            $expectedAttestationVersion,
            [self::STATE_PENDING, self::STATE_REVOKED],
            self::STATE_APPROVED,
            $actorId,
            $reason,
            'widget_template.approve',
        );
    }

    /**
     * approved -> revoked. This is a FUTURE-PROMOTION gate only (architecture.md
     * "Legacy eligibility boundary"): it never changes bytes an already-
     * active public Release resolves; that Release's own immutable
     * `eligibility_snapshot` is untouched by this method.
     *
     * @return array{outcome: string, row: array<string, mixed>|null}
     */
    public function revoke(string $projectName, string $manifestHash, int $expectedAttestationVersion, int $actorId, ?string $reason): array
    {
        return $this->transition(
            $projectName,
            $manifestHash,
            $expectedAttestationVersion,
            [self::STATE_APPROVED],
            self::STATE_REVOKED,
            $actorId,
            $reason,
            'widget_template.revoke',
        );
    }

    /**
     * The reusable eligibility "gate" (plan Chunk 2 Task 15b design notes):
     * projects one persisted Manifest row into the Task 9c
     * {@see WidgetEligibilityAttestation} VO, so
     * {@see WidgetSiteLayoutEligibility::evaluate()} -- already frozen,
     * UNMODIFIED by this task -- can be run against REAL persisted approval
     * data. Future consumer tasks (16 Instance Versions, 19 preview, 20
     * publish, 21 rollback) call this method to obtain the attestation to
     * evaluate a freshly-recomputed fingerprint against; this task proves
     * the gate but does not wire any consumer (that is those tasks' own
     * scope, per the plan's non_goals).
     *
     * IMPORTANT NAME COLLISION (same one Task 15a already documented on
     * {@see \Cms\Widget\Rendering\Artifacts\WidgetArtifactManifest}): the
     * `$manifestHash` PARAMETER here is the Task 10 composite Manifest
     * identity (this row's own `manifest_hash` column, used to LOOK UP the
     * row) -- but the RETURNED attestation's `manifestHash` PROPERTY is the
     * Task 9c fingerprint identity (the row's `executable_surface_fingerprint_hash`
     * column), because that is what {@see WidgetEligibilityAttestation::$manifestHash}
     * documents itself as pinning ("the EXACT LegacyWidgetExecutableSurfaceAnalyzer::analyze()
     * composite hash"). These are two different hashes with the same short
     * name on two different VOs; this method deliberately bridges them
     * rather than conflating them.
     *
     * Returns null when the row does not exist OR has never been approved
     * (still `pending`, `approved_at` null) -- WidgetSiteLayoutEligibility::evaluate()
     * already produces REASON_NO_ATTESTATION for a null attestation, so this
     * method does not invent a parallel "pending" representation on a VO
     * that structurally has none.
     */
    public function attestationFor(string $projectName, string $manifestHash): ?WidgetEligibilityAttestation
    {
        $row = $this->findRow($projectName, $manifestHash);

        if ($row === null || $row->approved_at === null) {
            return null;
        }

        return new WidgetEligibilityAttestation(
            projectName: $row->project_name,
            manifestHash: $row->executable_surface_fingerprint_hash,
            policyVersion: $row->trust_policy_version,
            trustLane: $row->trust_lane,
            applicability: is_array($row->applicability) ? array_values(array_map('strval', $row->applicability)) : [],
            approvedBy: (string) $row->approved_by,
            approvedAt: DateTimeImmutable::createFromInterface($row->approved_at),
            attestationVersion: (int) $row->attestation_version,
            revoked: $row->eligibility_state === self::STATE_REVOKED,
            revokedAt: $row->revoked_at === null ? null : DateTimeImmutable::createFromInterface($row->revoked_at),
        );
    }

    /**
     * @param list<string> $validFromStates
     * @return array{outcome: string, row: array<string, mixed>|null}
     */
    private function transition(
        string $projectName,
        string $manifestHash,
        int $expectedAttestationVersion,
        array $validFromStates,
        string $toState,
        int $actorId,
        ?string $reason,
        string $auditAction,
    ): array {
        $row = $this->findRow($projectName, $manifestHash);
        if ($row === null) {
            return ['outcome' => self::OUTCOME_NOT_FOUND, 'row' => null];
        }

        // Required IN ADDITION to the attestation_version CAS below (see
        // class docblock "expected state" + "CAS RACE-SAFETY"): a
        // version-only check cannot catch an attempt to run this transition
        // from a state it does not accept (e.g. revoking a still-pending
        // row whose version was never bumped away from what the client
        // expects).
        if (!in_array($row->eligibility_state, $validFromStates, true)) {
            return ['outcome' => self::OUTCOME_STALE, 'row' => null];
        }

        if ((int) $row->attestation_version !== $expectedAttestationVersion) {
            return ['outcome' => self::OUTCOME_STALE, 'row' => null];
        }

        $newVersion = $expectedAttestationVersion + 1;
        $now = now();

        $changes = $toState === self::STATE_APPROVED
            ? [
                'eligibility_state' => self::STATE_APPROVED,
                'attestation_version' => $newVersion,
                'approved_by' => $actorId,
                'approved_at' => $now,
                'revoked_by' => null,
                'revoked_at' => null,
                'eligibility_reason' => $reason,
            ]
            : [
                'eligibility_state' => self::STATE_REVOKED,
                'attestation_version' => $newVersion,
                'revoked_by' => $actorId,
                'revoked_at' => $now,
                'eligibility_reason' => $reason,
            ];

        $updatedRow = DB::transaction(function () use (
            $projectName,
            $manifestHash,
            $expectedAttestationVersion,
            $changes,
            $actorId,
            $reason,
            $auditAction,
            $row,
        ): ?WidgetArtifactManifestModel {
            $success = $this->repository->compareAndSwapAttestation($projectName, $manifestHash, $expectedAttestationVersion, $changes);

            if (!$success) {
                // Concurrent stale approve/revoke: write NO audit row (checklist
                // item 2). Returning null here still lets this closure commit
                // (nothing was mutated -- 0 affected rows is not an error), it
                // simply signals the outer caller that the CAS lost its race.
                return null;
            }

            $this->auditWriter->writeDurable($auditAction, [
                'actor_id' => $actorId,
                'project' => $projectName,
                'target_type' => WidgetArtifactManifestModel::class,
                // Cast to int explicitly: PDO_mysql (and pgsql) can hand back
                // integer primary keys as PHP strings, and
                // WidgetAuditWriter::resolveTarget()'s explicit target_id
                // branch requires a strict is_int() -- an un-cast string key
                // would throw INSIDE this transaction and roll the whole
                // approval back with a 500. The cross-engine CI matrix runs
                // mysql/pgsql, where this is not hypothetical.
                'target_id' => (int) $row->id,
                'note' => $reason,
                'metadata' => [
                    'manifest_hash' => $manifestHash,
                    'attestation_version' => $changes['attestation_version'],
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
     * Safe raw-column snapshot, mirroring
     * WidgetLegacyManifestMaterializer::safeRowSnapshot()'s established
     * shape (Task 15a): raw column names, HTTP-agnostic; the controller
     * layer renames `trust_policy_version` -> `policy_version` for the
     * response, matching WidgetManifestMaterializationController's own
     * precedent.
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
