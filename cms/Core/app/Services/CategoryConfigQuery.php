<?php

namespace Cms\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class CategoryConfigQuery
{
    public static function whereConfig(Builder $query, string $path, mixed $value, string $boolean = 'and'): Builder
    {
        return self::whereConfigOperator($query, $path, '=', $value, $boolean);
    }

    public static function whereConfigOperator(
        Builder $query,
        string $path,
        string $operator,
        mixed $value,
        string $boolean = 'and',
    ): Builder {
        $operator = self::normalizeOperator($operator);

        if (is_bool($value) && in_array($operator, ['=', '!=', '<>'], true)) {
            return self::whereConfigBool($query, $path, $value, $boolean, $operator !== '=');
        }

        $selector = self::textSelector($query, $path);

        return $query->whereRaw(
            sprintf('%s %s ?', $selector, $operator),
            [...self::textSelectorBindings($query, $path), self::comparisonValue($query, $value)],
            $boolean,
        );
    }

    /**
     * @param array<int, mixed> $values
     */
    public static function whereConfigIn(
        Builder $query,
        string $path,
        array $values,
        string $boolean = 'and',
        bool $not = false,
    ): Builder {
        self::assertPath($path);
        $values = array_values(array_filter($values, static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''));

        if ($values === []) {
            return $query->whereRaw($not ? '1 = 1' : '1 = 0', [], $boolean);
        }

        $operator = $not ? 'not in' : 'in';
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return $query->whereRaw(
            sprintf('%s %s (%s)', self::textSelector($query, $path), $operator, $placeholders),
            [
                ...self::textSelectorBindings($query, $path),
                ...array_map(static fn (mixed $value): mixed => self::comparisonValue($query, $value), $values),
            ],
            $boolean,
        );
    }

    public static function whereConfigLike(Builder $query, string $path, string $pattern, string $boolean = 'and'): Builder
    {
        return self::whereConfigOperator($query, $path, 'like', $pattern, $boolean);
    }

    public static function whereConfigBool(
        Builder $query,
        string $path,
        bool $value,
        string $boolean = 'and',
        bool $not = false,
    ): Builder {
        $driver = $query->getConnection()->getDriverName();
        $operator = $not ? '<>' : '=';
        $expected = $value ? 'true' : 'false';

        if ($driver === 'pgsql') {
            return $query->whereRaw(
                sprintf("LOWER(COALESCE(%s, 'false')) %s ?", self::textSelector($query, $path), $operator),
                [$expected],
                $boolean,
            );
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return $query->whereRaw(
                sprintf("LOWER(COALESCE(%s, 'false')) %s ?", self::textSelector($query, $path), $operator),
                [...self::textSelectorBindings($query, $path), $expected],
                $boolean,
            );
        }

        if ($driver === 'sqlite') {
            return $query->whereRaw(
                sprintf('COALESCE(%s, 0) %s ?', self::jsonSelector($query, $path), $operator),
                [...self::jsonSelectorBindings($query, $path), $value ? 1 : 0],
                $boolean,
            );
        }

        return $query->where(self::laravelJsonPath($path), $operator, $value, $boolean);
    }

    public static function laravelJsonPath(string $path): string
    {
        self::assertPath($path);

        return 'config->' . str_replace('.', '->', $path);
    }

    private static function textSelector(Builder $query, string $path): string
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return sprintf(
                "(NULLIF(%s::text, '')::jsonb #>> '{%s}')",
                self::wrappedConfigColumn($query),
                implode(',', self::pathSegments($path)),
            );
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return sprintf('JSON_UNQUOTE(%s)', self::jsonSelector($query, $path));
        }

        if ($driver === 'sqlite') {
            return self::jsonSelector($query, $path);
        }

        return self::wrappedConfigColumn($query);
    }

    private static function jsonSelector(Builder $query, string $path): string
    {
        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return sprintf('JSON_EXTRACT(%s, ?)', self::wrappedConfigColumn($query));
        }

        if ($driver === 'sqlite') {
            return sprintf('json_extract(%s, ?)', self::wrappedConfigColumn($query));
        }

        return self::textSelector($query, $path);
    }

    /**
     * @return list<string>
     */
    private static function jsonSelectorBindings(Builder $query, string $path): array
    {
        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return [self::jsonPathLiteral($path)];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function textSelectorBindings(Builder $query, string $path): array
    {
        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return self::jsonSelectorBindings($query, $path);
        }

        return [];
    }

    private static function wrappedConfigColumn(Builder $query): string
    {
        return $query->getQuery()->getGrammar()->wrap('config');
    }

    /**
     * @return list<string>
     */
    private static function pathSegments(string $path): array
    {
        self::assertPath($path);

        return explode('.', str_replace('->', '.', $path));
    }

    private static function jsonPathLiteral(string $path): string
    {
        return '$.' . implode('.', self::pathSegments($path));
    }

    private static function assertPath(string $path): void
    {
        $segments = explode('.', str_replace('->', '.', trim($path)));

        if ($segments === [] || in_array('', $segments, true)) {
            throw new InvalidArgumentException("Invalid category config path [{$path}].");
        }

        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $segment)) {
                throw new InvalidArgumentException("Invalid category config path [{$path}].");
            }
        }
    }

    private static function normalizeOperator(string $operator): string
    {
        $operator = strtolower(trim($operator));
        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'not like'];

        if (! in_array($operator, $allowed, true)) {
            throw new InvalidArgumentException("Invalid category config operator [{$operator}].");
        }

        return $operator;
    }

    private static function comparisonValue(Builder $query, mixed $value): mixed
    {
        if ($query->getConnection()->getDriverName() === 'sqlite') {
            return $value;
        }

        return (string) $value;
    }
}
