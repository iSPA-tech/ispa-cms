<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HierarchyProvider over a node table that stores each node's position as a materialised path, e.g.
 * `/MKT/MKT_HOTTOY/HOTTOY_A/` (segments are node keys, root first, wrapped in the separator).
 *
 * Well-formed path (anything else means the node has no hierarchy data, so the planner treats it as `self`):
 * - starts and ends with the separator and has at least one segment, none of them empty;
 * - its last segment is the node's own key;
 * - the chain is resolvable AND consistent: every segment names a live node whose own path is exactly the path
 *   prefix ending at that segment. A segment naming a node that lives elsewhere, or a soft-deleted ancestor, breaks
 *   the chain of every node below it.
 *
 * Subtrees are resolved with `path LIKE '<anchor path>%' ESCAPE '!'`; the anchor path is escaped, so `%`, `_` and
 * `!` in keys are matched literally. The match is always case-sensitive (`/ROOT/HOT_TOY/` never reaches
 * `/ROOT/hot_toy/`): MySQL/MariaDB use `LIKE BINARY`, sqlite adds an exact `substr(...) = prefix` check, pgsql LIKE is
 * already case-sensitive, and any other driver is refused instead of falling back to a loose match. Descendants are matched by path prefix only (their own chains are not
 * re-validated), mirroring the closure variant where membership comes from stored rows.
 * Only anchors with well-formed paths contribute. This class never derives anchors from path segments; only
 * ExpansionPlanner decides anchors.
 * Soft-deleted nodes do not exist: they are excluded from lookups, labels, trails, search and subtree queries.
 */
class PathHierarchy implements HierarchyProvider
{
    /**
     * Escape character for LIKE patterns; works with an explicit ESCAPE clause on sqlite and MySQL/MariaDB.
     */
    protected const LIKE_ESCAPE = '!';

    /**
     * @param string $table Node table.
     * @param string $idColumn Integer primary key column.
     * @param string $keyColumn Stable key column; path segments are values of this column.
     * @param string $labelColumn Human-readable label column.
     * @param string $pathColumn Materialised path column (`/ROOT/CHILD/NODE/`).
     * @param string $separator Path segment separator (non-empty).
     * @param string|null $deletedAtColumn Soft-delete timestamp column; null when the table has none.
     * @param string|null $connection Database connection name; null for the default connection.
     */
    public function __construct(
        protected string $table,
        protected string $idColumn = 'id',
        protected string $keyColumn = 'code',
        protected string $labelColumn = 'name',
        protected string $pathColumn = 'path',
        protected string $separator = '/',
        protected ?string $deletedAtColumn = 'deleted_at',
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
     * The live node named by the second-to-last segment of a well-formed path; null for a root, a malformed path
     * or an unknown node.
     */
    public function parentOf(int $id): ?int
    {
        $chain = $this->chain($id);

        return $chain === null || count($chain) < 2 ? null : (int) $chain[count($chain) - 2]->id;
    }

    /**
     * True when the live node has a well-formed path (see the class docblock).
     */
    public function hasHierarchyData(int $id): bool
    {
        return $this->chain($id) !== null;
    }

    /**
     * Live nodes whose path starts with the path of a well-formed anchor (anchors included), as `node_id`.
     *
     * @param array<int, int> $anchorIds
     */
    public function subtreeIdsQuery(array $anchorIds): Builder
    {
        $prefixes = [];

        foreach (array_unique(array_map('intval', $anchorIds)) as $anchorId) {
            $chain = $this->chain($anchorId);

            if ($chain !== null) {
                $prefixes[] = (string) end($chain)->path;
            }
        }

        $query = $this->live()->select($this->idColumn . ' as node_id');

        if ($prefixes === []) {
            return $query->whereRaw('1 = 0');
        }

        $column = $query->getGrammar()->wrap($this->pathColumn);
        $driver = $this->driverName();
        $escape = " escape '" . self::LIKE_ESCAPE . "'";

        [$sql, $exact] = match ($driver) {
            'mysql', 'mariadb' => [$column . ' like binary ?' . $escape, false],
            'sqlite' => [$column . ' like ?' . $escape . ' and substr(' . $column . ', 1, ?) = ?', true],
            'pgsql' => [$column . ' like ?' . $escape, false],
            default => throw new \RuntimeException("PathHierarchy cannot build a case-sensitive subtree match on database driver [{$driver}]."),
        };

        return $query->where(function (Builder $w) use ($prefixes, $sql, $exact): void {
            foreach ($prefixes as $prefix) {
                $bindings = [$this->escapeLike($prefix) . '%'];

                if ($exact) {
                    array_push($bindings, mb_strlen($prefix), $prefix);
                }

                $w->orWhereRaw('(' . $sql . ')', $bindings);
            }
        });
    }

    /**
     * Name of the database driver behind the node table connection (e.g. `mysql`, `sqlite`, `pgsql`).
     */
    protected function driverName(): string
    {
        return $this->db()->getDriverName();
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
     * Labels of the path chain, root first, ending with the node itself; empty for a missing node.
     * Fail closed on a malformed path: only the node's own label is returned, never labels of nodes the broken
     * path merely names.
     */
    public function trail(int $id): array
    {
        $chain = $this->chain($id);

        if ($chain !== null) {
            return array_map(static fn (object $row): string => (string) $row->label, $chain);
        }

        $label = $this->live()->where($this->idColumn, $id)->value($this->labelColumn);

        return $label === null ? [] : [(string) $label];
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
     * Rows (id, key, label, path) of the live nodes named by the node's path, root first, ending with the node;
     * null when the node is missing or its path is not well-formed.
     *
     * @return array<int, object>|null
     */
    protected function chain(int $id): ?array
    {
        $node = $this->live()->where($this->idColumn, $id)->first([$this->keyColumn . ' as key', $this->pathColumn . ' as path']);

        if ($node === null || $this->separator === '') {
            return null;
        }

        $path = (string) $node->path;
        $sep = $this->separator;

        if (strlen($path) <= 2 * strlen($sep) - 1 || ! str_starts_with($path, $sep) || ! str_ends_with($path, $sep)) {
            return null;
        }

        $segments = explode($sep, substr($path, strlen($sep), -strlen($sep)));

        if (in_array('', $segments, true) || end($segments) !== (string) $node->key) {
            return null;
        }

        $rows = $this->live()
            ->whereIn($this->keyColumn, array_values(array_unique($segments)))
            ->get([$this->idColumn . ' as id', $this->keyColumn . ' as key', $this->labelColumn . ' as label', $this->pathColumn . ' as path'])
            ->keyBy(static fn (object $row): string => (string) $row->key);

        $chain = [];
        $prefix = $sep;

        foreach ($segments as $segment) {
            $prefix .= $segment . $sep;
            $row = $rows->get($segment);

            // Each segment must be a live node sitting exactly at this prefix; otherwise the chain is broken.
            if ($row === null || (string) $row->path !== $prefix) {
                return null;
            }

            $chain[] = $row;
        }

        return $chain;
    }

    /**
     * Escape LIKE wildcards and the escape character itself so the value matches literally.
     */
    protected function escapeLike(string $value): string
    {
        $e = self::LIKE_ESCAPE;

        return str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], $value);
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
