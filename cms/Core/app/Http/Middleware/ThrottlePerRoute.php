<?php

namespace Cms\Core\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Numeric rate limit that counts each route on its own (`throttle.route:N,M`).
 *
 * Laravel keys an unnamed `throttle:N,M` limit by the signed-in user (or by
 * domain + client IP for guests) only; neither the limit values nor the route
 * are part of the key. Every unnamed numeric throttle a client reaches
 * therefore shares one counter: a route-level limit inside a throttled group
 * counts each request twice, sibling route-level limits drain each other, and
 * a busy screen in one module can push an unrelated route past its cap
 * (a client app, 2026-10-22: silent HTTP 429 on `account/parish-workspace`).
 *
 * This subclass keeps the stock behavior, response and priority (as a subclass
 * of ThrottleRequests the Kernel middleware priority still places it after
 * authentication) and only widens the key with the HTTP method and the route
 * URI template, so a limit applies to its own route per user (per client IP
 * for guests). Route parameters stay out of the key on purpose: the limit
 * covers the route, not each individual record.
 *
 * Use it for limits attached to one route. A limit meant as one budget for a
 * whole group keeps the stock middleware with its own key prefix (Laravel's
 * third throttle parameter), e.g. `throttle:20,1,v2-client:`, so it no longer
 * shares the default counter either.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    /**
     * Resolve the throttle key signature for the current request.
     *
     * Extends the default user/IP signature with the HTTP method and the route
     * URI template so each route-level limit gets its own counter.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $uri = $route !== null ? $route->uri() : $request->path();

        $signature = parent::resolveRequestSignature($request) . '|' . $request->method() . '|' . $uri;

        return static::$shouldHashKeys ? sha1($signature) : $signature;
    }
}
