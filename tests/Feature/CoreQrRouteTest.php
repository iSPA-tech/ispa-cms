<?php

namespace Tests\Feature;

use App\Constants\UserType;
use App\Http\Middleware\ApiLocalization;
use App\Models\User;
use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoreQrRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class]);
    }

    public function test_public_qr_web_url_renders_image(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $response = $this->get('/qr?string=' . rawurlencode('http://cms-dev.test/l/APPA000001'));

        $response->assertOk();
        $response->assertHeader('Content-Type', extension_loaded('imagick') ? 'image/png' : 'image/svg+xml');

        if (extension_loaded('imagick')) {
            $this->assertStringStartsWith(chr(0x89) . 'PNG', $response->getContent());
        } else {
            $this->assertStringContainsString('<svg', $response->getContent());
        }
    }

    public function test_qr_route_validates_expensive_or_malformed_inputs(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $this->get('/qr?string=' . rawurlencode('http://cms-dev.test/l/APPA000001') . '&size=5000')
            ->assertStatus(422);

        $this->get('/qr?string=' . rawurlencode('http://cms-dev.test/l/APPA000001') . '&color=nothex')
            ->assertStatus(422);
    }

    public function test_qr_cache_key_includes_render_options_and_no_cache_skips_storage(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $payload = rawurlencode('http://cms-dev.test/l/APPA000001');

        $small = $this->get("/qr?string=$payload&size=240&color=000000");
        $small->assertOk();

        $large = $this->get("/qr?string=$payload&size=260&color=000000");
        $large->assertOk();
        $this->assertNotSame($small->getContent(), $large->getContent());

        $this->get("/qr?string=$payload&size=260&color=000000&noCache=true")->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles('qrcode/url'));
    }

    public function test_qr_routes_are_throttled(): void
    {
        $this->assertContains('throttle:qr-public', $this->findGetRoute('qr')?->gatherMiddleware() ?? []);
        $this->assertContains('throttle:qr-public', $this->findGetRoute('api/qr')?->gatherMiddleware() ?? []);

        $adminRouteMiddleware = $this->findGetRoute('api/v2/admin/qr')?->gatherMiddleware() ?? [];

        $this->assertContains('auth:sanctum', $adminRouteMiddleware);
        $this->assertContains('api.admin', $adminRouteMiddleware);
        $this->assertContains('throttle:qr-admin', $adminRouteMiddleware);

        $adminPostMiddleware = $this->findPostRoute('api/v2/admin/qr')?->gatherMiddleware() ?? [];

        $this->assertContains('auth:sanctum', $adminPostMiddleware);
        $this->assertContains('api.admin', $adminPostMiddleware);
        $this->assertContains('throttle:qr-admin', $adminPostMiddleware);
    }

    public function test_public_qr_route_allows_only_two_requests_per_minute(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $payload = rawurlencode('http://cms-dev.test/l/APPA000001');

        $this->getJson("/qr?string=$payload")->assertOk();
        $this->getJson("/qr?string=$payload")->assertOk();
        $this->getJson("/qr?string=$payload")->assertStatus(429);
    }

    public function test_admin_qr_route_requires_admin_authentication(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->getJson('/api/v2/admin/qr?string=' . rawurlencode('https://example.test/private-qr'))
            ->assertUnauthorized();

        $this->postJson('/api/v2/admin/qr', [
            'string' => 'https://example.test/private-qr',
        ])->assertUnauthorized();

        $this->actingAs(User::factory()->make(['type' => UserType::USER]), 'sanctum')
            ->postJson('/api/v2/admin/qr', [
                'string' => 'https://example.test/private-qr',
            ])->assertUnauthorized();
    }

    public function test_authenticated_admin_qr_post_route_renders_uncached_image(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $response = $this->actingAs(User::factory()->make(['type' => UserType::WEBMASTER]), 'sanctum')
            ->post('/api/v2/admin/qr', [
                'string' => 'https://example.test/private-qr',
                'size' => 280,
            ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', extension_loaded('imagick') ? 'image/png' : 'image/svg+xml');
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    private function findGetRoute(string $uri): mixed
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && $route->uri() === $uri) {
                return $route;
            }
        }

        return null;
    }

    private function findPostRoute(string $uri): mixed
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array('POST', $route->methods(), true) && $route->uri() === $uri) {
                return $route;
            }
        }

        return null;
    }
}
