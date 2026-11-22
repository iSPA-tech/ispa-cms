<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HttpsProtocol;
use App\Http\Middleware\Localization;
use App\Http\Middleware\ApiLocalization;
use App\Constants\UserType;
use App\Models\User;
use App\Services\RuntimeCacheMaintenanceService;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Tests\TestCase;

final class AdminRuntimeCacheMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([HttpsProtocol::class, Localization::class, ApiLocalization::class, ThrottleRequests::class]);
    }

    public function test_fixed_cache_maintenance_routes_are_post_only_and_admin_guarded(): void
    {
        foreach ([
            '/api/v2/admin/runtime-cache/clear' => 'admin.runtime-cache.clear',
            '/api/v2/admin/runtime-cache/optimize' => 'admin.runtime-cache.optimize',
        ] as $path => $name) {
            $route = app('router')->getRoutes()->match(Request::create($path, 'POST'));

            self::assertSame($name, $route->getName());
            self::assertContains('POST', $route->methods());
            self::assertContains('auth:sanctum', $route->middleware());
            self::assertContains('api.admin', $route->middleware());
            self::assertContains('throttle:3,1,v2-runtime-cache:', $route->middleware());
        }
    }

    public function test_unauthenticated_cache_maintenance_is_rejected(): void
    {
        config()->set('ispa-cms.admin_auth_key', 'admin-test-key');

        $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
            ->postJson('/api/v2/admin/runtime-cache/clear')
            ->assertUnauthorized();
    }

    public function test_authorized_admin_can_only_run_fixed_maintenance_actions(): void
    {
        config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
        $user = new CacheMaintenanceUser(['id' => 77, 'type' => UserType::WEBMASTER]);
        $user->allowed = true;
        Sanctum::actingAs($user);
        $maintenance = Mockery::mock(RuntimeCacheMaintenanceService::class);
        $maintenance->shouldReceive('clearApplicationCache')->once()->andReturn([
            'cleared' => 12,
            'preserved' => ['acme:vehicle-sitemap:v2'],
        ]);
        $maintenance->shouldReceive('optimizeApplication')->once();
        app()->instance(RuntimeCacheMaintenanceService::class, $maintenance);

        $headers = ['ISPA-ADMIN-AUTH-KEY' => 'admin-test-key'];
        $this->withHeaders($headers)->postJson('/api/v2/admin/runtime-cache/clear')
            ->assertOk()
            ->assertJsonPath('data.cleared_keys', 12)
            ->assertJsonPath('data.preserved_keys', 1);
        $this->withHeaders($headers)->postJson('/api/v2/admin/runtime-cache/optimize')
            ->assertOk();
    }

    public function test_admin_without_setting_permission_is_forbidden(): void
    {
        config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
        $user = new CacheMaintenanceUser(['id' => 78, 'type' => UserType::ADMIN]);
        $user->allowed = false;
        Sanctum::actingAs($user);
        app()->instance(RuntimeCacheMaintenanceService::class, Mockery::mock(RuntimeCacheMaintenanceService::class));

        $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
            ->postJson('/api/v2/admin/runtime-cache/clear')
            ->assertForbidden();
    }

    public function test_concurrent_cache_maintenance_is_rejected(): void
    {
        config()->set('ispa-cms.admin_auth_key', 'admin-test-key');
        $user = new CacheMaintenanceUser(['id' => 79, 'type' => UserType::WEBMASTER]);
        $user->allowed = true;
        Sanctum::actingAs($user);
        app()->instance(RuntimeCacheMaintenanceService::class, Mockery::mock(RuntimeCacheMaintenanceService::class));
        $lock = Cache::store('file')->lock('admin:runtime-cache-maintenance', 30);
        self::assertTrue($lock->get());

        try {
            $this->withHeader('ISPA-ADMIN-AUTH-KEY', 'admin-test-key')
                ->postJson('/api/v2/admin/runtime-cache/clear')
                ->assertStatus(409);
        } finally {
            $lock->release();
        }
    }
}

final class CacheMaintenanceUser extends User
{
    public bool $allowed = false;

    public function can($abilities, $arguments = []): bool
    {
        return $this->allowed;
    }
}
