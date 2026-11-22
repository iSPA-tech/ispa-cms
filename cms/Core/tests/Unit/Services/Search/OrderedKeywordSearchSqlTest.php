<?php

namespace Cms\Core\Tests\Unit\Services\Search;

use Cms\Core\Services\Search\OrderedKeywordSearchSql;
use Cms\Core\Services\Search\OrderedKeywordSearchCapabilities;
use Cms\Core\Services\Search\OrderedKeywordSearch;
use Cms\Core\Services\Search\OrderedKeywordSearchPatternBuilder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Processors\Processor;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OrderedKeywordSearchSqlTest extends TestCase
{
    #[DataProvider('postgresqlProvider')]
    public function test_postgresql_renderer_uses_exact_controlled_templates(
        string $mode,
        array $capabilities,
        string $expectedSql,
        array $expectedPrefixBindings,
    ): void {
        $comparison = (new OrderedKeywordSearchSql())->comparison(
            'pgsql',
            $mode,
            '"search_fixture"."title"',
            $capabilities,
        );

        $this->assertSame($expectedSql, $comparison['sql']);
        $this->assertSame($expectedPrefixBindings, $comparison['prefix_bindings']);
    }

    public static function postgresqlProvider(): array
    {
        return [
            'postgresql sensitive' => [
                'sensitive',
                ['fold_strategy' => 'translate', 'translate_from' => 'áđ', 'translate_to' => 'ad'],
                '(lower("search_fixture"."title"::text) COLLATE "C") LIKE (?::text COLLATE "C") ESCAPE \'!\'',
                [],
            ],
            'postgresql qualified unaccent' => [
                'folded',
                ['fold_strategy' => 'unaccent', 'unaccent_schema' => 'extensions'],
                '("extensions".unaccent(lower("search_fixture"."title"::text)) COLLATE "C") LIKE ("extensions".unaccent(?::text) COLLATE "C") ESCAPE \'!\'',
                [],
            ],
            'postgresql translate fallback' => [
                'folded',
                ['fold_strategy' => 'translate', 'translate_from' => 'áđ', 'translate_to' => 'ad'],
                '(translate(lower("search_fixture"."title"::text), ?::text, ?::text) COLLATE "C") LIKE (?::text COLLATE "C") ESCAPE \'!\'',
                ['áđ', 'ad'],
            ],
        ];
    }

    #[DataProvider('postgresqlNonTextColumnProvider')]
    public function test_postgresql_renderer_casts_non_text_columns_to_text(
        string $mode,
        array $capabilities,
    ): void {
        $comparison = (new OrderedKeywordSearchSql())->comparison(
            'pgsql',
            $mode,
            '"sample_module_import_candidates"."uuid"',
            $capabilities,
        );

        // Without the cast PostgreSQL rejects the query: function lower(uuid) does not exist.
        $this->assertStringContainsString('"sample_module_import_candidates"."uuid"::text', $comparison['sql']);
        $this->assertStringNotContainsString('"uuid") COLLATE', $comparison['sql']);
        $this->assertStringNotContainsString('"uuid"),', $comparison['sql']);
    }

    public static function postgresqlNonTextColumnProvider(): array
    {
        return [
            'sensitive' => ['sensitive', ['fold_strategy' => 'translate', 'translate_from' => 'áđ', 'translate_to' => 'ad']],
            'folded translate' => ['folded', ['fold_strategy' => 'translate', 'translate_from' => 'áđ', 'translate_to' => 'ad']],
            'folded unaccent' => ['folded', ['fold_strategy' => 'unaccent', 'unaccent_schema' => 'public']],
        ];
    }

    #[DataProvider('mysqlProvider')]
    public function test_mysql_renderer_uses_only_allowlisted_collations(
        string $driver,
        string $mode,
        string $collation,
    ): void {
        $comparison = (new OrderedKeywordSearchSql())->comparison(
            $driver,
            $mode,
            '`search_fixture`.`title`',
            ['sensitive_collation' => 'utf8mb4_bin', 'folded_collation' => 'utf8mb4_unicode_ci'],
        );

        $left = $mode === 'sensitive'
            ? 'lower(`search_fixture`.`title`)'
            : "replace(lower(`search_fixture`.`title`), 'đ', 'd')";
        $this->assertSame("{$left} COLLATE {$collation} LIKE convert(? using utf8mb4) COLLATE {$collation} ESCAPE '!'", $comparison['sql']);
        $this->assertSame([], $comparison['prefix_bindings']);
    }

    public static function mysqlProvider(): array
    {
        return [
            'mysql sensitive' => ['mysql', 'sensitive', 'utf8mb4_bin'],
            'mysql folded' => ['mysql', 'folded', 'utf8mb4_unicode_ci'],
            'mariadb sensitive' => ['mariadb', 'sensitive', 'utf8mb4_bin'],
            'mariadb folded' => ['mariadb', 'folded', 'utf8mb4_unicode_ci'],
        ];
    }

    public function test_sqlite_renderer_uses_registered_functions_and_portable_escape(): void
    {
        $renderer = new OrderedKeywordSearchSql();

        $this->assertSame(
            'vn_unicode_lower("title") LIKE ? ESCAPE \'!\'',
            $renderer->comparison('sqlite', 'sensitive', '"title"', [])['sql'],
        );
        $this->assertSame(
            'vn_ascii_fold("title") LIKE ? ESCAPE \'!\'',
            $renderer->comparison('sqlite', 'folded', '"title"', [])['sql'],
        );
    }

    public function test_unsupported_driver_and_invalid_capability_fail_closed(): void
    {
        $renderer = new OrderedKeywordSearchSql();

        try {
            $renderer->comparison('sqlsrv', 'sensitive', '[title]', []);
            $this->fail('Unsupported driver must fail.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('sqlsrv', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertStringNotContainsString('[title]', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $renderer->comparison('pgsql', 'folded', '"title"', ['fold_strategy' => 'unaccent']);
    }

    #[DataProvider('postgresFallbackProvider')]
    public function test_postgresql_catalog_and_fixture_failures_select_translate_fallback(string $failure): void
    {
        $pdo = ScriptedSearchPdo::postgres($failure);
        $capabilities = (new OrderedKeywordSearchCapabilities())->forConnection($this->connection($pdo, 'pgsql'), []);

        $this->assertSame('translate', $capabilities['fold_strategy']);
    }

    public static function postgresFallbackProvider(): array
    {
        return [
            'catalog failure' => ['catalog_failure'],
            'catalog permission failure' => ['catalog_permission'],
            'missing extension' => ['missing_extension'],
            'failed exact fixture' => ['fixture_failure'],
        ];
    }

    public function test_postgresql_accepts_and_quotes_nontrivial_catalog_schema_and_caches_per_pdo(): void
    {
        $pdo = ScriptedSearchPdo::postgres('success', 'Tiện ích-search');
        $service = new OrderedKeywordSearchCapabilities();
        $connection = $this->connection($pdo, 'pgsql');

        $first = $service->forConnection($connection, []);
        $second = $service->forConnection($connection, []);

        $this->assertSame(['fold_strategy' => 'unaccent', 'unaccent_schema' => 'Tiện ích-search'], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $pdo->catalogQueries);
        $this->assertStringContainsString('"Tiện ích-search".unaccent', implode("\n", $pdo->preparedSql));
    }

    public function test_postgresql_transactional_unaccent_failure_rolls_back_and_releases_savepoint(): void
    {
        $pdo = ScriptedSearchPdo::postgres('catalog_permission', 'public', true);
        $capabilities = (new OrderedKeywordSearchCapabilities())->forConnection($this->connection($pdo, 'pgsql'), []);

        $this->assertSame('translate', $capabilities['fold_strategy']);
        $this->assertCount(3, $pdo->executedSql);
        $this->assertMatchesRegularExpression('/^SAVEPOINT "ordered_keyword_unaccent_probe_\d+"$/', $pdo->executedSql[0]);
        $this->assertMatchesRegularExpression('/^ROLLBACK TO SAVEPOINT "ordered_keyword_unaccent_probe_\d+"$/', $pdo->executedSql[1]);
        $this->assertMatchesRegularExpression('/^RELEASE SAVEPOINT "ordered_keyword_unaccent_probe_\d+"$/', $pdo->executedSql[2]);
        $this->assertSame(1, $pdo->query('SELECT 1')->fetchColumn());
    }

    public function test_postgresql_transactional_unaccent_success_and_missing_extension_release_cleanly(): void
    {
        foreach (['success', 'missing_extension'] as $state) {
            $pdo = ScriptedSearchPdo::postgres($state, 'public', true);
            $capabilities = (new OrderedKeywordSearchCapabilities())->forConnection($this->connection($pdo, 'pgsql'), []);

            $this->assertSame($state === 'success' ? 'unaccent' : 'translate', $capabilities['fold_strategy']);
            $this->assertCount(2, $pdo->executedSql);
            $this->assertStringStartsWith('SAVEPOINT ', $pdo->executedSql[0]);
            $this->assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedSql[1]);
        }
    }

    public function test_postgresql_rejects_only_nul_in_catalog_schema(): void
    {
        $pdo = ScriptedSearchPdo::postgres('success', "unsafe\0schema");
        $capabilities = (new OrderedKeywordSearchCapabilities())->forConnection($this->connection($pdo, 'pgsql'), []);

        $this->assertSame('translate', $capabilities['fold_strategy']);
    }

    public function test_postgresql_renderer_double_quotes_catalog_schema_without_ascii_restriction(): void
    {
        $comparison = (new OrderedKeywordSearchSql())->comparison(
            'pgsql',
            'folded',
            '"title"',
            ['fold_strategy' => 'unaccent', 'unaccent_schema' => 'Tiện ích-"search"'],
        );

        $this->assertStringContainsString('"Tiện ích-""search""".unaccent', $comparison['sql']);
    }

    public function test_mysql_probe_uses_exact_like_templates_and_caches_mariadb_per_pdo(): void
    {
        $pdo = ScriptedSearchPdo::mysql();
        $service = new OrderedKeywordSearchCapabilities();
        $connection = $this->connection($pdo, 'mariadb');

        $first = $service->forConnection($connection, []);
        $second = $service->forConnection($connection, []);

        $this->assertSame($first, $second);
        $this->assertSame(1, $pdo->collationQueries);
        $fixture = implode("\n", $pdo->preparedSql);
        $this->assertStringContainsString("lower(convert(? using utf8mb4)) COLLATE utf8mb4_bin LIKE convert(? using utf8mb4) COLLATE utf8mb4_bin ESCAPE '!'", $fixture);
        $this->assertStringContainsString("replace(lower(convert(? using utf8mb4)), 'đ', 'd') COLLATE utf8mb4_unicode_ci LIKE convert(? using utf8mb4) COLLATE utf8mb4_unicode_ci ESCAPE '!'", $fixture);
        $this->assertSame(1, $pdo->canonicalFixturePrepares);
    }

    public function test_mysql_batches_and_caches_column_charset_verification_per_pdo(): void
    {
        $pdo = ScriptedSearchPdo::mysql();
        $service = new OrderedKeywordSearchCapabilities();
        $connection = $this->connection($pdo, 'mysql');
        $columns = [
            'qb_questions.question_text',
            'qb_questions.explanation',
            'sample_module_articles.title',
            'sample_module_articles.summary',
            'posts.name',
            'posts.description',
        ];

        $service->forConnection($connection, $columns);
        $service->forConnection($connection, $columns);

        $this->assertSame(1, $pdo->metadataPrepares);
        $this->assertSame(1, $pdo->canonicalFixturePrepares);
    }

    public function test_mysql_missing_collation_and_failed_canonical_fixture_fail_closed(): void
    {
        foreach (['missing_collation', 'canonical_failure'] as $failure) {
            try {
                (new OrderedKeywordSearchCapabilities())->forConnection(
                    $this->connection(ScriptedSearchPdo::mysql($failure), 'mysql'),
                    [],
                );
                $this->fail('MySQL capability failure must throw.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_mysql_non_utf8mb4_failure_exercises_all_six_adopted_columns_without_echoing_identifiers(): void
    {
        $columns = [
            'qb_questions.question_text',
            'qb_questions.explanation',
            'sample_module_articles.title',
            'sample_module_articles.summary',
            'posts.name',
            'posts.description',
        ];

        foreach ($columns as $column) {
            try {
                (new OrderedKeywordSearchCapabilities())->forConnection(
                    $this->connection(ScriptedSearchPdo::mysql('bad_charset'), 'mysql'),
                    [$column],
                );
                $this->fail('Incompatible charset must throw.');
            } catch (LogicException $exception) {
                $this->assertSame('MySQL searchable column charset is incompatible.', $exception->getMessage());
                $this->assertStringNotContainsString($column, $exception->getMessage());
            }
        }
    }

    public function test_sqlite_registration_is_cached_per_pdo_and_independent_across_pdos(): void
    {
        $service = new OrderedKeywordSearchCapabilities();
        $first = new FakeSqliteRegistrationPdo();
        $second = new FakeSqliteRegistrationPdo();

        $firstCapability = $service->forConnection($this->connection($first, 'sqlite'), []);
        $service->forConnection($this->connection($first, 'sqlite'), []);
        $secondCapability = $service->forConnection($this->connection($second, 'sqlite'), []);

        $this->assertSame(2, $first->registrationCalls);
        $this->assertSame(2, $second->registrationCalls);
        $this->assertSame(defined('PDO::SQLITE_DETERMINISTIC'), $firstCapability['deterministic']);
        $this->assertSame($firstCapability, $secondCapability);
        if (defined('PDO::SQLITE_DETERMINISTIC')) {
            $this->assertSame([PDO::SQLITE_DETERMINISTIC, PDO::SQLITE_DETERMINISTIC], $first->flags);
        }
    }

    public function test_sqlite_unavailable_or_failed_registration_fails_closed(): void
    {
        foreach ([ScriptedSearchPdo::mysql(), new FakeSqliteRegistrationPdo(true)] as $pdo) {
            try {
                (new OrderedKeywordSearchCapabilities())->forConnection($this->connection($pdo, 'sqlite'), []);
                $this->fail('SQLite registration failure must throw.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_postgresql_translate_bindings_stay_aligned_across_where_and_case(): void
    {
        $connection = $this->connection(ScriptedSearchPdo::postgres('missing_extension'), 'pgsql');
        $connection->setQueryGrammar(new PostgresGrammar($connection));
        $base = new QueryBuilder($connection, $connection->getQueryGrammar(), new Processor());
        $query = new EloquentBuilder($base);
        SqlBindingFixtureModel::$testConnection = $connection;
        $query->setModel(new SqlBindingFixtureModel());

        (new OrderedKeywordSearch())->apply(
            $query,
            'nhiệm vụ',
            ['search_fixture.title'],
            static fn (): null => null,
            'search_fixture.id',
            'asc',
        );

        $patterns = new OrderedKeywordSearchPatternBuilder();
        $from = $patterns->postgresTranslateFrom();
        $to = $patterns->postgresTranslateTo();
        $this->assertSame([
            '%nhiệm vụ%', '%nhiệm%vụ%',
            $from, $to, '%nhiem vu%', $from, $to, '%nhiem%vu%',
            '%nhiệm vụ%', '%nhiệm%vụ%',
            $from, $to, '%nhiem vu%', $from, $to, '%nhiem%vu%',
        ], $query->getBindings());
    }

    public function test_applicator_rejects_unsupported_driver_before_query_construction_with_driver_only_message(): void
    {
        $pdo = ScriptedSearchPdo::mysql();
        $connection = $this->connection($pdo, 'sqlsrv');
        $base = new QueryBuilder($connection, $connection->getQueryGrammar(), new Processor());
        $query = new EloquentBuilder($base);
        SqlBindingFixtureModel::$testConnection = $connection;
        $query->setModel(new SqlBindingFixtureModel());
        $beforeSql = $query->toSql();
        $beforeBindings = $query->getBindings();

        try {
            (new OrderedKeywordSearch())->apply(
                $query,
                'private keyword',
                ['search_fixture.title'],
                static fn (): null => null,
                'search_fixture.id',
                'asc',
            );
            $this->fail('Unsupported applicator driver must throw.');
        } catch (LogicException $exception) {
            $this->assertSame('Unsupported database driver: sqlsrv.', $exception->getMessage());
            $this->assertStringNotContainsString('private keyword', $exception->getMessage());
            $this->assertStringNotContainsString('search_fixture', $exception->getMessage());
        }

        $this->assertSame($beforeSql, $query->toSql());
        $this->assertSame($beforeBindings, $query->getBindings());
        $this->assertSame([], $pdo->preparedSql);
        $this->assertSame(0, $pdo->collationQueries);
    }

    private function connection(PDO $pdo, string $driver): Connection
    {
        return new Connection($pdo, 'testing', '', ['driver' => $driver]);
    }
}

final class ScriptedSearchPdo extends PDO
{
    public int $catalogQueries = 0;

    public int $collationQueries = 0;

    public int $canonicalFixturePrepares = 0;

    public int $metadataPrepares = 0;

    /** @var list<string> */
    public array $executedSql = [];

    private bool $transactionAborted = false;

    /** @var list<string> */
    public array $preparedSql = [];

    private function __construct(
        private readonly string $driver,
        private readonly string $failure = 'success',
        private readonly string $schema = 'public',
        private readonly bool $transaction = false,
    ) {}

    public static function postgres(
        string $failure = 'success',
        string $schema = 'public',
        bool $transaction = false,
    ): self
    {
        return new self('pgsql', $failure, $schema, $transaction);
    }

    public static function mysql(string $failure = 'success'): self
    {
        return new self('mysql', $failure);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $query;

        if ($this->driver === 'pgsql') {
            if (str_contains($query, "lower('NHIỆM')") && ! str_contains($query, 'unaccent')) {
                return new ScriptedSearchStatement([['case_ok' => true, 'accent_distinct' => true]]);
            }

            return new ScriptedSearchStatement(
                [['case_ok' => $this->failure !== 'fixture_failure', 'fold_ok' => $this->failure !== 'fixture_failure']],
            );
        }

        if (str_contains($query, 'information_schema.COLUMNS')) {
            $this->metadataPrepares++;
            $charset = $this->failure === 'bad_charset' ? 'latin1' : 'utf8mb4';

            return new ScriptedSearchStatement([
                ['TABLE_NAME' => 'qb_questions', 'COLUMN_NAME' => 'question_text', 'CHARACTER_SET_NAME' => $charset],
                ['TABLE_NAME' => 'qb_questions', 'COLUMN_NAME' => 'explanation', 'CHARACTER_SET_NAME' => $charset],
                ['TABLE_NAME' => 'sample_module_articles', 'COLUMN_NAME' => 'title', 'CHARACTER_SET_NAME' => $charset],
                ['TABLE_NAME' => 'sample_module_articles', 'COLUMN_NAME' => 'summary', 'CHARACTER_SET_NAME' => $charset],
                ['TABLE_NAME' => 'posts', 'COLUMN_NAME' => 'name', 'CHARACTER_SET_NAME' => $charset],
                ['TABLE_NAME' => 'posts', 'COLUMN_NAME' => 'description', 'CHARACTER_SET_NAME' => $charset],
            ]);
        }

        if (str_contains($query, 'sensitive_case_ok')) {
            $this->canonicalFixturePrepares++;

            return new ScriptedSearchStatement([[
                'sensitive_case_ok' => true,
                'sensitive_accent_distinct' => true,
                'folded_ok' => true,
                'canonical_fold_ok' => $this->failure !== 'canonical_failure',
            ]]);
        }

        $this->canonicalFixturePrepares++;

        return new ScriptedSearchStatement([[
            'canonical_fold_ok' => $this->failure !== 'canonical_failure',
        ]]);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->driver === 'pgsql') {
            if (trim($query) === 'SELECT 1') {
                if ($this->transactionAborted) {
                    throw new RuntimeException('transaction aborted');
                }

                return new ScriptedSearchStatement([[1]]);
            }
            $this->catalogQueries++;
            if ($this->failure === 'catalog_failure') {
                return false;
            }
            if ($this->failure === 'catalog_permission') {
                $this->transactionAborted = $this->transaction;
                throw new RuntimeException('permission denied');
            }
            return new ScriptedSearchStatement([[$this->failure === 'missing_extension' ? false : $this->schema]]);
        }

        $this->collationQueries++;
        return new ScriptedSearchStatement([[
            $this->failure === 'missing_collation' ? 'utf8mb4_bin' : 'utf8mb4_bin',
        ], ...($this->failure === 'missing_collation' ? [] : [['utf8mb4_unicode_ci']])]);
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function exec(string $statement): int|false
    {
        $this->executedSql[] = $statement;
        if (str_starts_with($statement, 'ROLLBACK TO SAVEPOINT ')) {
            $this->transactionAborted = false;
        }

        return 0;
    }
}

final class ScriptedSearchStatement extends PDOStatement
{
    private int $cursor = 0;

    /** @param list<array<mixed>> $rows */
    public function __construct(private readonly array $rows) {}

    public function execute(?array $params = null): bool
    {
        $this->cursor = 0;

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[$this->cursor++] ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->rows[$this->cursor++] ?? false;
        return $row === false ? false : array_values($row)[$column] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(static fn (array $row): mixed => array_values($row)[0] ?? false, $this->rows);
        }

        return $this->rows;
    }
}

final class FakeSqliteRegistrationPdo extends PDO
{
    public int $registrationCalls = 0;

    /** @var list<int> */
    public array $flags = [];

    public function __construct(private readonly bool $fail = false) {}

    public function sqliteCreateFunction(
        string $functionName,
        callable $callback,
        int $numArgs = -1,
        int $flags = 0,
    ): bool {
        $this->registrationCalls++;
        $this->flags[] = $flags;

        return ! $this->fail;
    }
}

final class SqlBindingFixtureModel extends Model
{
    public static Connection $testConnection;

    protected $table = 'search_fixture';

    public function getConnection()
    {
        return self::$testConnection;
    }
}
