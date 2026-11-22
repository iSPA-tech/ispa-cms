<?php

declare(strict_types=1);

namespace Cms\Widget\Support\Concurrency;

use Cms\Widget\Models\WidgetInstance;
use Cms\Widget\Models\WidgetInstanceVersion;
use Cms\Widget\Models\WidgetLayout;
use Cms\Widget\Models\WidgetLayoutVersion;
use Illuminate\Support\Facades\DB;

/**
 * Canonical lock-order primitive plus the two raw version allocators (plan
 * Chunk 2 Task 13b; docs/architecture/adr-widget-mutation-idempotency.md
 * decision 6, generalizing
 * cms/Ecommerce/app/Services/Order/InventorySettlementService.php:50-52,:61-62's
 * lock-then-recheck-plus-sorted-multi-row-lock pattern to Widget's
 * two-aggregate ordering).
 *
 * Full canonical sequence (ADR decision 6) is split across two owners by
 * design:
 *
 *   idempotency claim -> Layout (FOR UPDATE) -> Instance (FOR UPDATE)
 *   -> expected-state recheck -> allocate -> audit/idempotency-result
 *
 * 1. The idempotency claim step is
 *    {@see \Cms\Widget\Support\Idempotency\WidgetIdempotencyService::claim()},
 *    called BEFORE this class, outside any aggregate lock.
 * 2. {@see self::withLocks()} performs the Layout-then-Instance locking
 *    step (sorted multi-row locking when either side has more than one id
 *    -- the InventorySettlementService precedent).
 * 3. Expected-state recheck and actual mutation are the calling action's
 *    own job (Task 16/18/20/21) -- this class holds no opinion about what a
 *    "stale expectation" means for any specific action.
 * 4. {@see self::allocateLayoutVersionPair()} and
 *    {@see self::allocateNextInstanceVersion()} are the two RAW allocators
 *    named in the plan checklist -- primitives only. Callers MUST already
 *    hold the relevant row lock (via {@see self::withLocks()}) before
 *    calling either allocator; neither method locks anything itself.
 *
 * This class deliberately implements NO action-specific race behavior:
 * different-key Instance allocation (Task 16), repin/standalone contention
 * (Task 18), publish (Task 20), rollback/publish contention (Task 21), and
 * aggregate route/race conformance (Task 24b) all own their own tests
 * against these primitives.
 */
final class WidgetAggregateLockOrder
{
    /**
     * Runs `$callback` inside one transaction, having already acquired
     * `FOR UPDATE` row locks on every named Layout id (first) then every
     * named Instance id (second) -- the canonical Layout-then-Instance
     * order. Multiple ids on either side are sorted ascending before
     * locking (deadlock-avoidance precedent:
     * InventorySettlementService.php:61-62's
     * `sortBy('product_variant_id')`). Either list may be empty for actions
     * that own no Layout (e.g. plain Instance update) or no pre-existing
     * Instance (e.g. create).
     *
     * @template TReturn
     * @param list<int> $layoutIds
     * @param list<int> $instanceIds
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    public function withLocks(array $layoutIds, array $instanceIds, callable $callback): mixed
    {
        return DB::transaction(function () use ($layoutIds, $instanceIds, $callback): mixed {
            $sortedLayoutIds = $this->sortedUniqueIds($layoutIds);
            $sortedInstanceIds = $this->sortedUniqueIds($instanceIds);

            if ($sortedLayoutIds !== []) {
                WidgetLayout::query()
                    ->whereIn('id', $sortedLayoutIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            if ($sortedInstanceIds !== []) {
                WidgetInstance::query()
                    ->whereIn('id', $sortedInstanceIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            return $callback();
        });
    }

    /**
     * Raw Layout Version pair allocator (plan: "Layout two-number pair
     * (MAX+1 release, MAX+2 draft) under FOR UPDATE"). Reads
     * `MAX(version)` across every Layout Version row for this Layout
     * (draft/superseded/release all count -- version numbers are never
     * reused) and returns the next two numbers. The caller MUST already
     * hold the Layout row lock (via {@see self::withLocks()}) before
     * calling this -- it performs no locking of its own, and it inserts no
     * rows itself (primitive only, no service semantics; Task 20/21 own the
     * actual Release/Draft row inserts).
     *
     * @return array{release: int, draft: int}
     */
    public function allocateLayoutVersionPair(int $layoutId): array
    {
        $max = (int) (WidgetLayoutVersion::query()->where('layout_id', $layoutId)->max('version') ?? 0);

        return ['release' => $max + 1, 'draft' => $max + 2];
    }

    /**
     * Raw Instance Version allocator (plan: "Instance MAX(version)+1 under
     * Instance row lock"). Caller MUST already hold the Instance row lock
     * (via {@see self::withLocks()}) before calling this.
     */
    public function allocateNextInstanceVersion(int $instanceId): int
    {
        $max = (int) (WidgetInstanceVersion::query()->where('instance_id', $instanceId)->max('version') ?? 0);

        return $max + 1;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sortedUniqueIds(array $ids): array
    {
        $unique = array_values(array_unique(array_map('intval', $ids)));
        sort($unique);

        return $unique;
    }
}
