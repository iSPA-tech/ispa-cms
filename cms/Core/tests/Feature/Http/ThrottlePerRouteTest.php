<?php

namespace Cms\Core\Tests\Feature\Http;

use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;

/**
 * Regression guard for the shared numeric throttle counter.
 *
 * An unnamed `throttle:N,M` is keyed by user (or domain + IP) only, so a group
 * limit and a route limit used to count every request twice and two
 * route-level limits drained each other (a client app, 2026-10-22). `throttle.route`
 * (ThrottlePerRoute) gives each route its own counter. The routes below are
 * registered by the test itself so nothing but the throttles is exercised;
 * JSON requests keep the 429 rendering free of view composers.
 */
final class ThrottlePerRouteTest extends TestCase
{
    /**
     * Skip the global redirects (HTTPS, locale prefix) unrelated to throttling.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([HttpsProtocol::class, Localization::class]);
    }

    /**
     * Root cause, kept as a control: stock unnamed throttles share one counter.
     */
    public function test_stock_unnamed_group_and_route_throttles_share_one_counter(): void
    {
        Route::middleware('throttle:6,1')->group(static function (): void {
            Route::get('__throttle-test/stock/limited', static fn () => 'ok')->middleware('throttle:4,1');
        });

        // Each call is counted by the group and by the route on the same key,
        // so the route's 4/min cap is reached on the third call.
        $this->getJson('/__throttle-test/stock/limited')->assertOk();
        $this->getJson('/__throttle-test/stock/limited')->assertOk();
        $this->getJson('/__throttle-test/stock/limited')->assertStatus(429);
    }

    /**
     * A route limit inside a throttled group counts each request once, and
     * both limits still apply at their own cap.
     */
    public function test_group_limit_and_route_limit_no_longer_share_a_counter(): void
    {
        Route::middleware('throttle:6,1')->group(static function (): void {
            Route::get('__throttle-test/group/limited', static fn () => 'ok')->middleware('throttle.route:4,1');
            Route::get('__throttle-test/group/open', static fn () => 'ok');
        });

        for ($call = 1; $call <= 4; $call++) {
            $this->getJson('/__throttle-test/group/limited')->assertOk();
        }

        // Fifth call: the route's own 4/min cap.
        $this->getJson('/__throttle-test/group/limited')->assertStatus(429);

        // The group counter saw five requests, so its 6/min budget has one left.
        $this->getJson('/__throttle-test/group/open')->assertOk();
        $this->getJson('/__throttle-test/group/open')->assertStatus(429);
    }

    /**
     * Two route-level limits of the same signed-in user no longer drain each
     * other, and each route is still cut off at its own cap.
     */
    public function test_two_throttled_routes_no_longer_drain_each_other(): void
    {
        Route::get('__throttle-test/routes/first', static fn () => 'ok')->middleware('throttle.route:3,1');
        Route::get('__throttle-test/routes/second', static fn () => 'ok')->middleware('throttle.route:3,1');

        $this->actingAs((new User())->forceFill(['id' => 9801]));

        for ($call = 1; $call <= 3; $call++) {
            $this->getJson('/__throttle-test/routes/first')->assertOk();
        }
        $this->getJson('/__throttle-test/routes/first')->assertStatus(429);

        for ($call = 1; $call <= 3; $call++) {
            $this->getJson('/__throttle-test/routes/second')->assertOk();
        }
        $this->getJson('/__throttle-test/routes/second')->assertStatus(429);
    }
}
