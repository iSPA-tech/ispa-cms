<?php

namespace Cms\Core\Tests\Feature\Services\Search;

use Cms\Core\Services\Search\OrderedKeywordSearch;
use Cms\Core\Services\Search\OrderedKeywordSearchCapabilities;
use Cms\Core\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

final class OrderedKeywordSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ordered_search_fixtures');
        Schema::create('ordered_search_fixtures', static function (Blueprint $table): void {
            $table->id();
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            for ($index = 3; $index <= 9; $index++) {
                $table->text("column_{$index}")->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ordered_search_fixtures');
        parent::tearDown();
    }

    public function test_sqlite_orders_all_four_tiers_and_global_tier_beats_field_priority(): void
    {
        $this->insert(1, 'NHIỆM VỤ TRẬT TỰ', null);
        $this->insert(2, 'làm nhiệm vụ khẩn cấp trong trường hợp', null);
        $this->insert(3, 'nhiem vu trien khai', null);
        $this->insert(4, 'nhiem nhanh vu trong dot', null);
        $this->insert(5, 'nhiem vu khac', 'nhiệm vụ tr secondary');
        $this->insert(6, 'trước rồi nhiệm vụ', null);

        $ids = $this->search('nhiệm vụ tr')->pluck('id')->all();

        $this->assertSame([1, 5, 2, 3, 4], $ids);
        $this->assertNotContains(6, $ids);
    }

    public function test_sqlite_accentless_query_folds_accented_storage(): void
    {
        $this->insert(1, 'NHIỆM VỤ TRẬT TỰ', null);

        $this->assertSame([1], $this->search('nhiem vu tr')->pluck('id')->all());
    }

    public function test_sqlite_treats_wildcards_escape_and_backslash_as_literals(): void
    {
        $this->insert(1, "Mức 100%_đúng!\\ cuối", null);
        $this->insert(2, "Mức 100XXđúng!\\ cuối", null);

        $this->assertSame([1], $this->search("100%_đúng!\\")->pluck('id')->all());
    }

    public function test_nulls_do_not_match_and_unique_direction_breaks_complete_ties(): void
    {
        $this->insert(1, null, null, 1);
        $this->insert(2, 'nhiệm vụ trật tự', null, 1);
        $this->insert(3, 'nhiệm vụ trật tự', null, 1);

        $ids = $this->search('nhiệm vụ tr', 'desc')->pluck('id')->all();

        $this->assertSame([3, 2], $ids);
    }

    public function test_empty_input_is_true_noop_and_does_not_call_callback(): void
    {
        $query = OrderedSearchFixture::query()->orderByDesc('sort_order');
        $beforeSql = $query->toSql();
        $beforeBindings = $query->getBindings();
        $calls = 0;

        $result = (new OrderedKeywordSearch())->apply(
            $query,
            " \t\n ",
            ['ordered_search_fixtures.title'],
            static function () use (&$calls): void {
                $calls++;
            },
            'ordered_search_fixtures.id',
            'asc',
        );

        $this->assertSame($query, $result);
        $this->assertSame($beforeSql, $result->toSql());
        $this->assertSame($beforeBindings, $result->getBindings());
        $this->assertSame(0, $calls);
    }

    public function test_callback_runs_once_between_relevance_and_unique_order_and_exception_propagates(): void
    {
        $calls = 0;
        $query = OrderedSearchFixture::query();

        (new OrderedKeywordSearch())->apply(
            $query,
            'nhiệm vụ',
            ['ordered_search_fixtures.title'],
            static function ($builder) use (&$calls): void {
                $calls++;
                $builder->orderBy('ordered_search_fixtures.sort_order');
            },
            'ordered_search_fixtures.id',
            'desc',
        );

        $sql = $query->toSql();
        $this->assertSame(1, $calls);
        $this->assertMatchesRegularExpression('/order by CASE .* asc, "ordered_search_fixtures"\."sort_order" asc, "ordered_search_fixtures"\."id" desc/is', $sql);

        $this->expectException(RuntimeException::class);
        (new OrderedKeywordSearch())->apply(
            OrderedSearchFixture::query(),
            'nhiệm vụ',
            ['ordered_search_fixtures.title'],
            static fn () => throw new RuntimeException('callback failed'),
            'ordered_search_fixtures.id',
            'asc',
        );
    }

    public function test_score_projection_keeps_full_model_hydration_and_pagination_stable(): void
    {
        $this->insert(1, 'nhiệm vụ trật tự', 'kept', 2);
        $this->insert(2, 'nhiệm vụ trật tự', 'also kept', 1);
        Paginator::currentPageResolver(static fn (): int => 1);

        $query = OrderedSearchFixture::query();
        (new OrderedKeywordSearch())->apply(
            $query,
            'nhiệm vụ tr',
            ['ordered_search_fixtures.title', 'ordered_search_fixtures.description'],
            static fn ($builder) => $builder->orderBy('ordered_search_fixtures.sort_order'),
            'ordered_search_fixtures.id',
            'asc',
            'keyword_score',
        );

        $page = $query->paginate(1);
        $model = $page->items()[0];

        $this->assertSame(2, $page->total());
        $this->assertSame('also kept', $model->description);
        $this->assertSame(0, $model->keyword_score);
    }

    public function test_reconnect_registers_sqlite_functions_for_new_pdo(): void
    {
        $this->insert(1, 'nhiệm vụ', null);
        $this->assertSame([1], $this->search('nhiem')->pluck('id')->all());

        DB::disconnect();
        DB::reconnect();
        (new OrderedKeywordSearchCapabilities())->forConnection(DB::connection(), []);

        $this->assertSame('nhiem', DB::scalar("select vn_ascii_fold('NHIỆM')"));
    }

    public function test_motivating_query_timing_reports_only_safe_shape_and_ids(): void
    {
        $this->insert(1, 'làm nhiệm vụ khẩn cấp trong trường hợp', null);
        $query = $this->baseSearch('nhiệm vụ tr');
        $started = hrtime(true);
        $ids = $query->pluck('id')->all();
        $elapsed = (hrtime(true) - $started) / 1_000_000;

        fwrite(STDERR, sprintf(
            "motivating_query_timing driver=sqlite elapsed_ms=%.3f sql_shape=%s ordered_fixture_ids=%s\n",
            $elapsed,
            preg_replace('/\s+/', ' ', $query->toSql()),
            implode(',', $ids),
        ));

        $this->assertSame([1], $ids);
    }

    public function test_invalid_identifiers_alias_direction_and_utf8_fail_before_sql(): void
    {
        $service = new OrderedKeywordSearch();
        $validCallback = static fn (): null => null;

        foreach ([
            [['bad.column.extra'], 'id', 'asc', null, 'word'],
            [['title'], 'bad.id.extra', 'asc', null, 'word'],
            [['title'], 'id', 'ASC', null, 'word'],
            [['title'], 'id', 'asc', 'bad.alias', 'word'],
            [['title'], 'id', 'asc', null, "\xFF"],
        ] as [$columns, $unique, $direction, $alias, $keyword]) {
            try {
                $service->apply(OrderedSearchFixture::query(), $keyword, $columns, $validCallback, $unique, $direction, $alias);
                $this->fail('Invalid search input must fail.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_column_error_reports_only_position(): void
    {
        try {
            (new OrderedKeywordSearch())->apply(
                OrderedSearchFixture::query(),
                'word',
                ['ordered_search_fixtures.title', 'private.payload.secret'],
                static fn (): null => null,
                'ordered_search_fixtures.id',
                'asc',
            );
            $this->fail('Invalid column must throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid search column at position 1.', $exception->getMessage());
            $this->assertStringNotContainsString('private', $exception->getMessage());
        }
    }

    public function test_nonblank_search_rebuilds_existing_orders_before_relevance_and_final_unique_direction(): void
    {
        $this->insert(1, 'nhiệm vụ trật tự', null, 5);
        $this->insert(2, 'nhiệm xen vụ rồi tr', null, 1);
        $this->insert(3, 'nhiệm vụ trật tự', null, 5);

        $query = OrderedSearchFixture::query()
            ->orderBy('ordered_search_fixtures.id')
            ->orderByDesc('ordered_search_fixtures.sort_order');
        (new OrderedKeywordSearch())->apply(
            $query,
            'nhiệm vụ tr',
            ['ordered_search_fixtures.title'],
            static fn ($builder) => $builder->orderByDesc('ordered_search_fixtures.sort_order'),
            'ordered_search_fixtures.id',
            'desc',
        );

        $this->assertSame([3, 1, 2], $query->pluck('id')->all());
        $this->assertMatchesRegularExpression(
            '/order by CASE .* ASC, "ordered_search_fixtures"\."sort_order" desc, "ordered_search_fixtures"\."id" desc/is',
            $query->toSql(),
        );
        $this->assertStringNotContainsString('"ordered_search_fixtures"."id" asc', $query->toSql());
    }

    public function test_nine_trusted_columns_pass_and_the_ninth_column_keeps_field_priority(): void
    {
        $this->row(['id' => 1, 'column_9' => 'nhiệm vụ trật tự']);
        $this->row(['id' => 2, 'column_3' => 'nhiem vu khac']);
        $this->row(['id' => 3, 'title' => 'nhiệm vụ']);
        $this->row(['id' => 4, 'column_9' => 'không liên quan']);

        $query = $this->nineColumnSearch('nhiệm vụ');

        // Score is tier * 100 + field index: id 3 = 0, id 1 = 8 (ninth field), id 2 = 202.
        $this->assertSame([3, 1, 2], $query->pluck('id')->all());
        $this->assertStringContainsString('THEN 8', $query->toSql());
    }

    public function test_more_than_nine_or_no_trusted_columns_still_fail_before_sql(): void
    {
        foreach ([$this->columns(10), []] as $columns) {
            try {
                (new OrderedKeywordSearch())->apply(
                    OrderedSearchFixture::query(),
                    'nhiệm vụ',
                    $columns,
                    static fn (): null => null,
                    'ordered_search_fixtures.id',
                    'asc',
                );
                $this->fail('A column list outside one to nine must fail.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Search requires one to nine trusted columns.', $exception->getMessage());
            }
        }
    }

    public function test_callers_with_eight_or_fewer_columns_keep_membership_and_order(): void
    {
        $this->insert(1, 'NHIỆM VỤ TRẬT TỰ', null);
        $this->insert(2, 'làm nhiệm vụ khẩn cấp trong trường hợp', null);
        $this->insert(3, 'nhiem vu trien khai', null);
        $this->insert(4, 'nhiem nhanh vu trong dot', null);
        $this->insert(5, 'nhiem vu khac', 'nhiệm vụ tr secondary');
        $this->insert(6, 'trước rồi nhiệm vụ', null);

        $ordered = static function (array $columns): array {
            $query = OrderedSearchFixture::query();
            (new OrderedKeywordSearch())->apply(
                $query,
                'nhiệm vụ tr',
                $columns,
                static fn ($builder) => $builder->orderBy('ordered_search_fixtures.sort_order'),
                'ordered_search_fixtures.id',
                'asc',
            );

            return $query->pluck('id')->all();
        };

        $this->assertSame([1, 2, 3, 4], $ordered(['ordered_search_fixtures.title']));
        $this->assertSame([1, 5, 2, 3, 4], $ordered($this->columns(2)));
        $this->assertSame([1, 5, 2, 3, 4], $ordered($this->columns(8)));

        $sql = $this->nineColumnSearch('nhiệm vụ', 8)->toSql();
        $this->assertStringContainsString('THEN 7', $sql);
        $this->assertStringNotContainsString('THEN 8', $sql);
    }

    public function test_percent_underscore_and_bang_stay_literal_across_nine_columns(): void
    {
        $this->row(['id' => 1, 'title' => 'Giảm 100% giá']);
        $this->row(['id' => 2, 'title' => 'Giảm 100X giá']);
        $this->row(['id' => 3, 'column_9' => 'a_b']);
        $this->row(['id' => 4, 'column_9' => 'axb']);
        $this->row(['id' => 5, 'description' => 'Wow! thật']);
        $this->row(['id' => 6, 'description' => 'Wow? thật']);
        $this->row(['id' => 7, 'column_5' => '50%']);
        $this->row(['id' => 8, 'column_5' => '50 phần trăm']);

        $this->assertSame([1], $this->nineColumnSearch('100%')->pluck('id')->all());
        $this->assertSame([3], $this->nineColumnSearch('a_b')->pluck('id')->all());
        $this->assertSame([5], $this->nineColumnSearch('wow!')->pluck('id')->all());
        $this->assertSame([1, 7], $this->nineColumnSearch('%')->pluck('id')->all());
        $this->assertSame([3], $this->nineColumnSearch('_')->pluck('id')->all());
        $this->assertSame([5], $this->nineColumnSearch('!')->pluck('id')->all());
        $this->assertSame([], $this->nineColumnSearch('100_')->pluck('id')->all());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function row(array $row): void
    {
        DB::table('ordered_search_fixtures')->insert($row + ['sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return list<string>
     */
    private function columns(int $count): array
    {
        $columns = ['ordered_search_fixtures.title', 'ordered_search_fixtures.description'];

        for ($index = 3; $index <= $count; $index++) {
            $columns[] = "ordered_search_fixtures.column_{$index}";
        }

        return array_slice($columns, 0, $count);
    }

    private function nineColumnSearch(string $keyword, int $count = 9)
    {
        $query = OrderedSearchFixture::query();

        return (new OrderedKeywordSearch())->apply(
            $query,
            $keyword,
            $this->columns($count),
            static fn ($builder) => $builder->orderBy('ordered_search_fixtures.sort_order'),
            'ordered_search_fixtures.id',
            'asc',
        );
    }

    private function search(string $keyword, string $direction = 'asc')
    {
        return $this->baseSearch($keyword, $direction)->get();
    }

    private function baseSearch(string $keyword, string $direction = 'asc')
    {
        $query = OrderedSearchFixture::query();
        return (new OrderedKeywordSearch())->apply(
            $query,
            $keyword,
            ['ordered_search_fixtures.title', 'ordered_search_fixtures.description'],
            static fn ($builder) => $builder->orderBy('ordered_search_fixtures.sort_order'),
            'ordered_search_fixtures.id',
            $direction,
        );
    }

    private function insert(int $id, ?string $title, ?string $description, int $sortOrder = 0): bool
    {
        return DB::table('ordered_search_fixtures')->insert([
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

final class OrderedSearchFixture extends Model
{
    protected $table = 'ordered_search_fixtures';

    protected $guarded = [];
}
