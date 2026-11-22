<?php

namespace Cms\Core\Services\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class OrderedKeywordSearch
{
    private const IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/';

    private const ALIAS = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    public function __construct(
        private readonly OrderedKeywordSearchPatternBuilder $patterns = new OrderedKeywordSearchPatternBuilder(),
        private readonly OrderedKeywordSearchCapabilities $capabilities = new OrderedKeywordSearchCapabilities(),
        private readonly OrderedKeywordSearchSql $sql = new OrderedKeywordSearchSql(),
    ) {}

    /** @param list<string> $columns */
    public function apply(
        Builder $query,
        ?string $keyword,
        array $columns,
        Closure $businessOrder,
        string $uniqueKey,
        string $direction,
        ?string $scoreAlias = null,
    ): Builder {
        $this->validate($columns, $uniqueKey, $direction, $scoreAlias);
        $patterns = $this->patterns->build($keyword);

        if ($patterns === []) {
            return $query;
        }

        $connection = $query->getConnection();
        $driver = $connection->getDriverName();
        $capabilities = $this->capabilities->forConnection($connection, $columns);
        $grammar = $query->getQuery()->getGrammar();
        $query->reorder();

        $branches = [];
        foreach ($patterns as $pattern) {
            foreach ($columns as $fieldIndex => $column) {
                $comparison = $this->sql->comparison(
                    $driver,
                    $pattern['mode'],
                    $grammar->wrap($column),
                    $capabilities,
                );
                $branches[] = [
                    'sql' => $comparison['sql'],
                    'bindings' => [...$comparison['prefix_bindings'], $pattern['pattern']],
                    'score' => ($pattern['tier'] * 100) + $fieldIndex,
                ];
            }
        }

        $query->where(static function ($nested) use ($branches): void {
            foreach ($branches as $index => $branch) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $nested->{$method}($branch['sql'], $branch['bindings']);
            }
        });

        $caseSql = 'CASE '.implode(' ', array_map(
            static fn (array $branch): string => "WHEN {$branch['sql']} THEN {$branch['score']}",
            $branches,
        )).' ELSE 999 END';
        $caseBindings = array_merge(...array_column($branches, 'bindings'));

        if ($scoreAlias !== null) {
            if ($query->getQuery()->columns === null) {
                $query->select($query->getModel()->getTable().'.*');
            }
            $query->selectRaw("{$caseSql} AS {$grammar->wrap($scoreAlias)}", $caseBindings);
            $query->orderBy($scoreAlias);
        } else {
            $query->orderByRaw("{$caseSql} ASC", $caseBindings);
        }

        $businessOrder($query);
        $query->orderBy($uniqueKey, $direction);

        return $query;
    }

    /** @param list<string> $columns */
    private function validate(array $columns, string $uniqueKey, string $direction, ?string $scoreAlias): void
    {
        if (count($columns) < 1 || count($columns) > 9) {
            throw new InvalidArgumentException('Search requires one to nine trusted columns.');
        }

        foreach ($columns as $index => $column) {
            if (! is_string($column) || ! preg_match(self::IDENTIFIER, $column)) {
                throw new InvalidArgumentException("Invalid search column at position {$index}.");
            }
        }

        if (! preg_match(self::IDENTIFIER, $uniqueKey)) {
            throw new InvalidArgumentException('Invalid unique-key identifier.');
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Invalid unique-key direction.');
        }

        if ($scoreAlias !== null && ! preg_match(self::ALIAS, $scoreAlias)) {
            throw new InvalidArgumentException('Invalid score alias.');
        }
    }
}
