<?php

use App\Constants\Tables;
use App\Events\SaveTracking;
use App\Jobs\ProcessTrackingEvent;
use App\Listeners\SaveTrackingHandle;
use App\Models\Tracking\TrackingRuntimeState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Tests\Support\TrackingTestSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    TrackingTestSchema::create();
    Cache::flush();
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-23 17:30:00 UTC');
    config([
        'app.key' => 'tracking-concurrency-test-key',
        'tracking.enabled' => true,
        'tracking.public_hosts' => ['example.com'],
        'tracking.public_path_patterns' => [],
    ]);
    TrackingRuntimeState::query()->whereKey(1)->update([
        'mode' => TrackingRuntimeState::MODE_AGGREGATE,
        'direct_counter_owner' => 'event',
        'first_direct_write_date' => '2026-07-24',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TrackingTestSchema::drop();
});

test('two event ids share one target day row and both atomic deltas survive', function (): void {
    $events = collect([str_repeat('x', 40), str_repeat('y', 40)])
        ->map(function (string $sessionId): SaveTracking {
            $request = Request::create('https://example.com/articles/42', 'GET');
            $request->headers->set('User-Agent', 'Aggregate Concurrency Test');
            app()->instance('request', $request);
            Session::setId($sessionId);
            Session::start();

            $event = new SaveTracking(
                ['page_view'],
                'Cms\\Content\\Models\\Post',
                42
            );
            app(SaveTrackingHandle::class)->handle($event);

            return $event;
        });

    foreach ($events as $event) {
        app()->call([(new ProcessTrackingEvent($event->eventId)), 'handle']);
    }

    expect(DB::table(Tables::TRACKING_TARGET_DAILY)->count())->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_DAILY)->value('total_views'))->toBe(2)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->count())->toBe(1)
        ->and(DB::table(Tables::TRACKING_TARGET_TOTALS)->value('live_views'))->toBe(2)
        ->and(DB::table(Tables::TRACKING_SITE_TOTALS)->where('id', 1)->value('live_views'))->toBe(2);
});
