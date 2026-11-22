<?php

namespace Cms\Core\Services\Search;

use Illuminate\Database\Connection;
use LogicException;
use PDO;
use Throwable;
use WeakMap;

final class OrderedKeywordSearchCapabilities
{
    /** @var WeakMap<object, array{capabilities: array<string, mixed>, verified_columns: array<string, true>}>|null */
    private static ?WeakMap $cache = null;

    private static int $savepointSequence = 0;

    public function __construct(
        private readonly OrderedKeywordSearchPatternBuilder $patterns = new OrderedKeywordSearchPatternBuilder(),
    ) {}

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    public function forConnection(Connection $connection, array $columns): array
    {
        $pdo = $connection->getPdo();
        if (! is_object($pdo)) {
            throw new LogicException('Database connection has no active PDO capability.');
        }

        self::$cache ??= new WeakMap();
        $record = self::$cache[$pdo] ?? null;
        $driver = $connection->getDriverName();

        if ($record === null) {
            $capabilities = match ($driver) {
                'sqlite' => $this->registerSqlite($pdo),
                'pgsql' => $this->probePostgresql($pdo),
                'mysql', 'mariadb' => $this->probeMysql($pdo),
                default => throw new LogicException("Unsupported database driver: {$driver}."),
            };
            $record = ['capabilities' => $capabilities, 'verified_columns' => []];
            self::$cache[$pdo] = $record;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true) && $columns !== []) {
            $unverified = array_values(array_filter(
                array_unique($columns),
                static fn (string $column): bool => ! isset($record['verified_columns'][$column]),
            ));
            if ($unverified !== []) {
                $this->assertMysqlColumns($pdo, $unverified);
                foreach ($unverified as $column) {
                    $record['verified_columns'][$column] = true;
                }
                self::$cache[$pdo] = $record;
            }
        }

        return $record['capabilities'];
    }

    /** @return array<string, mixed> */
    private function registerSqlite(object $pdo): array
    {
        if (! method_exists($pdo, 'sqliteCreateFunction')) {
            throw new LogicException('SQLite function registration is unavailable.');
        }

        $flags = defined('PDO::SQLITE_DETERMINISTIC') ? PDO::SQLITE_DETERMINISTIC : 0;
        $lower = static fn (?string $value): ?string => $value === null ? null : mb_strtolower($value);
        $fold = fn (?string $value): ?string => $value === null ? null : $this->patterns->fold($value);

        if (! $pdo->sqliteCreateFunction('vn_unicode_lower', $lower, 1, $flags)
            || ! $pdo->sqliteCreateFunction('vn_ascii_fold', $fold, 1, $flags)) {
            throw new LogicException('SQLite keyword functions could not be registered.');
        }

        return ['deterministic' => $flags !== 0];
    }

    /** @return array<string, mixed> */
    private function probePostgresql(object $pdo): array
    {
        try {
            $statement = $pdo->prepare(<<<'SQL'
SELECT
    (lower('NHIỆM') COLLATE "C") LIKE (?::text COLLATE "C") ESCAPE '!' AS case_ok,
    NOT ((lower('nhiệm') COLLATE "C") LIKE (?::text COLLATE "C") ESCAPE '!') AS accent_distinct
SQL);
            $statement->execute(['nhiệm', 'nhiem']);
            $fixture = $statement->fetch(PDO::FETCH_ASSOC);
            if (! $this->databaseBoolean($fixture['case_ok'] ?? false)
                || ! $this->databaseBoolean($fixture['accent_distinct'] ?? false)) {
                throw new LogicException('PostgreSQL sensitive comparison capability failed.');
            }
        } catch (LogicException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LogicException('PostgreSQL sensitive comparison capability failed.');
        }

        $fallback = [
            'fold_strategy' => 'translate',
            'translate_from' => $this->patterns->postgresTranslateFrom(),
            'translate_to' => $this->patterns->postgresTranslateTo(),
        ];

        $savepoint = null;
        try {
            if ($pdo->inTransaction()) {
                $savepoint = 'ordered_keyword_unaccent_probe_'.(++self::$savepointSequence);
                $pdo->exec('SAVEPOINT "'.$savepoint.'"');
            }

            $catalog = $pdo->query(<<<'SQL'
SELECT n.nspname
FROM pg_extension e
JOIN pg_namespace n ON n.oid = e.extnamespace
WHERE e.extname = 'unaccent'
LIMIT 1
SQL);
            if ($catalog === false) {
                throw new LogicException('PostgreSQL unaccent catalog probe failed.');
            }
            $schema = $catalog->fetchColumn();
            if (! is_string($schema) || str_contains($schema, "\0")) {
                $this->releaseSavepoint($pdo, $savepoint);

                return $fallback;
            }

            $quoted = '"'.str_replace('"', '""', $schema).'"';
            $fixture = $pdo->prepare(<<<SQL
SELECT
    ({$quoted}.unaccent(lower('NHIỆM')) COLLATE "C") LIKE ({$quoted}.unaccent(?::text) COLLATE "C") ESCAPE '!' AS case_ok,
    ({$quoted}.unaccent(lower('nhiệm')) COLLATE "C") LIKE ({$quoted}.unaccent(?::text) COLLATE "C") ESCAPE '!' AS fold_ok
SQL);
            $fixture->execute(['nhiem', 'nhiem']);
            $result = $fixture->fetch(PDO::FETCH_ASSOC);

            if ($this->databaseBoolean($result['case_ok'] ?? false)
                && $this->databaseBoolean($result['fold_ok'] ?? false)) {
                $this->releaseSavepoint($pdo, $savepoint);

                return ['fold_strategy' => 'unaccent', 'unaccent_schema' => $schema];
            }

            throw new LogicException('PostgreSQL unaccent fixture failed.');
        } catch (Throwable) {
            $this->rollbackSavepoint($pdo, $savepoint);

            return $fallback;
        }
    }

    /** @return array<string, string> */
    private function probeMysql(object $pdo): array
    {
        try {
            $statement = $pdo->query(<<<'SQL'
SELECT COLLATION_NAME
FROM information_schema.COLLATIONS
WHERE COLLATION_NAME IN ('utf8mb4_bin', 'utf8mb4_unicode_ci')
SQL);
            $available = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
            if (! in_array('utf8mb4_bin', $available, true)
                || ! in_array('utf8mb4_unicode_ci', $available, true)) {
                throw new LogicException('Required MySQL collations are unavailable.');
            }

            $fixture = $pdo->prepare(<<<'SQL'
SELECT
    lower(convert(? using utf8mb4)) COLLATE utf8mb4_bin LIKE convert(? using utf8mb4) COLLATE utf8mb4_bin ESCAPE '!' AS sensitive_case_ok,
    NOT (lower(convert(? using utf8mb4)) COLLATE utf8mb4_bin LIKE convert(? using utf8mb4) COLLATE utf8mb4_bin ESCAPE '!') AS sensitive_accent_distinct,
    replace(lower(convert(? using utf8mb4)), 'đ', 'd') COLLATE utf8mb4_unicode_ci LIKE convert(? using utf8mb4) COLLATE utf8mb4_unicode_ci ESCAPE '!' AS folded_ok,
    replace(lower(convert(? using utf8mb4)), 'đ', 'd') COLLATE utf8mb4_unicode_ci LIKE convert(? using utf8mb4) COLLATE utf8mb4_unicode_ci ESCAPE '!' AS canonical_fold_ok
SQL);
            $fixture->execute([
                'NHIỆM', 'nhiệm',
                'nhiệm', 'nhiem',
                'nhiệm', 'nhiem',
                $this->patterns->postgresTranslateFrom(),
                $this->patterns->postgresTranslateTo(),
            ]);
            $result = $fixture->fetch(PDO::FETCH_ASSOC);
            if (! $this->databaseBoolean($result['sensitive_case_ok'] ?? false)
                || ! $this->databaseBoolean($result['sensitive_accent_distinct'] ?? false)
                || ! $this->databaseBoolean($result['folded_ok'] ?? false)
                || ! $this->databaseBoolean($result['canonical_fold_ok'] ?? false)) {
                throw new LogicException('MySQL comparison capability failed.');
            }
        } catch (LogicException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LogicException('MySQL comparison capability failed.');
        }

        return [
            'sensitive_collation' => 'utf8mb4_bin',
            'folded_collation' => 'utf8mb4_unicode_ci',
        ];
    }

    /** @param list<string> $columns */
    private function assertMysqlColumns(object $pdo, array $columns): void
    {
        $pairs = [];
        $bindings = [];
        $expected = [];
        foreach ($columns as $identifier) {
            [$table, $column] = str_contains($identifier, '.')
                ? explode('.', $identifier, 2)
                : ['', $identifier];

            if ($table === '') {
                throw new LogicException('MySQL searchable columns must be table-qualified.');
            }

            $pairs[] = '(TABLE_NAME = ? AND COLUMN_NAME = ?)';
            $bindings[] = $table;
            $bindings[] = $column;
            $expected[$table."\0".$column] = true;
        }

        $statement = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME '
            .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ('
            .implode(' OR ', $pairs).')'
        );
        $statement->execute($bindings);
        $verified = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = ($row['TABLE_NAME'] ?? '')."\0".($row['COLUMN_NAME'] ?? '');
            if (isset($expected[$key]) && ($row['CHARACTER_SET_NAME'] ?? null) === 'utf8mb4') {
                $verified[$key] = true;
            }
        }

        foreach ($expected as $key => $_) {
            if (! isset($verified[$key])) {
                throw new LogicException('MySQL searchable column charset is incompatible.');
            }
        }
    }

    private function databaseBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function releaseSavepoint(object $pdo, ?string $savepoint): void
    {
        if ($savepoint !== null) {
            $pdo->exec('RELEASE SAVEPOINT "'.$savepoint.'"');
        }
    }

    private function rollbackSavepoint(object $pdo, ?string $savepoint): void
    {
        if ($savepoint === null) {
            return;
        }

        try {
            $pdo->exec('ROLLBACK TO SAVEPOINT "'.$savepoint.'"');
        } finally {
            $pdo->exec('RELEASE SAVEPOINT "'.$savepoint.'"');
        }
    }
}
