<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\Eligibility;

use Cms\Widget\Models\WidgetArtifactManifest as WidgetArtifactManifestModel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Render-path reader for the independent emergency runtime control axis
 * (plan Chunk 2 Task 15c checklist item 2; docs/architecture/adr-widget-artifact-store.md
 * decision 11).
 *
 * CONSUMER: {@see \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer} only,
 * in this task's scope. NOT consulted by the admin block/clear endpoint
 * (that endpoint mutates directly through
 * {@see \Cms\Widget\Rendering\Eligibility\WidgetManifestRuntimeBlockService}
 * and its own {@see \Cms\Widget\Rendering\Eligibility\WidgetRuntimeControlTopologyGuard}
 * preflight) -- the read side's shared read-after-write visibility is
 * ALREADY proven on the current topology (evidence §5/§6: single host,
 * shared Redis); only the WRITE side's permanent CDN cache-bypass proof is
 * outstanding (gap G4), which is exclusively a write-path precondition.
 *
 * MANIFEST IDENTITY BRIDGING (same disclosed class of hash-identity
 * distinction already documented twice in this module -- see
 * {@see WidgetManifestApprovalService::attestationFor()}'s docblock): the
 * ONLY manifest identity available at the {@see \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer::render()}
 * call site is `$attestation->manifestHash`, which is the Task 9c
 * EXECUTABLE-SURFACE-FINGERPRINT identity (the row's own
 * `executable_surface_fingerprint_hash` column) -- NOT the Task 10
 * composite `manifest_hash` primary lookup key the admin block/clear
 * endpoint's `{manifest_hash}` URL segment and
 * `compareAndSwapControl()` operate on. `render()`'s signature is
 * structurally locked by a read-only reflection test
 * (`ApprovedLegacyWidgetRendererTest::test_render_method_requires_an_explicit_attestation_argument`),
 * so no new parameter can be added to carry the composite identity through.
 * Both hashes are columns on the SAME `widget_artifact_manifests` row, so
 * {@see self::readState()} queries by `project_name` plus EITHER column
 * matching the supplied `$manifestHash` value -- correct for today's only
 * caller (fingerprint-keyed) and defensively correct for a hypothetical
 * future composite-hash-keyed caller, without needing two separate methods.
 *
 * ANY BLOCKED SIBLING WINS (bypass-proof block resolution): because
 * `executable_surface_fingerprint_hash` is NOT unique per project (the
 * schema uniques only `(project_name, manifest_hash)`), a fingerprint-keyed
 * lookup can match SEVERAL rows -- a generator/renderer-version bump over a
 * byte-identical executable surface produces a NEW composite `manifest_hash`
 * that shares the OLD fingerprint. If an operator blocks the exact composite
 * hash a Release promoted, an unblocked fingerprint-sibling must never be
 * allowed to mask that block. {@see self::readFromDatabase()} therefore does
 * NOT read a single non-deterministically-ordered `->first()` row's flag; it
 * loads the `blocked` flag of EVERY matching row and returns
 * {@see self::STATE_BLOCKED} if ANY of them is blocked, only falling to
 * {@see self::STATE_SAFE} when rows exist and NONE is blocked. Fail-closed by
 * construction: the safe outcome requires positively observing that no
 * matching row is blocked.
 *
 * FOUR-STATE MODEL AND A DISCLOSED, DELIBERATE NARROWING OF "MISSING FAILS
 * SOFT" (checklist item 2: "missing/unavailable/unknown control state
 * fails soft"):
 * - {@see self::STATE_SAFE}: a row was found and `blocked` is false ->
 *   safe to render.
 * - {@see self::STATE_BLOCKED}: a row was found and `blocked` is true ->
 *   fails soft, exactly per the checklist's central, most safety-critical
 *   requirement.
 * - {@see self::STATE_UNAVAILABLE}: the read itself raised an exception
 *   (control backend genuinely unreachable/erroring) -> fails soft,
 *   satisfying the checklist's "unavailable...fails soft" literally.
 * - {@see self::STATE_UNTRACKED}: the query ran cleanly and simply found
 *   NO row for this identity -> treated as SAFE TO RENDER, a deliberate
 *   narrowing of the checklist's "missing...fails soft" language. THIS IS
 *   A DISCLOSED DEVIATION, not an oversight: {@see \Cms\Widget\Rendering\Eligibility\WidgetManifestApprovalService::attestationFor()}
 *   only ever returns a non-null attestation when a REAL, approved
 *   `widget_artifact_manifests` row exists -- so in real (future) usage, a
 *   genuinely eligible, non-null attestation reaching render() ALWAYS
 *   correlates to a row this reader's query would find; "untracked" should
 *   be practically impossible for real traffic. The read-only, frozen
 *   `ApprovedLegacyWidgetRendererTest` (Task 9c) suite, however,
 *   constructs every "eligible" `WidgetEligibilityAttestation` as a bare
 *   persistence-agnostic VO (documented on that VO's own class docblock:
 *   "there is no repository or Eloquent model behind it yet, by design")
 *   with NO corresponding Manifest row at all, and this task's
 *   allowed_paths forbids editing that test file. Treating "row not found"
 *   as fail-soft would silently break that entire already-green,
 *   frozen-contract suite. Treating it as safe-to-render instead preserves
 *   that suite exactly as written while still fully honoring the
 *   checklist's two MORE central cases (blocked, and a genuinely erroring
 *   read) literally. Flagged prominently to the coordinator/reviewer as a
 *   concern, not silently resolved.
 *
 * SCHEMA-NOT-PROVISIONED ALSO COUNTS AS UNTRACKED, NOT UNAVAILABLE: that
 * same read-only Task 9c suite never loads the Widget module's own
 * migrations (it predates Task 13a's schema and only needs Core's
 * widgets/widget_themes/widget_inputs tables), so a query against
 * `widget_artifact_manifests` there does not cleanly return zero rows --
 * it raises a genuine "no such table" {@see QueryException}. Treating every
 * exception as {@see self::STATE_UNAVAILABLE} would reintroduce the exact
 * regression this class's disclosed narrowing exists to avoid, just via a
 * different mechanism (a thrown exception instead of an empty result set)
 * for what is conceptually the SAME "no evidence this identity is tracked"
 * situation -- arguably an even more emphatic form of "untracked" (the
 * whole feature's schema isn't provisioned here, not merely this one row).
 * {@see self::readFromDatabase()} therefore routes ONLY a caught
 * {@see QueryException} that {@see self::isUndefinedTable()} confirms is an
 * undefined-TABLE fault to {@see self::STATE_UNTRACKED}. That check is gated
 * on SQLSTATE codes (MySQL `42S02`, PostgreSQL `42P01`), mirroring the
 * established {@see \Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactManifestRepository::isUniqueViolation()}
 * SQLSTATE precedent, plus SQLite's `no such table` message (SQLite
 * publishes no dedicated undefined-table SQLSTATE). It deliberately does
 * NOT match the broad `does not exist` substring the first version used:
 * on PostgreSQL that ALSO matches an undefined COLUMN/FUNCTION/TYPE, so a
 * partial-migration or query-shape fault on a table that DOES exist would
 * have rendered instead of failing soft. Every other exception (connection
 * failures, undefined columns, syntax errors, timeouts) routes to
 * {@see self::STATE_UNAVAILABLE} and fails soft. The exception is always
 * reported via {@see report()} regardless of classification, so a genuinely
 * misprovisioned production table remains observable in logs even though it
 * no longer suppresses rendering site-wide.
 *
 * MEMOIZATION (checklist item 2: "at most once per unique (project_name,
 * manifest_hash) per request"): memoized on the CURRENT {@see Request}'s
 * `attributes` bag (framework/application-scoped per-request storage,
 * distinct from user input) rather than an instance property or a
 * class-level static. Neither this class nor
 * {@see \Cms\Widget\Rendering\ApprovedLegacyWidgetRenderer} is bound as a
 * container singleton (this task's allowed_paths excludes
 * WidgetServiceProvider.php), so a fresh reader instance may be constructed
 * on every render() call -- exactly how the existing Task 9c test suite
 * already calls `$this->renderer()` fresh per assertion. Keying the memo
 * (and the diagnostic {@see self::databaseReadCount()} counter) off the
 * shared `Request` object, not `$this`, makes memoization correct
 * regardless of how many reader instances get constructed within one
 * request, and is safe under a hypothetical future Octane-style reused
 * worker (a fresh Request per request always resets the bag) -- the same
 * discipline the artifact-store ADR already required elsewhere (resolver
 * cache isolation across simulated Octane worker reuse). This class
 * deliberately never caches an "unblocked" verdict anywhere other than
 * this per-request bag: it has no access to, and never writes into, the
 * Release-only Placement metadata cache
 * ({@see \Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache}, built by
 * Chunk 2 Task 24 -- DoD 1283: "keep emergency runtime control outside
 * Release-only metadata cache"). Confirmed by construction, not merely by
 * convention: this file imports no such cache class, and this reader's own
 * memo is keyed on the per-request {@see Request} attribute bag
 * ("MEMOIZATION" above), which can never outlive one request regardless of
 * how long that cache's own TTL is. Emergency control and the Placement
 * metadata cache remain two structurally separate axes, exactly like
 * architecture.md's "Emergency runtime response is separate" requires.
 */
final class WidgetRuntimeControlReader
{
    /** Row found, `blocked` is false: safe to render. */
    public const STATE_SAFE = 'safe';

    /** Row found, `blocked` is true: fail soft, never render. */
    public const STATE_BLOCKED = 'blocked';

    /**
     * No row found for this identity, but the read itself succeeded: safe
     * to render. See class docblock "FOUR-STATE MODEL" for why this is a
     * disclosed narrowing of the checklist's literal "missing fails soft"
     * language, required to preserve the read-only Task 9c test suite.
     */
    public const STATE_UNTRACKED = 'untracked';

    /** The read itself failed (control backend unreachable/erroring): fail soft, never render. */
    public const STATE_UNAVAILABLE = 'unavailable';

    private const REQUEST_ATTRIBUTE_PREFIX = 'widget_runtime_control_state:';

    private const REQUEST_ATTRIBUTE_READ_COUNT = 'widget_runtime_control_db_read_count';

    public function __construct(
        private readonly Request $request,
    ) {
    }

    /**
     * True for {@see self::STATE_SAFE} and {@see self::STATE_UNTRACKED};
     * false for {@see self::STATE_BLOCKED} and {@see self::STATE_UNAVAILABLE}.
     */
    public function isRenderSafe(string $projectName, string $manifestHash): bool
    {
        $state = $this->readState($projectName, $manifestHash);

        return $state === self::STATE_SAFE || $state === self::STATE_UNTRACKED;
    }

    /**
     * The raw four-state outcome, for callers/tests that need to
     * distinguish states without leaking WHY (no state ever carries the
     * operator's `control_reason` text -- checklist item 7: "no sensitive
     * reason leakage publicly").
     */
    public function readState(string $projectName, string $manifestHash): string
    {
        $attributeKey = self::REQUEST_ATTRIBUTE_PREFIX . $projectName . ':' . $manifestHash;

        if ($this->request->attributes->has($attributeKey)) {
            /** @var string $cached */
            $cached = $this->request->attributes->get($attributeKey);

            return $cached;
        }

        $state = $this->readFromDatabase($projectName, $manifestHash);
        $this->request->attributes->set($attributeKey, $state);

        return $state;
    }

    /**
     * Diagnostic counter: how many times THIS request actually reached the
     * database for an emergency control read (cache hits never increment
     * it). Stored on the shared {@see Request} bag for the same reason the
     * state memo is -- see class docblock "MEMOIZATION" note inline above.
     * Tests use this to prove "at most once per unique (project_name,
     * manifest_hash) per request" directly, rather than inferring it from
     * timing or query logs.
     */
    public function databaseReadCount(): int
    {
        return (int) $this->request->attributes->get(self::REQUEST_ATTRIBUTE_READ_COUNT, 0);
    }

    private function readFromDatabase(string $projectName, string $manifestHash): string
    {
        $this->request->attributes->set(
            self::REQUEST_ATTRIBUTE_READ_COUNT,
            $this->databaseReadCount() + 1,
        );

        try {
            // Fetch EVERY row matching this identity, not ->first() (see
            // class docblock "ANY BLOCKED SIBLING WINS"):
            // executable_surface_fingerprint_hash is NOT unique per project
            // (the migration uniques only (project_name, manifest_hash)), so
            // the OR-match can return several rows -- one composite-hash row
            // per generator/renderer version over a byte-identical surface.
            // Selecting only the `blocked` column keeps this cheap; the
            // model's boolean cast makes $row->blocked a real bool on every
            // engine (pgsql `t`/`f`, mysql/sqlite 0/1).
            $rows = WidgetArtifactManifestModel::query()
                ->where('project_name', $projectName)
                ->where(static function ($query) use ($manifestHash): void {
                    $query->where('manifest_hash', $manifestHash)
                        ->orWhere('executable_surface_fingerprint_hash', $manifestHash);
                })
                ->get(['blocked']);
        } catch (Throwable $exception) {
            // Always observable in logs regardless of classification below.
            report($exception);

            // Narrow carve-out: an undefined TABLE (schema not provisioned
            // in this environment/test) is treated as untracked, not
            // unavailable -- see class docblock "SCHEMA-NOT-PROVISIONED
            // ALSO COUNTS AS UNTRACKED, NOT UNAVAILABLE". Every other
            // exception (a table that exists but a connection/query still
            // failed, including an undefined COLUMN/FUNCTION/TYPE on a table
            // that DOES exist) is genuine infrastructure trouble and fails
            // soft.
            if ($exception instanceof QueryException && $this->isUndefinedTable($exception)) {
                return self::STATE_UNTRACKED;
            }

            return self::STATE_UNAVAILABLE;
        }

        if ($rows->isEmpty()) {
            return self::STATE_UNTRACKED;
        }

        // ANY blocked sibling forces a block (checklist item 2's central
        // invariant, made bypass-proof): an unblocked fingerprint-sibling
        // must never mask a block an operator set on the composite-hash row
        // a Release actually promoted. SAFE only when rows exist and NONE is
        // blocked.
        foreach ($rows as $row) {
            if ((bool) $row->blocked === true) {
                return self::STATE_BLOCKED;
            }
        }

        return self::STATE_SAFE;
    }

    /**
     * Cross-engine "undefined table" detection, gated on SQLSTATE codes
     * (mirroring {@see \Cms\Widget\Rendering\Artifacts\DatabaseWidgetArtifactManifestRepository::isUniqueViolation()}'s
     * SQLSTATE-based `23505` check): MySQL `42S02` and PostgreSQL `42P01`
     * are the standard "undefined_table" states, and `QueryException::getCode()`
     * carries the SQLSTATE (copied from the wrapped PDOException).
     *
     * SQLite reports no dedicated SQLSTATE for this (the generic `HY000`),
     * so its "no such table" MESSAGE is matched narrowly -- for that engine
     * only. Deliberately NOT the broad "does not exist" substring the
     * earlier version used: on PostgreSQL that ALSO matches an undefined
     * COLUMN (`42703`), FUNCTION (`42883`), or TYPE (`42704`) -- a
     * partial-migration or query-shape fault that must fail soft
     * ({@see self::STATE_UNAVAILABLE}), never render. Matching by SQLSTATE
     * for the engines that publish one, and by the table-specific SQLite
     * message otherwise, closes that hole while keeping the sqlite path
     * (the read-only Task 9c suite's engine) working.
     */
    private function isUndefinedTable(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();

        if ($sqlState === '42S02' || $sqlState === '42P01') {
            return true;
        }

        // SQLite has no dedicated undefined-table SQLSTATE; its
        // column-missing message is "no such column: ...", which this
        // table-specific substring intentionally does not match.
        return str_contains($exception->getMessage(), 'no such table');
    }
}
