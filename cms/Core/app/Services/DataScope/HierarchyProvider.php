<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use Illuminate\Database\Query\Builder;

/**
 * Read-only view of one node hierarchy (organisation units, categories, ...) for data-scope resolution.
 *
 * Implementations must fail closed: unknown and soft-deleted nodes do not exist, have no key, label, parent or
 * hierarchy data, and never appear in subtree queries, labels or search results.
 * Providers never decide which nodes are expansion anchors; only ExpansionPlanner does.
 */
interface HierarchyProvider
{
    /**
     * Id of the live node with the given stable key, or null when none exists.
     */
    public function idForKey(string $key): ?int;

    /**
     * Stable key of the live node, or null when it does not exist.
     */
    public function keyFor(int $id): ?string;

    /**
     * Whether the node exists and is not soft-deleted.
     */
    public function exists(int $id): bool;

    /**
     * Parent id of the live node, or null for a root, an unknown node or a flat hierarchy.
     */
    public function parentOf(int $id): ?int;

    /**
     * Whether the live node has the hierarchy data needed to expand it (for a closure table: its depth-0 self row).
     */
    public function hasHierarchyData(int $id): bool;

    /**
     * Query selecting one column `node_id`: every live node in the subtree of each anchor, anchors included.
     * An empty anchor list yields a query that returns no rows.
     *
     * @param array<int, int> $anchorIds
     */
    public function subtreeIdsQuery(array $anchorIds): Builder;

    /**
     * Labels of the live nodes among the given ids, keyed by id and sorted by id.
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    public function labels(array $ids): array;

    /**
     * Labels from the root down to the node itself; empty when the node does not exist.
     *
     * @return array<int, string>
     */
    public function trail(int $id): array;

    /**
     * Live nodes whose key or label contains the query, ordered by label.
     *
     * @return array<int, array{id: int, key: string, label: string}>
     */
    public function search(string $q, int $limit = 20): array;
}
