<?php

namespace Tests\Unit;

use App\Http\Controllers\V3\Public\StaticController;
use Cms\Core\Interfaces\MenuInterface;
use Cms\Core\Interfaces\MenuItemInterface;
use Cms\Core\Services\Captcha\CaptchaServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

class V3PublicStaticControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_footer_menu_ignores_redis_cache_write_failure(): void
    {
        $previousRedisEnabled = config('database.redis.enable');
        config(['database.redis.enable' => true]);
        Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis down'));

        try {
            $items = Mockery::mock(MenuItemInterface::class);
            $items->shouldReceive('getMenuItemListByMenuGroup')
                ->once()
                ->with('footer_menu', Mockery::any())
                ->andReturn(collect([
                    (object) ['name' => 'Footer item'],
                ]));

            $controller = new StaticController(
                Mockery::mock(MenuInterface::class),
                $items,
                Mockery::mock(CaptchaServiceInterface::class),
            );

            $request = Request::create('/api/v3/static/footer-menu', 'GET');
            $response = $controller->footerMenu();
            $payload = $response->toResponse($request)->getData(true);

            $this->assertSame('success', $payload['status']);
            $this->assertSame('Footer item', $payload['data'][0]['name']);
        } finally {
            config(['database.redis.enable' => $previousRedisEnabled]);
        }
    }
}
