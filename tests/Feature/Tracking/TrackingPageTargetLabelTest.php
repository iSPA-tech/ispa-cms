<?php

use App\Constants\Tables;
use App\Http\Controllers\V2\Admin\TrackController;
use App\Jobs\TopRangeAggregationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
});

afterEach(function (): void {
    TrackingTestSchema::drop();
});

/**
 * The controller resolves its repositories through the container and the admin
 * auth stack; neither matters for label resolution, so the methods under test
 * are exercised on a bare instance.
 */
function pageTargetController(): object
{
    return new class extends TrackController {
        public function __construct()
        {
            // the parent wires repositories and admin auth, none of which the
            // label resolution touches
        }

        public function label(string $targetKey, ?string $publicPath = null): array
        {
            return $this->resolvePageTarget($targetKey, $publicPath);
        }

        public function paths(\Illuminate\Support\Collection $items): \Illuminate\Support\Collection
        {
            return $this->pagePathsFor($items);
        }
    };
}

function topRow(string $targetKey, string $targetType = TopRangeAggregationJob::ROUTE_TYPE): object
{
    return (object) [
        'target_key' => $targetKey,
        'target_type' => $targetType,
        'target_id' => 0,
        'views' => 1,
    ];
}

test('a hashed path target is labelled with the readable path from the catalog', function (): void {
    $key = 'path:sha256:' . hash('sha256', '/xe/toyota');

    expect(pageTargetController()->label($key, '/xe/toyota'))->toBe([
        'name' => '/xe/toyota',
        'url' => '/xe/toyota',
    ]);
});

test('a hashed path target without a catalog row never masquerades as a route', function (): void {
    $key = 'path:sha256:' . hash('sha256', '/xe/toyota');

    //  Str::after() returns the whole string when the needle is absent, so an
    //  unguarded 'route:' strip used to hand the raw hash to the router and
    //  then print it as the page name.
    expect(pageTargetController()->label($key, null))->toBe([
        'name' => $key,
        'url' => null,
    ]);
});

test('a named route target still resolves through the router', function (): void {
    Route::get('/lien-he', static fn (): string => 'ok')->name('contact');
    Route::getRoutes()->refreshNameLookups();

    expect(pageTargetController()->label('route:contact', null))->toBe([
        'name' => '/lien-he',
        'url' => '/lien-he',
    ]);
});

test('catalog paths are read once for the page rows only', function (): void {
    $home = 'path:sha256:' . hash('sha256', '/');
    $brand = 'path:sha256:' . hash('sha256', '/xe/toyota');

    DB::table(Tables::TRACKING_TARGET_CATALOG)->insert([
        ['target_key' => $home, 'target_kind' => 'path', 'public_path' => '/', 'is_rankable' => true],
        ['target_key' => $brand, 'target_kind' => 'path', 'public_path' => '/xe/toyota', 'is_rankable' => true],
        ['target_key' => 'post:5', 'target_kind' => 'entity', 'public_path' => null, 'is_rankable' => true],
    ]);

    $items = collect([
        topRow($home),
        topRow($brand),
        topRow('post:5', 'Cms\\Content\\Models\\Post'),
    ]);

    DB::enableQueryLog();
    $paths = pageTargetController()->paths($items);
    $queries = collect(DB::getQueryLog())
        // the schema probe also names the table, so match the read itself
        ->filter(static fn (array $query): bool => str_contains($query['query'], 'public_path'))
        ->count();
    DB::disableQueryLog();

    expect($paths->all())->toEqual([$home => '/', $brand => '/xe/toyota'])
        ->and($queries)->toBe(1);
});

test('no page rows means no catalog query at all', function (): void {
    $items = collect([topRow('post:5', 'Cms\\Content\\Models\\Post')]);

    expect(pageTargetController()->paths($items)->all())->toBe([]);
});
