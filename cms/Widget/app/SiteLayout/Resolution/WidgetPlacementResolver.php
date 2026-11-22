<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Resolution;

use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use InvalidArgumentException;

/**
 * Deterministic effective-Placement resolver (plan Chunk 2 Task 17, lines
 * 1020-1051; architecture.md "Effective resolution").
 *
 * Pure and persistence-agnostic: takes an already-loaded candidate list for
 * ONE Slot (any scope; the caller groups by `slot_key` first -- this class
 * has no opinion about which Slot a candidate belongs to) and returns the
 * effective, ordered subset for one Render Context (a specific Template
 * Context key and/or target). No database, no Eloquent, no container.
 *
 * Two precedence families, verbatim from architecture.md:
 * - `single`/`replace`: target scope wins when present, else template, else
 *   global; at most one row is ever returned. Storage never allows more
 *   than one row per exact (slot, scope_identity) pair for a non-stack Slot
 *   ({@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService} enforces
 *   that at Draft-Save time), so this method's "lowest position wins within
 *   a tier" behavior is a defensive fallback, never the real deciding rule.
 * - `stack`: every matching scope concatenates in the fixed order (global,
 *   then template, then target), each internally ordered by `position`.
 *
 * {@see self::scopeIdentity()} is the SAME canonical `scope_identity`
 * string-building rule architecture.md's "widget_placements" section
 * defines (`global`; `template:<key>`; `target:<type>:<id>`).
 * {@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService} reuses this
 * static method verbatim rather than adding a second private copy, since it
 * needs the identical string both to normalize incoming Draft Save rows and
 * to detect a cardinality conflict (more than one row sharing a `single`/
 * `replace` Slot's exact scope identity).
 */
final class WidgetPlacementResolver
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_TEMPLATE = 'template';

    public const SCOPE_TARGET = 'target';

    /**
     * @throws InvalidArgumentException on an unknown scope type.
     */
    public static function scopeIdentity(string $scopeType, ?string $scopeKey, ?string $targetType, ?string $targetId): string
    {
        return match ($scopeType) {
            self::SCOPE_GLOBAL => self::SCOPE_GLOBAL,
            self::SCOPE_TEMPLATE => self::SCOPE_TEMPLATE . ':' . $scopeKey,
            self::SCOPE_TARGET => self::SCOPE_TARGET . ':' . $targetType . ':' . $targetId,
            default => throw new InvalidArgumentException("Unknown scope type '{$scopeType}'."),
        };
    }

    /**
     * @param list<WidgetResolvedPlacementCandidate> $candidates every stored
     *        candidate for ONE Slot (any scope) -- caller pre-filters by
     *        `slot_key`.
     * @return list<WidgetResolvedPlacementCandidate> the effective,
     *         position-ordered set for one Render Context.
     */
    public function resolve(
        array $candidates,
        string $cardinality,
        ?string $templateContextKey,
        ?string $targetType,
        ?string $targetId,
    ): array {
        $global = $this->ordered($this->matching($candidates, self::SCOPE_GLOBAL, null, null, null));
        $template = $templateContextKey === null
            ? []
            : $this->ordered($this->matching($candidates, self::SCOPE_TEMPLATE, $templateContextKey, null, null));
        $target = ($targetType === null || $targetId === null)
            ? []
            : $this->ordered($this->matching($candidates, self::SCOPE_TARGET, null, $targetType, $targetId));

        if ($cardinality === WidgetSlotDefinition::CARDINALITY_STACK) {
            return [...$global, ...$template, ...$target];
        }

        // single/replace: target overrides template overrides global.
        if ($target !== []) {
            return [$target[0]];
        }

        if ($template !== []) {
            return [$template[0]];
        }

        if ($global !== []) {
            return [$global[0]];
        }

        return [];
    }

    /**
     * @param list<WidgetResolvedPlacementCandidate> $candidates
     * @return list<WidgetResolvedPlacementCandidate>
     */
    private function matching(array $candidates, string $scopeType, ?string $scopeKey, ?string $targetType, ?string $targetId): array
    {
        return array_values(array_filter(
            $candidates,
            static fn (WidgetResolvedPlacementCandidate $candidate): bool => $candidate->scopeType === $scopeType
                && $candidate->scopeKey === $scopeKey
                && $candidate->targetType === $targetType
                && $candidate->targetId === $targetId,
        ));
    }

    /**
     * @param list<WidgetResolvedPlacementCandidate> $rows
     * @return list<WidgetResolvedPlacementCandidate>
     */
    private function ordered(array $rows): array
    {
        usort($rows, static fn (WidgetResolvedPlacementCandidate $a, WidgetResolvedPlacementCandidate $b): int => $a->position <=> $b->position);

        return array_values($rows);
    }

    /**
     * Maps ONE persisted candidate row's plain scalar fields into
     * {@see WidgetResolvedPlacementCandidate} (plan Chunk 2 Task 24, DoD
     * 1280). The single conversion point
     * {@see \Cms\Widget\SiteLayout\WidgetSlotRuntimeService}'s production
     * DB-backed resolution ("cold" path: a fresh `WidgetPlacement` row's
     * attributes) and {@see \Cms\Widget\SiteLayout\Resolution\WidgetPlacementCache}'s
     * cached-metadata rehydration ("warm" path: the same shape decoded from
     * JSON) both use, so the mapping cannot drift between the two -- this
     * is what makes it "not a standalone class used only by unit tests"
     * (DoD 1280) despite living on this otherwise-pure resolver.
     *
     * Still pure: no database, no Eloquent model required as input -- only
     * the plain scalar fields both representations (an Eloquent attributes
     * array, decoded cache JSON) already share identically.
     *
     * `identity` carries the `widget_instance_version_id` -- the ONLY thing
     * a caller needs to actually render the winning candidate.
     *
     * @param array{scope_type: string, scope_key: ?string, target_type: ?string, target_id: ?string, position: int, widget_instance_version_id: int} $row
     */
    public static function candidateFromRow(array $row): WidgetResolvedPlacementCandidate
    {
        return new WidgetResolvedPlacementCandidate(
            scopeType: $row['scope_type'],
            scopeKey: $row['scope_key'] ?? null,
            targetType: $row['target_type'] ?? null,
            targetId: $row['target_id'] ?? null,
            position: (int) $row['position'],
            identity: (int) $row['widget_instance_version_id'],
        );
    }
}

/**
 * One resolvable candidate row, decoupled from the {@see \Cms\Widget\Models\WidgetPlacement}
 * Eloquent model so {@see WidgetPlacementResolver} stays a pure function
 * over plain data (co-located: this task's file list has no separate DTO
 * slot for it, mirroring the established co-location precedent in this
 * module, e.g. {@see \Cms\Widget\Support\Idempotency\WidgetIdempotencyClaimResult}).
 * `identity` is an opaque caller-supplied payload (e.g. a Placement uuid or
 * pinned Instance Version id) this class never inspects.
 */
final readonly class WidgetResolvedPlacementCandidate
{
    public function __construct(
        public string $scopeType,
        public ?string $scopeKey,
        public ?string $targetType,
        public ?string $targetId,
        public int $position,
        public mixed $identity = null,
    ) {
    }
}
