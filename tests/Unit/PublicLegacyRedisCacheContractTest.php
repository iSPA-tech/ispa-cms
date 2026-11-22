<?php

namespace Tests\Unit;

use Tests\TestCase;

class PublicLegacyRedisCacheContractTest extends TestCase
{
    public function test_public_post_category_and_tag_controllers_do_not_read_legacy_redis_cache(): void
    {
        $controllers = [
            base_path('cms/Content/app/Http/Controllers/V3/Public/PostController.php'),
            base_path('cms/Content/app/Http/Controllers/V3/Public/CategoryController.php'),
            base_path('cms/Content/app/Http/Controllers/V3/Public/TagController.php'),
        ];

        foreach ($controllers as $controller) {
            $source = file_get_contents($controller);

            $this->assertIsString($source);
            $this->assertStringNotContainsString('RedisHelper::get(', $source, $controller);
            $this->assertStringNotContainsString('RedisHelper::getInstance()->get(', $source, $controller);
            $this->assertStringNotContainsString('Redis::get(', $source, $controller);
        }
    }
}
