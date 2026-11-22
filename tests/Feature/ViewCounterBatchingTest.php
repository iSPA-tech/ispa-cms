<?php

namespace Tests\Feature;

use App\Traits\Models\ViewCounterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract for the batched `total_view` resolution.
 *
 * `total_view` is appended on Post, Product and Gallery, so it is resolved once
 * per row while the response serializes. Resolving it row by row cost one SUM
 * aggregate over `tracks` per row — 36 of them behind a single featured
 * category page on production, 27 ms of SQL for a value the public site never
 * even reads. withTotalView() answers the batch with one grouped aggregate.
 *
 * @author iSPA Team
 * @since  8.1.0 (2026-08-03)
 */
class ViewCounterBatchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        cache()->flush();

        //  The store is mode dependent, so every case pins it explicitly.
        config(['tracking.read_mode' => 'raw']);

        //  The memo is per process, and the process spans the whole suite.
        ViewCounterWidget::flushTotalViewMemo();
        OtherViewCounterWidget::flushTotalViewMemo();

        Schema::dropIfExists('view_counter_widgets');
        Schema::create('view_counter_widgets', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        if (! Schema::hasTable('tracks')) {
            Schema::create('tracks', static function (Blueprint $table): void {
                $table->id();
                $table->nullableMorphs('target');
                $table->unsignedBigInteger('view')->default(0);
                $table->timestamps();
            });
        }

        DB::table('tracks')->delete();

        DB::table('view_counter_widgets')->insert([
            ['id' => 1, 'name' => 'one'],
            ['id' => 2, 'name' => 'two'],
            ['id' => 3, 'name' => 'three'],
        ]);

        $morph = ViewCounterWidget::class;
        DB::table('tracks')->insert([
            ['target_type' => $morph, 'target_id' => 1, 'view' => 4],
            ['target_type' => $morph, 'target_id' => 1, 'view' => 6],
            ['target_type' => $morph, 'target_id' => 2, 'view' => 5],
            // id 3 has no rows at all, and must still resolve to 0.
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('view_counter_widgets');

        parent::tearDown();
    }

    /** One grouped aggregate covers the whole result set. */
    public function test_with_total_view_resolves_the_batch_in_one_query(): void
    {
        $widgets = ViewCounterWidget::query()->withTotalView()->orderBy('id')->get();

        $aggregates = 0;
        DB::listen(static function ($event) use (&$aggregates): void {
            if (str_contains($event->sql, 'sum(`view`)') || str_contains($event->sql, 'sum("view")')) {
                $aggregates++;
            }
        });

        $totals = $widgets->map(static fn (ViewCounterWidget $widget): int => $widget->total_view)->all();

        $this->assertSame([10, 5, 0], $totals);
        $this->assertSame(0, $aggregates, 'serialization must not re-run the aggregate');
    }

    /** Without the scope each row still resolves, one aggregate at a time. */
    public function test_without_the_scope_each_row_falls_back_to_its_own_aggregate(): void
    {
        $widget = ViewCounterWidget::query()->find(1);

        $this->assertSame(10, $widget->total_view);
    }

    public function test_raw_total_view_uses_connection_grammar_for_view_column(): void
    {
        $aggregateSql = null;
        DB::listen(static function ($event) use (&$aggregateSql): void {
            if (str_contains($event->sql, 'sum(')) {
                $aggregateSql = $event->sql;
            }
        });

        $this->assertSame(10, ViewCounterWidget::query()->find(1)->total_view);
        $this->assertNotNull($aggregateSql);
        $this->assertStringContainsString(
            'sum(' . DB::connection()->getQueryGrammar()->wrap('view') . ') as total',
            $aggregateSql,
        );
    }

    /**
     * A model is never handed another model's total.
     *
     * The cache key used to be hardcoded to the posts bucket, so a Product and
     * a Post sharing an id also shared a cached total.
     */
    public function test_totals_are_scoped_per_model_class(): void
    {
        DB::table('tracks')->insert([
            ['target_type' => OtherViewCounterWidget::class, 'target_id' => 1, 'view' => 999],
        ]);

        $this->assertSame(10, ViewCounterWidget::query()->find(1)->total_view);
        $this->assertSame(999, OtherViewCounterWidget::query()->find(1)->total_view);
    }

    /**
     * In aggregate read mode the total comes from `tracking_target_totals`.
     *
     * A site whose write mode has rotated to aggregate stops writing `tracks`
     * entirely, so the legacy SUM reports 0 for everything published after the
     * cutover — the public article header rendered that 0.
     */
    public function test_aggregate_read_mode_reads_the_entity_total(): void
    {
        $this->useAggregateTotals();

        DB::table('tracking_target_totals')->insert([
            [
                'target_key' => 'view-counter-widget:1',
                'baseline_views' => 900,
                'live_views' => 100,
            ],
            [
                'target_key' => 'view-counter-widget:2',
                'baseline_views' => 0,
                'live_views' => 7,
            ],
            // id 3 has no aggregate row at all, and must still resolve to 0.
        ]);

        $totals = ViewCounterWidget::query()
            ->withTotalView()
            ->orderBy('id')
            ->get()
            ->map(static fn (ViewCounterWidget $widget): int => $widget->total_view)
            ->all();

        $this->assertSame([1000, 7, 0], $totals);
        $this->assertSame(1000, ViewCounterWidget::query()->find(1)->total_view);
    }

    /** An unmapped model resolves to 0 instead of leaking another key's total. */
    public function test_aggregate_read_mode_ignores_models_without_a_target_alias(): void
    {
        $this->useAggregateTotals();

        DB::table('tracking_target_totals')->insert([
            [
                'target_key' => 'view-counter-widget:1',
                'baseline_views' => 900,
                'live_views' => 100,
            ],
        ]);

        $this->assertSame(0, OtherViewCounterWidget::query()->find(1)->total_view);
    }

    /** Switch the trait to the aggregate store and give it a mapped alias. */
    private function useAggregateTotals(): void
    {
        config([
            'tracking.read_mode' => 'aggregate',
            'tracking.target_aliases' => [
                ViewCounterWidget::class => 'view-counter-widget',
            ],
        ]);

        if (! Schema::hasTable('tracking_target_totals')) {
            Schema::create('tracking_target_totals', static function (Blueprint $table): void {
                $table->id();
                $table->string('target_key', 191)->unique();
                $table->bigInteger('baseline_views')->default(0);
                $table->bigInteger('live_views')->default(0);
                $table->timestamps();
            });
        }

        DB::table('tracking_target_totals')->delete();
    }
}

class ViewCounterWidget extends Model
{
    use ViewCounterTrait;

    public $timestamps = false;

    protected $table = 'view_counter_widgets';

    protected $appends = ['total_view'];
}

class OtherViewCounterWidget extends Model
{
    use ViewCounterTrait;

    public $timestamps = false;

    protected $table = 'view_counter_widgets';

    protected $appends = ['total_view'];
}
