<?php

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Contract guard: no registered route passes through two unnamed numeric
 * throttles that share Laravel's default per-user/IP key.
 *
 * Stacked unnamed `throttle:N,M` limits (a group limit plus a route limit, or
 * two limits in one middleware list) count every request once per limit on the
 * same counter, so the lower limit trips long before its own cap. Route-level
 * limits use `throttle.route:N,M` (Cms\Core\Http\Middleware\ThrottlePerRoute);
 * stacked group budgets carry their own key prefix (`throttle:N,M,scope:`).
 */
final class RouteThrottleKeyIsolationTest extends TestCase
{
    /**
     * Every route carries at most one throttle on the default shared key.
     */
    public function test_no_route_stacks_two_unnamed_numeric_throttles(): void
    {
        $sharedKeyThrottle = '/^(throttle|' . preg_quote(ThrottleRequests::class, '/') . '):\d+,\d+$/';
        $stacked = [];

        foreach (Route::getRoutes() as $route) {
            $shared = array_values(array_filter(
                // Group + route middleware as declared; gatherMiddleware() would also
                // build every controller, and some constructors query the database.
                $route->middleware(),
                static fn ($middleware): bool => is_string($middleware) && preg_match($sharedKeyThrottle, $middleware) === 1
            ));

            if (count($shared) > 1) {
                $stacked[] = implode('|', $route->methods()) . ' ' . $route->uri() . ' => ' . implode(', ', $shared);
            }
        }

        $this->assertSame([], $stacked, "Routes stacking unnamed numeric throttles on one shared counter:\n" . implode("\n", $stacked));
    }
}
