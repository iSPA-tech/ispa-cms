<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HierarchyProvider over a plain table without parent semantics (every node is a root).
 *
 * parentOf() is always null and the subtree of an anchor is the anchor itself, so every expansion resolves to
 * exactly the held nodes.
 */
class FlatHierarchy implements HierarchyProvider
{
    /**
     * @param string $table Node table.
     * @param string $idColumn Integer primary key column.
     * @param string $keyColumn Stable key column (code, uuid, ...).
     * @param string $labelColumn Human-readable label column.
     * @param string|null $connection Database connection name; null for the default connection.
     * @param string|null $deletedAtColumn Soft-delete timestamp column; null when the table has none.
     */
    public function __construct(
        protected string $table,
        protected string $idColumn = 'id',
        protected string $keyColumn = 'code',
        protected string $labelColumn = 'name',
        protected ?string $connection = null,
        protected ?string $deletedAtColumn = null,
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
     * Always null: a flat hierarchy has no parents.
     */
    public function parentOf(int $id): ?int
    {
        return null;
    }

    /**
     * True whenever the node exists: a flat node needs no extra hierarchy data.
     */
    public function hasHierarchyData(int $id): bool
    {
        return $this->exists($id);
    }

    /**
     * Live anchors only, as `node_id`.
     *
     * @param array<int, int> $anchorIds
     */
    public function subtreeIdsQuery(array $anchorIds): Builder
    {
        $query = $this->live()->select($this->idColumn . ' as node_id');

        return $anchorIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($this->idColumn, array_map('intval', $anchorIds));
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
     * The node's own label (a flat node is its own root), or empty when it does not exist.
     */
    public function trail(int $id): array
    {
        return array_values($this->labels([$id]));
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
        $query = $this->db()->table($this->table);

        return $this->deletedAtColumn === null ? $query : $query->whereNull($this->deletedAtColumn);
    }
}
