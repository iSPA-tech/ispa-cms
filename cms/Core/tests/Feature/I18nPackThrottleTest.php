<?php

namespace Cms\Core\Tests\Feature;

use App\Http\Middleware\ApiLocalization;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Cms\Core\Tests\TestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * the public i18n pack and manifest routes must not drain the shared
 * per-IP `api` budget (60/min). One admin page load fetches ~15 shards.
 */
final class I18nPackThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class]);

        // Existing packs answer 200 without the slow not-found logging path.
        Storage::fake('public');
        Storage::disk('public')->put('i18n/1.0.0/adm/vi/a.json', '{}');
        Storage::disk('public')->put('i18n/1.0.0/adm/vi/b.json', '{}');
    }

    public function test_pack_routes_use_their_own_limit_instead_of_the_api_limiter(): void
    {
        foreach (['api/i18n/app/manifest.json', 'api/i18n/{file}', 'api/{version}/i18n/{path}/{locale}/{character}.json'] as $uri) {
            $route = Route::getRoutes()->match(
                \Illuminate\Http\Request::create('/' . str_replace(
                    ['{file}', '{version}', '{path}', '{locale}', '{character}'],
                    ['vi.json', '1.0.0', 'adm', 'vi', 'a'],
                    $uri
                ))
            );
            $excluded = $route->excludedMiddleware();
            $middleware = $route->middleware();

            $this->assertContains(ThrottleRequests::class . ':api', $excluded, $uri);
            $this->assertContains('throttle.route:600,1', $middleware, $uri);
        }
    }

    public function test_many_pack_requests_from_one_ip_are_not_throttled_by_the_api_limit(): void
    {
        for ($i = 0; $i < 70; $i++) {
            $status = $this->get('/api/1.0.0/i18n/adm/vi/a.json')->getStatusCode();
            $this->assertNotSame(429, $status, "request $i was throttled");
        }
    }

    public function test_pack_routes_are_still_limited(): void
    {
        // The 600/min budget is reported on the response and runs out; the throttle
        // exception may render as 429 or as a generic error depending on the test handler.
        $first = $this->get('/api/1.0.0/i18n/adm/vi/b.json');
        $this->assertSame('600', $first->headers->get('x-ratelimit-limit'));

        $blocked = false;
        for ($i = 0; $i < 610 && ! $blocked; $i++) {
            $blocked = $this->get('/api/1.0.0/i18n/adm/vi/b.json')->getStatusCode() !== 200;
        }

        $this->assertTrue($blocked, 'pack route has no limit');
    }

    /**
     * the numbers behind the fix. A cold admin load asks for the manifest and about 15 shards; ten cold loads in
     * a minute from one IP (150 shard requests) stay far below the pack budget, and so do the same loads revalidated
     * with If-None-Match: a 304 still counts against the limit (the throttle runs before the answer), so the budget must
     * hold both kinds.
     */
    public function test_ten_quick_cold_loads_and_ten_revalidated_loads_stay_under_the_pack_limit(): void
    {
        $shards = [];
        foreach (range(1, 15) as $n) {
            $shards[] = 'shard' . $n;
            Storage::disk('public')->put('i18n/1.0.0/adm/vi/shard' . $n . '.json', '{"k":' . $n . '}');
        }
        $etags = [];
        $statuses = [];

        for ($load = 0; $load < 10; $load++) {
            foreach ($shards as $shard) {
                $response = $this->get('/api/1.0.0/i18n/adm/vi/' . $shard . '.json');
                $statuses[] = $response->getStatusCode();
                $etags[$shard] = $response->headers->get('etag');
            }
        }
        $afterCold = (int) $this->get('/api/1.0.0/i18n/adm/vi/shard1.json')->headers->get('x-ratelimit-remaining');
        $this->assertNotContains(429, $statuses);
        $this->assertSame(600 - 151, $afterCold, 'the 150 cold shard requests and this one are counted, nothing else is');

        $revalidated = [];
        for ($load = 0; $load < 10; $load++) {
            foreach ($shards as $shard) {
                if ($etags[$shard] === null) {
                    continue;
                }
                $revalidated[] = $this->get('/api/1.0.0/i18n/adm/vi/' . $shard . '.json', ['If-None-Match' => $etags[$shard]])->getStatusCode();
            }
        }
        $this->assertNotContains(429, $revalidated);
        $this->assertContains(304, $revalidated, 'the shards answer a matching ETag with 304 (the ETag behaviour is kept)');
        // A 304 is a counted request too: the budget went down by the revalidated requests as well.
        $remaining = (int) $this->get('/api/1.0.0/i18n/adm/vi/shard2.json')->headers->get('x-ratelimit-remaining');
        $this->assertSame(600 - 151 - count($revalidated) - 1, $remaining);
    }
}
