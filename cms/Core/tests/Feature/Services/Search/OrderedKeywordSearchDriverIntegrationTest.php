<?php

namespace Cms\Core\Tests\Feature\Services\Search;

use Cms\Core\Services\Search\OrderedKeywordSearch;
use Cms\Core\Services\Search\OrderedKeywordSearchCapabilities;
use Cms\Core\Services\Search\OrderedKeywordSearchPatternBuilder;
use Cms\Core\Services\Search\OrderedKeywordSearchSql;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

final class OrderedKeywordSearchDriverIntegrationTest extends TestCase
{
    private bool $createdNondeterministicCollation = false;

    /** @var list<string> */
    private array $createdTables = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdTables) as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            if ($this->createdNondeterministicCollation) {
                DB::statement('DROP COLLATION IF EXISTS "ordered_search_nondeterministic_test"');
            }
        }

        parent::tearDown();
    }

    public function test_mysql_or_mariadb_active_connection_capabilities(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('mysql_or_mariadb inactive');
        }
        $this->assertSame('testing', DB::connection()->getDatabaseName(), 'Driver fixture requires the isolated testing database.');

        $this->createMysqlAdoptedColumnFixtures();
        $this->createFixtureTable();
        $capabilities = (new OrderedKeywordSearchCapabilities())->forConnection(DB::connection(), [
            'ordered_search_driver_fixtures.title',
            'ordered_search_driver_fixtures.description',
        ]);

        $this->assertSame('utf8mb4_bin', $capabilities['sensitive_collation']);
        $this->assertSame('utf8mb4_unicode_ci', $capabilities['folded_collation']);
        (new OrderedKeywordSearchCapabilities())->forConnection(DB::connection(), [
            'qb_questions.question_text',
            'qb_questions.explanation',
            'sample_module_articles.title',
            'sample_module_articles.summary',
            'posts.name',
            'posts.description',
        ]);
        $this->assertDriverSemantics();
        fwrite(STDERR, 'driver_integration driver='.DB::connection()->getDriverName().' version='.DB::scalar('select version()')." capabilities=verified\n");
    }

    public function test_postgresql_active_connection_capabilities(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('postgresql inactive');
        }
        $this->assertSame('testing', DB::connection()->getDatabaseName(), 'Driver fixture requires the isolated testing database.');

        $this->createFixtureTable();
        $capabilities = (new OrderedKeywordSearchCapabilities())->forConnection(DB::connection(), []);

        $this->assertContains($capabilities['fold_strategy'], ['unaccent', 'translate']);
        $this->assertDriverSemantics();
        $this->assertPostgresqlTranslateFallback();
        $databaseCollation = DB::selectOne(<<<'SQL'
SELECT d.datcollate, c.collisdeterministic, c.collprovider
FROM pg_database d
LEFT JOIN pg_collation c ON c.collname = 'default'
    AND c.collnamespace = 'pg_catalog'::regnamespace
WHERE d.datname = current_database()
LIMIT 1
SQL);
        $this->assertTrue($this->databaseBoolean($databaseCollation->collisdeterministic ?? false));
        $nondeterministicStatus = $this->assertPostgresqlNondeterministicIcuFixture();
        fwrite(STDERR, sprintf(
            "driver_integration driver=pgsql default_collation=%s default_provider=%s deterministic_status=%s fold_strategy=%s %s\n",
            $databaseCollation->datcollate ?? 'unknown',
            $databaseCollation->collprovider ?? 'unknown',
            var_export($databaseCollation->collisdeterministic ?? null, true),
            $capabilities['fold_strategy'],
            $nondeterministicStatus,
        ));
    }

    public function test_postgresql_transactional_unaccent_permission_failure_keeps_transaction_usable(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('postgresql inactive');
        }

        $host = getenv('PTG_HOST') ?: (string) config('database.connections.pgsql.host');
        $port = getenv('PTG_PORT') ?: (string) config('database.connections.pgsql.port');
        $database = getenv('PTG_DATABASE') ?: (string) config('database.connections.pgsql.database');
        $username = getenv('PTG_USERNAME') ?: (string) config('database.connections.pgsql.username');
        $password = getenv('PTG_PASSWORD') ?: (string) config('database.connections.pgsql.password');
        $this->assertSame('testing', $database, 'Transactional PostgreSQL fixture requires the isolated testing database.');

        try {
            $pdo = new PDO(
                "pgsql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (Throwable $exception) {
            $this->markTestSkipped('fresh PostgreSQL connection unavailable sqlstate='.(string) $exception->getCode());
        }

        $connection = new PostgresConnection($pdo, $database, '', ['driver' => 'pgsql']);
        $role = 'ordered_keyword_limited_'.bin2hex(random_bytes(4));
        $quotedRole = $this->quotePostgresqlIdentifier($role);
        $extensionCreated = false;
        $roleCreated = false;
        $schemaUsageGranted = false;
        $publicExecuteRevoked = false;
        $publicHadExecute = false;
        $skipReason = null;

        try {
            $schema = $pdo->query(<<<'SQL'
SELECT n.nspname
FROM pg_extension e
JOIN pg_namespace n ON n.oid = e.extnamespace
WHERE e.extname = 'unaccent'
LIMIT 1
SQL)->fetchColumn();
            if (! is_string($schema)) {
                try {
                    $pdo->exec('CREATE EXTENSION unaccent');
                    $extensionCreated = true;
                    $schema = $pdo->query(<<<'SQL'
SELECT n.nspname
FROM pg_extension e
JOIN pg_namespace n ON n.oid = e.extnamespace
WHERE e.extname = 'unaccent'
LIMIT 1
SQL)->fetchColumn();
                } catch (Throwable $exception) {
                    $skipReason = 'unaccent extension unavailable sqlstate='.(string) $exception->getCode();
                }
            }

            if ($skipReason === null && is_string($schema)) {
                $quotedSchema = $this->quotePostgresqlIdentifier($schema);
                $signature = $quotedSchema.'.unaccent(text)';
                $privilege = $pdo->prepare("SELECT has_function_privilege('public', ?::regprocedure, 'EXECUTE')");
                $privilege->execute([$signature]);
                $publicHadExecute = $this->databaseBoolean($privilege->fetchColumn());

                try {
                    $pdo->exec("CREATE ROLE {$quotedRole} NOLOGIN");
                    $roleCreated = true;
                    $pdo->exec("GRANT USAGE ON SCHEMA {$quotedSchema} TO {$quotedRole}");
                    $schemaUsageGranted = true;
                    $pdo->exec("GRANT {$quotedRole} TO CURRENT_USER");
                    $pdo->exec("REVOKE EXECUTE ON FUNCTION {$signature} FROM PUBLIC");
                    $publicExecuteRevoked = true;
                } catch (Throwable $exception) {
                    $skipReason = 'limited-role unaccent fixture unavailable sqlstate='.(string) $exception->getCode();
                }
            }

            if ($skipReason === null) {
                $pdo->beginTransaction();
                $pdo->exec("SET LOCAL ROLE {$quotedRole}");
                $capabilities = (new OrderedKeywordSearchCapabilities())->forConnection($connection, []);

                $this->assertSame('translate', $capabilities['fold_strategy']);
                $this->assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
                $pdo->rollBack();
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($publicExecuteRevoked && $publicHadExecute && isset($signature)) {
                $pdo->exec("GRANT EXECUTE ON FUNCTION {$signature} TO PUBLIC");
            }
            if ($schemaUsageGranted && isset($quotedSchema)) {
                $pdo->exec("REVOKE USAGE ON SCHEMA {$quotedSchema} FROM {$quotedRole}");
            }
            if ($roleCreated) {
                $pdo->exec("DROP ROLE {$quotedRole}");
            }
            if ($extensionCreated) {
                $pdo->exec('DROP EXTENSION unaccent');
            }
        }

        if ($skipReason !== null) {
            $this->markTestSkipped($skipReason);
        }
    }

    private function createFixtureTable(): void
    {
        $this->assertFalse(
            Schema::hasTable('ordered_search_driver_fixtures'),
            'Isolated driver fixture table already exists; refusing to alter it.',
        );
        Schema::create('ordered_search_driver_fixtures', static function (Blueprint $table): void {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });
        $this->createdTables[] = 'ordered_search_driver_fixtures';
    }

    private function createMysqlAdoptedColumnFixtures(): void
    {
        $definitions = [
            'qb_questions' => ['question_text', 'explanation'],
            'sample_module_articles' => ['title', 'summary'],
            'posts' => ['name', 'description'],
        ];

        foreach ($definitions as $tableName => $columns) {
            if (Schema::hasTable($tableName)) {
                continue;
            }

            Schema::create($tableName, static function (Blueprint $table) use ($columns): void {
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
                $table->id();
                foreach ($columns as $column) {
                    $table->text($column)->nullable();
                }
            });
            $this->createdTables[] = $tableName;
        }
    }

    /** @param class-string<Model> $modelClass */
    private function assertDriverSemantics(
        string $table = 'ordered_search_driver_fixtures',
        string $modelClass = DriverSearchFixture::class,
        string $keyword = 'nhiệm vụ tr',
        string $literalKeyword = "100%_đúng!\\",
    ): void
    {
        DB::table($table)->insert([
            ['id' => 1, 'title' => 'NHIỆM VỤ TRẬT TỰ', 'description' => null, 'sort_order' => 0],
            ['id' => 2, 'title' => 'làm nhiệm vụ khẩn cấp trong trường hợp', 'description' => null, 'sort_order' => 0],
            ['id' => 3, 'title' => 'nhiem vu trien khai', 'description' => null, 'sort_order' => 0],
            ['id' => 4, 'title' => 'nhiem nhanh vu trong dot', 'description' => null, 'sort_order' => 0],
            ['id' => 5, 'title' => 'tr trước nhiệm vụ', 'description' => null, 'sort_order' => 0],
            ['id' => 6, 'title' => 'Mức 100%_đúng!\\ cuối', 'description' => null, 'sort_order' => 0],
            ['id' => 7, 'title' => 'Đi đúng hướng', 'description' => null, 'sort_order' => 0],
        ]);

        $started = hrtime(true);
        $query = $modelClass::query();
        (new OrderedKeywordSearch())->apply(
            $query,
            $keyword,
            ["{$table}.title", "{$table}.description"],
            static fn ($builder) => $builder->orderBy("{$table}.sort_order"),
            "{$table}.id",
            'asc',
        );
        $ids = $query->pluck('id')->all();
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
        $planShape = $plan === [] ? 'empty' : implode(',', array_keys((array) $plan[0]));

        $this->assertSame([1, 2, 3, 4], $ids);
        $this->assertNotContains(5, $ids);

        $literal = $modelClass::query();
        (new OrderedKeywordSearch())->apply(
            $literal,
            $literalKeyword,
            ["{$table}.title"],
            static fn (): null => null,
            "{$table}.id",
            'asc',
        );
        $this->assertSame([6], $literal->pluck('id')->all());

        $foldedD = $modelClass::query();
        (new OrderedKeywordSearch())->apply(
            $foldedD,
            'di dung',
            ["{$table}.title"],
            static fn (): null => null,
            "{$table}.id",
            'asc',
        );
        $this->assertSame([7], $foldedD->pluck('id')->all());

        fwrite(STDERR, sprintf(
            "motivating_query_timing driver=%s elapsed_ms=%.3f sql_shape=%s plan_shape=%s ordered_fixture_ids=%s\n",
            DB::connection()->getDriverName(),
            $elapsed,
            preg_replace('/\s+/', ' ', $query->toSql()),
            $planShape,
            implode(',', $ids),
        ));
    }

    private function assertPostgresqlTranslateFallback(): void
    {
        $patterns = new OrderedKeywordSearchPatternBuilder();
        $comparison = (new OrderedKeywordSearchSql())->comparison(
            'pgsql',
            'folded',
            '"ordered_search_driver_fixtures"."title"',
            [
                'fold_strategy' => 'translate',
                'translate_from' => $patterns->postgresTranslateFrom(),
                'translate_to' => $patterns->postgresTranslateTo(),
            ],
        );
        $rows = DB::select(
            "SELECT id FROM ordered_search_driver_fixtures WHERE {$comparison['sql']} ORDER BY id",
            [...$comparison['prefix_bindings'], '%nhiem%vu%tr%'],
        );

        $this->assertSame([1, 2, 3, 4], array_map(static fn (object $row): int => (int) $row->id, $rows));
    }

    private function assertPostgresqlNondeterministicIcuFixture(): string
    {
        $collation = DB::selectOne(<<<'SQL'
SELECT n.nspname, c.collname
FROM pg_collation c
JOIN pg_namespace n ON n.oid = c.collnamespace
WHERE c.collprovider = 'i' AND c.collisdeterministic = false
ORDER BY c.oid
LIMIT 1
SQL);
        $createError = null;

        if (! is_object($collation)) {
            try {
                DB::statement(<<<'SQL'
CREATE COLLATION "ordered_search_nondeterministic_test"
(provider = icu, locale = 'und-u-ks-level1', deterministic = false)
SQL);
                $this->createdNondeterministicCollation = true;
                $collation = (object) [
                    'nspname' => DB::scalar('select current_schema()'),
                    'collname' => 'ordered_search_nondeterministic_test',
                ];
            } catch (Throwable $exception) {
                $createError = method_exists($exception, 'getSqlState')
                    ? $exception->getSqlState()
                    : (string) $exception->getCode();
            }
        }

        if (! is_object($collation)
            || ! is_string($collation->nspname ?? null)
            || ! is_string($collation->collname ?? null)) {
            $status = 'nondeterministic-icu unavailable create_error_code='.($createError ?: 'catalog_absent');
            fwrite(STDERR, "driver_integration {$status}\n");
            $this->addToAssertionCount(1);

            return $status;
        }

        $qualified = $this->quotePostgresqlIdentifier($collation->nspname)
            .'.'.$this->quotePostgresqlIdentifier($collation->collname);
        $this->assertFalse(Schema::hasTable('ordered_search_nondeterministic_fixtures'));
        DB::statement(<<<SQL
CREATE TABLE ordered_search_nondeterministic_fixtures (
    id bigint PRIMARY KEY,
    title text COLLATE {$qualified} NULL,
    description text COLLATE {$qualified} NULL,
    sort_order integer NOT NULL DEFAULT 0
)
SQL);
        $this->createdTables[] = 'ordered_search_nondeterministic_fixtures';
        $this->assertFalse(Schema::hasTable('ordered_search_nondeterministic_inputs'));
        DB::statement(<<<SQL
CREATE TABLE ordered_search_nondeterministic_inputs (
    name text PRIMARY KEY,
    keyword text COLLATE {$qualified} NOT NULL
)
SQL);
        $this->createdTables[] = 'ordered_search_nondeterministic_inputs';
        DB::table('ordered_search_nondeterministic_inputs')->insert([
            ['name' => 'ordered', 'keyword' => 'nhiệm vụ tr'],
            ['name' => 'literal', 'keyword' => "100%_đúng!\\"],
        ]);
        $columnCollation = DB::scalar(<<<'SQL'
SELECT a.attcollation::regcollation::text
FROM pg_attribute a
WHERE a.attrelid = 'ordered_search_nondeterministic_fixtures'::regclass
    AND a.attname = 'title'
SQL);
        $this->assertStringContainsString($collation->collname, (string) $columnCollation);
        $inputCollation = DB::scalar(<<<'SQL'
SELECT a.attcollation::regcollation::text
FROM pg_attribute a
WHERE a.attrelid = 'ordered_search_nondeterministic_inputs'::regclass
    AND a.attname = 'keyword'
SQL);
        $this->assertStringContainsString($collation->collname, (string) $inputCollation);

        $this->assertDriverSemantics(
            'ordered_search_nondeterministic_fixtures',
            NondeterministicDriverSearchFixture::class,
            (string) DB::table('ordered_search_nondeterministic_inputs')->where('name', 'ordered')->value('keyword'),
            (string) DB::table('ordered_search_nondeterministic_inputs')->where('name', 'literal')->value('keyword'),
        );

        return 'nondeterministic-icu full fixture executed collation='
            .$collation->nspname.'.'.$collation->collname;
    }

    private function quotePostgresqlIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function databaseBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}

final class DriverSearchFixture extends Model
{
    public $timestamps = false;

    protected $table = 'ordered_search_driver_fixtures';

    protected $guarded = [];
}

final class NondeterministicDriverSearchFixture extends Model
{
    public $timestamps = false;

    protected $table = 'ordered_search_nondeterministic_fixtures';

    protected $guarded = [];
}
