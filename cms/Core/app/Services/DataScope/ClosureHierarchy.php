<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HierarchyProvider over a node table with `parent_id` plus a closure table (ancestor, descendant, depth) that
 * holds a depth-0 self row for every node.
 *
 * Soft-deleted nodes do not exist: they are excluded from lookups, labels, trails, search and subtree queries.
 * A node without its depth-0 closure row has no hierarchy data, so the planner treats it as `self`.
 */
class ClosureHierarchy implements HierarchyProvider
{
    /**
     * @param string $nodeTable Node table.
     * @param string $closureTable Closure table.
     * @param string $idColumn Integer primary key column of the node table.
     * @param string $parentColumn Parent id column of the node table.
     * @param string $keyColumn Stable key column (code, uuid, ...).
     * @param string $labelColumn Human-readable label column.
     * @param string|null $deletedAtColumn Soft-delete timestamp column; null when the node table has none.
     * @param string $ancestorColumn Ancestor id column of the closure table.
     * @param string $descendantColumn Descendant id column of the closure table.
     * @param string $depthColumn Depth column of the closure table (0 for the self row).
     * @param string|null $connection Database connection name; null for the default connection.
     */
    public function __construct(
        protected string $nodeTable,
        protected string $closureTable,
        protected string $idColumn = 'id',
        protected string $parentColumn = 'parent_id',
        protected string $keyColumn = 'code',
        protected string $labelColumn = 'name',
        protected ?string $deletedAtColumn = 'deleted_at',
        protected string $ancestorColumn = 'ancestor_id',
        protected string $descendantColumn = 'descendant_id',
        protected string $depthColumn = 'depth',
        protected ?string $connection = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function idForKey(string $key): ?int
    {
        $id = $this->live()->where($this->keyColumn, $key)->value($this->idColumn);

        return $id === null ? null : (int) $id;
    }

    /**
     * {@inheritDoc}
     */
    public function keyFor(int $id): ?string
    {
        $key = $this->live()->where($this->idColumn, $id)->value($this->keyColumn);

        return $key === null ? null : (string) $key;
    }

    /**
     * {@inheritDoc}
     */
    public function exists(int $id): bool
    {
        return $this->live()->where($this->idColumn, $id)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function parentOf(int $id): ?int
    {
        $parent = $this->live()->where($this->idColumn, $id)->value($this->parentColumn);

        return $parent === null ? null : (int) $parent;
    }

    /**
     * True when the live node has its depth-0 self row in the closure table.
     */
    public function hasHierarchyData(int $id): bool
    {
        return $this->exists($id)
            && $this->db()->table($this->closureTable)
                ->where($this->ancestorColumn, $id)
                ->where($this->descendantColumn, $id)
                ->where($this->depthColumn, 0)
                ->exists();
    }

    /**
     * Live closure descendants of the anchors (depth >= 0, so anchors included), as `node_id`.
     *
     * @param array<int, int> $anchorIds
     */
    public function subtreeIdsQuery(array $anchorIds): Builder
    {
        $query = $this->db()->table($this->closureTable . ' as scope_closure')
            ->join($this->nodeTable . ' as scope_node', 'scope_node.' . $this->idColumn, '=', 'scope_closure.' . $this->descendantColumn)
            ->select('scope_closure.' . $this->descendantColumn . ' as node_id')
            ->where('scope_closure.' . $this->depthColumn, '>=', 0);

        if ($this->deletedAtColumn !== null) {
            $query->whereNull('scope_node.' . $this->deletedAtColumn);
        }

        return $anchorIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('scope_closure.' . $this->ancestorColumn, array_map('intval', $anchorIds));
    }

    /**
     * {@inheritDoc}
     */
    public function labels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $labels = [];
        $rows = $this->live()
            ->whereIn($this->idColumn, array_map('intval', $ids))
            ->orderBy($this->idColumn)
            ->get([$this->idColumn . ' as id', $this->labelColumn . ' as label']);

        foreach ($rows as $row) {
            $labels[(int) $row->id] = (string) $row->label;
        }

        return $labels;
    }

    /**
     * Labels of the live closure ancestors, root first, ending with the node itself; empty for a missing node.
     */
    public function trail(int $id): array
    {
        if (! $this->exists($id)) {
            return [];
        }

        $query = $this->db()->table($this->closureTable . ' as scope_closure')
            ->join($this->nodeTable . ' as scope_node', 'scope_node.' . $this->idColumn, '=', 'scope_closure.' . $this->ancestorColumn)
            ->where('scope_closure.' . $this->descendantColumn, $id)
            ->orderByDesc('scope_closure.' . $this->depthColumn);

        if ($this->deletedAtColumn !== null) {
            $query->whereNull('scope_node.' . $this->deletedAtColumn);
        }

        return $query->pluck('scope_node.' . $this->labelColumn)
            ->map(static fn (mixed $label): string => (string) $label)
            ->values()
            ->all();
    }

    /**
     * {@inheritDoc}
     */
    public function search(string $q, int $limit = 20): array
    {
        $like = '%' . addcslashes($q, '%_\\') . '%';

        return $this->live()
            ->where(fn (Builder $w) => $w->where($this->labelColumn, 'like', $like)->orWhere($this->keyColumn, 'like', $like))
            ->orderBy($this->labelColumn)
            ->orderBy($this->idColumn)
            ->limit(max(0, $limit))
            ->get([$this->idColumn . ' as id', $this->keyColumn . ' as key', $this->labelColumn . ' as label'])
            ->map(static fn (object $row): array => ['id' => (int) $row->id, 'key' => (string) $row->key, 'label' => (string) $row->label])
            ->all();
    }

    /**
     * Database connection used by every query.
     */
    protected function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    /**
     * Base query over non-deleted rows of the node table.
     */
    protected function live(): Builder
    {
        $query = $this->db()->table($this->nodeTable);

        return $this->deletedAtColumn === null ? $query : $query->whereNull($this->deletedAtColumn);
    }
}
