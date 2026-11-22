<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use Illuminate\Database\Query\Builder;

/**
 * Result of ExpansionPlanner: the held nodes kept apart from the expansion anchors derived from them.
 *
 * Visible nodes = selfNodeIds ∪ subtree of every anchor (anchors included).
 */
final readonly class ExpansionPlan
{
    /**
     * @param array<int, HeldNode> $held Every held node passed to the planner, unchanged.
     * @param array<int, int> $selfNodeIds Live nodes visible on their own (sorted, unique).
     * @param array<int, int> $anchorIds Nodes whose subtree is visible (sorted, unique).
     */
    public function __construct(
        public array $held,
        public array $selfNodeIds,
        public array $anchorIds,
    ) {
    }

    /**
     * Query selecting one column `node_id` with every visible node (may contain duplicates; wrap with DISTINCT
     * or use it inside `whereIn` as needed). Runs on the provider's connection.
     */
    public function visibleIdsQuery(HierarchyProvider $hierarchy): Builder
    {
        $query = $hierarchy->subtreeIdsQuery($this->anchorIds);

        foreach ($this->selfNodeIds as $id) {
            // Ids are ints from the planner, so embedding them as literals is safe.
            $query->unionAll($query->newQuery()->selectRaw((int) $id . ' as node_id'));
        }

        return $query->getConnection()->query()->fromSub($query, 'visible_nodes')->select('node_id');
    }

    /**
     * Sorted, unique ids of every visible node.
     *
     * @return array<int, int>
     */
    public function visibleIds(HierarchyProvider $hierarchy): array
    {
        $ids = array_map('intval', $this->visibleIdsQuery($hierarchy)->distinct()->pluck('node_id')->all());
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
