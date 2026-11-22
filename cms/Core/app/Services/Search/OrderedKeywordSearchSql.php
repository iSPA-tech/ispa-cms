<?php

namespace Cms\Core\Services\Search;

use LogicException;

final class OrderedKeywordSearchSql
{
    /**
     * @param  array<string, mixed>  $capabilities
     * @return array{sql: string, prefix_bindings: list<string>}
     */
    public function comparison(
        string $driver,
        string $mode,
        string $quotedColumn,
        array $capabilities,
    ): array {
        if (! in_array($mode, ['sensitive', 'folded'], true)) {
            throw new LogicException('Unsupported keyword normalization mode.');
        }

        return match ($driver) {
            'sqlite' => $this->sqlite($mode, $quotedColumn),
            'pgsql' => $this->postgresql($mode, $quotedColumn, $capabilities),
            'mysql', 'mariadb' => $this->mysql($mode, $quotedColumn, $capabilities),
            default => throw new LogicException("Unsupported database driver: {$driver}."),
        };
    }

    /** @return array{sql: string, prefix_bindings: list<string>} */
    private function sqlite(string $mode, string $quotedColumn): array
    {
        $function = $mode === 'sensitive' ? 'vn_unicode_lower' : 'vn_ascii_fold';

        return [
            'sql' => "{$function}({$quotedColumn}) LIKE ? ESCAPE '!'",
            'prefix_bindings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array{sql: string, prefix_bindings: list<string>}
     */
    private function postgresql(string $mode, string $quotedColumn, array $capabilities): array
    {
        // PostgreSQL keeps uuid/enum/numeric columns strongly typed, so lower(),
        // translate() and unaccent() reject them outright. Cast every searched
        // column to text first; it is a no-op for text and varchar columns.
        $quotedColumn = "{$quotedColumn}::text";

        if ($mode === 'sensitive') {
            return [
                'sql' => "(lower({$quotedColumn}) COLLATE \"C\") LIKE (?::text COLLATE \"C\") ESCAPE '!'",
                'prefix_bindings' => [],
            ];
        }

        if (($capabilities['fold_strategy'] ?? null) === 'unaccent') {
            $schema = $capabilities['unaccent_schema'] ?? null;
            if (! is_string($schema) || str_contains($schema, "\0")) {
                throw new LogicException('Invalid PostgreSQL unaccent capability.');
            }

            $quotedSchema = '"'.str_replace('"', '""', $schema).'"';

            return [
                'sql' => "({$quotedSchema}.unaccent(lower({$quotedColumn})) COLLATE \"C\") LIKE ({$quotedSchema}.unaccent(?::text) COLLATE \"C\") ESCAPE '!'",
                'prefix_bindings' => [],
            ];
        }

        if (($capabilities['fold_strategy'] ?? null) !== 'translate') {
            throw new LogicException('Missing PostgreSQL fold capability.');
        }

        $from = $capabilities['translate_from'] ?? null;
        $to = $capabilities['translate_to'] ?? null;
        if (! is_string($from) || ! is_string($to)) {
            throw new LogicException('Invalid PostgreSQL translate capability.');
        }

        return [
            'sql' => "(translate(lower({$quotedColumn}), ?::text, ?::text) COLLATE \"C\") LIKE (?::text COLLATE \"C\") ESCAPE '!'",
            'prefix_bindings' => [$from, $to],
        ];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array{sql: string, prefix_bindings: list<string>}
     */
    private function mysql(string $mode, string $quotedColumn, array $capabilities): array
    {
        $key = $mode === 'sensitive' ? 'sensitive_collation' : 'folded_collation';
        $expected = $mode === 'sensitive' ? 'utf8mb4_bin' : 'utf8mb4_unicode_ci';

        if (($capabilities[$key] ?? null) !== $expected) {
            throw new LogicException('Missing required MySQL collation capability.');
        }

        $left = $mode === 'sensitive'
            ? "lower({$quotedColumn})"
            : "replace(lower({$quotedColumn}), 'đ', 'd')";

        return [
            // convert() gives the bound parameter an explicit utf8mb4 charset:
            // under native prepares (no PDO emulation) a bare `?` binds as
            // CHARACTER SET binary and `? COLLATE utf8mb4_*` fails with 1253.
            // The capability probe already uses convert(); runtime must match.
            'sql' => "{$left} COLLATE {$expected} LIKE convert(? using utf8mb4) COLLATE {$expected} ESCAPE '!'",
            'prefix_bindings' => [],
        ];
    }
}
