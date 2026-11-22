<?php

declare(strict_types=1);

namespace Cms\Widget\Support\Idempotency;

use Cms\Widget\Models\WidgetIdempotencyKey;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generic claim-before-mutation idempotency primitive (plan Chunk 2 Task
 * 13b; docs/architecture/adr-widget-mutation-idempotency.md decisions 1-5,
 * generalizing the codebase's only proven precedent,
 * cms/Achievements/app/Recorders/AchievementRecorderImpl.php:94-132's
 * insert-then-detect-unique-violation pattern, to a durable
 * `widget_idempotency_keys` row with processing/committed state).
 *
 * This class is database primitives ONLY: {@see self::claim()} performs a
 * unique-constrained insert (or detects and resolves an existing row) and
 * returns a {@see WidgetIdempotencyClaimResult} describing what the caller
 * must do next; it never itself creates/updates/clones/repins/publishes/
 * rolls back any Widget aggregate. Every actual Instance/Layout mutation
 * this claim guards is Task 16/18/20/21's owner (plan Task 13b checklist:
 * "Move action-specific races to their owner").
 *
 * Canonical caller sequence (ADR decision 6): claim() here FIRST, then the
 * caller locks Layout/Instance rows via
 * {@see \Cms\Widget\Support\Concurrency\WidgetAggregateLockOrder}, rechecks
 * expected state, allocates/mutates, then calls
 * {@see self::markCommitted()} (success) or {@see self::release()}
 * (rollback) -- both inside the SAME transaction as the mutation itself, so
 * a rolled-back mutation also releases its claim atomically.
 */
final class WidgetIdempotencyService
{
    /** @var \Closure(): DateTimeImmutable */
    private readonly \Closure $now;

    /**
     * @param null|\Closure(): DateTimeImmutable $now injectable clock, real
     *        time by default; tests override this to deterministically
     *        simulate TTL expiry without a real sleep.
     */
    public function __construct(?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Claim, replay, conflict, or wait-then-report-in-progress for one
     * idempotency scope (project, action, resource_scope, idempotency_key).
     *
     * - New scope -> unique insert succeeds -> {@see WidgetIdempotencyClaimResult::created()}.
     * - Existing COMMITTED row, same request_hash -> {@see WidgetIdempotencyClaimResult::replay()}
     *   (safe identity rehydration -- never re-runs the mutation).
     * - Existing row (either state), DIFFERENT request_hash -> {@see WidgetIdempotencyClaimResult::conflict()}
     *   immediately (no waiting).
     * - Existing PROCESSING row, same request_hash, and its `expires_at` has
     *   already elapsed (the original claimant crashed without committing
     *   or releasing) -> reclaimed via an optimistic compare-and-swap
     *   (`UPDATE ... WHERE state = 'processing' AND expires_at < now`,
     *   affected-rows checked) -> {@see WidgetIdempotencyClaimResult::created()}
     *   for the new claimant on success, otherwise falls through to the
     *   wait below (another process won the reclaim race).
     * - Existing PROCESSING row, same request_hash, not yet expired -> poll
     *   up to `$waitBoundMs` (never longer -- ADR decision 4) and return
     *   {@see WidgetIdempotencyClaimResult::inProgress()} if it is still
     *   processing when the bound elapses.
     */
    public function claim(
        string $projectName,
        string $action,
        string $resourceScope,
        ?int $actorId,
        string $idempotencyKey,
        string $requestHash,
        int $ttlSeconds = 86400,
        int $waitBoundMs = 200,
        int $pollIntervalMs = 20,
    ): WidgetIdempotencyClaimResult {
        $row = $this->tryInsertClaim($projectName, $action, $resourceScope, $actorId, $idempotencyKey, $requestHash, $ttlSeconds);
        if ($row !== null) {
            return WidgetIdempotencyClaimResult::created($row->id);
        }

        $existing = $this->findScoped($projectName, $action, $resourceScope, $idempotencyKey);
        if ($existing === null) {
            // Lost race: the other claimant released between our failed
            // insert and this select. One bounded retry, never unbounded.
            $row = $this->tryInsertClaim($projectName, $action, $resourceScope, $actorId, $idempotencyKey, $requestHash, $ttlSeconds);
            if ($row !== null) {
                return WidgetIdempotencyClaimResult::created($row->id);
            }

            $existing = $this->findScoped($projectName, $action, $resourceScope, $idempotencyKey);
            if ($existing === null) {
                throw new RuntimeException(
                    'Idempotency claim raced with an unresolvable concurrent delete for the same scope; caller should retry the whole request.'
                );
            }
        }

        if ($existing->state === WidgetIdempotencyKey::STATE_COMMITTED) {
            // A committed row is never "expired" in the reclaim sense -- TTL
            // only governs an ABANDONED processing claim. Hash comparison
            // decides replay vs. conflict here.
            return hash_equals($existing->request_hash, $requestHash)
                ? WidgetIdempotencyClaimResult::replay($existing)
                : WidgetIdempotencyClaimResult::conflict($existing->id);
        }

        // Still processing: an EXPIRED claim is presumed abandoned by its
        // original claimant, so expiry is checked BEFORE any hash
        // comparison -- a new caller with a genuinely DIFFERENT request may
        // still legitimately reclaim it (its own hash was never resolved).
        if ($this->isExpired($existing)) {
            $reclaimed = $this->tryReclaimExpired($existing, $requestHash, $ttlSeconds, $actorId);
            if ($reclaimed !== null) {
                return WidgetIdempotencyClaimResult::created($reclaimed->id);
            }

            // Lost the reclaim race to a concurrent claimant -- re-fetch and
            // fall through to the ordinary hash-check/wait path below on
            // whatever the row looks like now.
            $refetched = $this->findScoped($projectName, $action, $resourceScope, $idempotencyKey);
            if ($refetched === null) {
                throw new RuntimeException(
                    'Idempotency claim raced with an unresolvable concurrent delete during TTL reclaim; caller should retry the whole request.'
                );
            }
            $existing = $refetched;

            if ($existing->state === WidgetIdempotencyKey::STATE_COMMITTED) {
                return hash_equals($existing->request_hash, $requestHash)
                    ? WidgetIdempotencyClaimResult::replay($existing)
                    : WidgetIdempotencyClaimResult::conflict($existing->id);
            }
        }

        if (!hash_equals($existing->request_hash, $requestHash)) {
            return WidgetIdempotencyClaimResult::conflict($existing->id);
        }

        return $this->waitForResolution($existing, $requestHash, $waitBoundMs, $pollIntervalMs);
    }

    /**
     * Commit path: transition a claimed row from `processing` to
     * `committed`, persisting only the safe committed identity (never the
     * request body/values). Callers run this inside the same transaction as
     * the mutation it guards.
     *
     * FENCED (post-review rider): the UPDATE is scoped to
     * `state = processing AND request_hash = $requestHash`, affected-rows
     * checked. `request_hash` is the natural fencing token because TTL
     * reclaim ({@see self::tryReclaimExpired()}) REWRITES it to the new
     * claimant's hash -- so a stalled original claimant that lost its claim
     * to a reclaimer no longer matches and gets `false` back (its caller
     * must roll back its own mutation rather than commit over the
     * reclaimer's live claim). A state-only guard WITHOUT the hash would be
     * worse than none here: the reclaimed row is still `processing`, so
     * state alone matches exactly in the one real multi-claimant scenario
     * this fence exists for, while the bool return would falsely signal
     * safety. Boundary: a reclaimer that claimed with the IDENTICAL hash
     * (same canonical request retried after TTL) is indistinguishable by
     * design -- both claimants represent the same request, so either's
     * committed safe identity is a valid replay source.
     *
     * @return bool true when this caller still held the claim and the row
     *              is now committed; false when the claim was lost
     *              (reclaimed with a different hash, already committed, or
     *              released) -- the caller must treat false as a lost claim
     *              and roll back.
     */
    public function markCommitted(int $idempotencyKeyId, string $requestHash, int $httpStatus, string $resourceType, string $resourceId, ?string $resourceVersion): bool
    {
        $affected = WidgetIdempotencyKey::query()
            ->where('id', $idempotencyKeyId)
            ->where('state', WidgetIdempotencyKey::STATE_PROCESSING)
            ->where('request_hash', $requestHash)
            ->update([
                'state' => WidgetIdempotencyKey::STATE_COMMITTED,
                'http_status' => $httpStatus,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'resource_version' => $resourceVersion,
            ]);

        return $affected > 0;
    }

    /**
     * Rollback path: release an uncommitted claim so a retry can claim it
     * fresh. Scoped to `state = processing` (never deletes an
     * already-committed row, even if called by mistake) AND fenced on
     * `request_hash` exactly like {@see self::markCommitted()} -- mirrored
     * deliberately: a stalled original claimant's rollback path must not
     * delete a reclaimer's live in-flight claim either (the same
     * reclaim-stomp scenario, on the delete side).
     *
     * @return bool true when this caller's own claim was released; false
     *              when there was nothing of this caller's to release
     *              (already committed, already released, or reclaimed under
     *              a different hash).
     */
    public function release(int $idempotencyKeyId, string $requestHash): bool
    {
        $affected = WidgetIdempotencyKey::query()
            ->where('id', $idempotencyKeyId)
            ->where('state', WidgetIdempotencyKey::STATE_PROCESSING)
            ->where('request_hash', $requestHash)
            ->delete();

        return $affected > 0;
    }

    private function waitForResolution(WidgetIdempotencyKey $existing, string $requestHash, int $waitBoundMs, int $pollIntervalMs): WidgetIdempotencyClaimResult
    {
        $deadline = microtime(true) + max(0, $waitBoundMs) / 1000;
        $sleepMicroseconds = max(1000, $pollIntervalMs * 1000);

        while (microtime(true) < $deadline) {
            usleep($sleepMicroseconds);

            $refreshed = WidgetIdempotencyKey::query()->find($existing->id);
            if ($refreshed === null) {
                // Released concurrently -- nothing left to wait on. The
                // caller's retry of the whole request will claim fresh.
                break;
            }

            if (!hash_equals($refreshed->request_hash, $requestHash)) {
                return WidgetIdempotencyClaimResult::conflict($refreshed->id);
            }

            if ($refreshed->state === WidgetIdempotencyKey::STATE_COMMITTED) {
                return WidgetIdempotencyClaimResult::replay($refreshed);
            }

            $existing = $refreshed;
        }

        return WidgetIdempotencyClaimResult::inProgress($existing->id);
    }

    private function tryInsertClaim(
        string $projectName,
        string $action,
        string $resourceScope,
        ?int $actorId,
        string $idempotencyKey,
        string $requestHash,
        int $ttlSeconds,
    ): ?WidgetIdempotencyKey {
        try {
            // PostgreSQL-specific correctness requirement (discovered
            // running the real widget-site-layout-db-matrix pgsql leg, not
            // theoretical): unlike MySQL/SQLite, PostgreSQL aborts the
            // ENTIRE enclosing transaction the instant any statement fails
            // -- including an expected unique-constraint violation -- and
            // refuses to run any further statement until a ROLLBACK (or
            // ROLLBACK TO SAVEPOINT). Catching the PHP QueryException alone
            // does not un-poison the server-side transaction, so the
            // caller's subsequent findScoped() would fail with SQLSTATE
            // 25P02 even though the "violation" was fully expected and
            // handled. Wrapping the insert in its own DB::transaction()
            // makes Laravel issue a SAVEPOINT here (Connection::transaction()
            // detects it is already inside a transaction), so a caught
            // violation only rolls back to that savepoint and the outer
            // transaction (the whole request, or the calling test's
            // RefreshDatabase transaction) stays healthy for every query
            // after this method returns.
            $expiresAt = $this->addSeconds($this->currentTime(), $ttlSeconds);

            return DB::transaction(static fn () => WidgetIdempotencyKey::query()->create([
                'project_name' => $projectName,
                'action' => $action,
                'resource_scope' => $resourceScope,
                'actor_id' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'state' => WidgetIdempotencyKey::STATE_PROCESSING,
                'expires_at' => $expiresAt,
            ]));
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    private function tryReclaimExpired(WidgetIdempotencyKey $existing, string $newRequestHash, int $ttlSeconds, ?int $actorId): ?WidgetIdempotencyKey
    {
        $now = $this->currentTime();

        $affected = WidgetIdempotencyKey::query()
            ->where('id', $existing->id)
            ->where('state', WidgetIdempotencyKey::STATE_PROCESSING)
            ->where('expires_at', '<', $now)
            ->update([
                'request_hash' => $newRequestHash,
                'actor_id' => $actorId,
                'expires_at' => $this->addSeconds($now, $ttlSeconds),
            ]);

        return $affected > 0 ? WidgetIdempotencyKey::query()->find($existing->id) : null;
    }

    private function findScoped(string $projectName, string $action, string $resourceScope, string $idempotencyKey): ?WidgetIdempotencyKey
    {
        return WidgetIdempotencyKey::query()
            ->where('project_name', $projectName)
            ->where('action', $action)
            ->where('resource_scope', $resourceScope)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function isExpired(WidgetIdempotencyKey $row): bool
    {
        return $row->expires_at !== null && $row->expires_at->lt($this->currentTime());
    }

    private function currentTime(): DateTimeImmutable
    {
        return ($this->now)();
    }

    private function addSeconds(DateTimeImmutable $time, int $seconds): DateTimeImmutable
    {
        return $time->modify(sprintf('%+d seconds', $seconds));
    }

    /**
     * Cross-engine UNIQUE-violation detector -- the same pattern as
     * cms/Achievements/app/Recorders/AchievementRecorderImpl.php:124-132,
     * generalized to `widget_idem_scope_key_unique`
     * (project_name, action, resource_scope, idempotency_key).
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed') // SQLite
            || str_contains($message, 'Duplicate entry')          // MySQL
            || str_contains($message, 'duplicate key value')      // PostgreSQL
            || (string) $exception->getCode() === '23505';        // PostgreSQL SQLSTATE
    }
}

/**
 * Outcome of {@see WidgetIdempotencyService::claim()}. Co-located because
 * this task's allowed_paths has no separate value-object file slot,
 * mirroring the established co-location precedent in this module
 * ({@see \Cms\Widget\Rendering\Artifacts\WidgetArtifactIntegrityException},
 * {@see \Cms\Widget\Rendering\DataProviders\WidgetProviderInUseException}).
 *
 * Only the SAFE committed identity ever travels on `replay`/`inProgress`
 * results -- never raw request values or response bodies, matching the
 * `widget_idempotency_keys` schema's own banned-column proof
 * (WidgetSiteLayoutSchemaTest::test_widget_idempotency_keys_stores_only_safe_scoped_identity).
 */
final readonly class WidgetIdempotencyClaimResult
{
    public const STATUS_CREATED = 'created';

    public const STATUS_REPLAY = 'replay';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_IN_PROGRESS = 'in_progress';

    private function __construct(
        public string $status,
        public int $idempotencyKeyId,
        public ?int $httpStatus = null,
        public ?string $resourceType = null,
        public ?string $resourceId = null,
        public ?string $resourceVersion = null,
    ) {
    }

    public static function created(int $idempotencyKeyId): self
    {
        return new self(self::STATUS_CREATED, $idempotencyKeyId);
    }

    public static function replay(WidgetIdempotencyKey $row): self
    {
        return new self(
            self::STATUS_REPLAY,
            $row->id,
            $row->http_status,
            $row->resource_type,
            $row->resource_id,
            $row->resource_version,
        );
    }

    public static function conflict(int $idempotencyKeyId): self
    {
        return new self(self::STATUS_CONFLICT, $idempotencyKeyId);
    }

    public static function inProgress(int $idempotencyKeyId): self
    {
        return new self(self::STATUS_IN_PROGRESS, $idempotencyKeyId);
    }

    public function isCreated(): bool
    {
        return $this->status === self::STATUS_CREATED;
    }

    public function isReplay(): bool
    {
        return $this->status === self::STATUS_REPLAY;
    }

    public function isConflict(): bool
    {
        return $this->status === self::STATUS_CONFLICT;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }
}
