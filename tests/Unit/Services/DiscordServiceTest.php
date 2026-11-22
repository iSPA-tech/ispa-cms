<?php

namespace Tests\Unit\Services;

use ReflectionMethod;
use PHPUnit\Framework\TestCase;
use App\Services\Logger\DiscordService;

class DiscordServiceTest extends TestCase
{
    public function test_set_title_returns_string(): void
    {
        $title = DiscordService::setTitle('foo');
        $this->assertIsString($title);
        $this->assertStringContainsString('foo', $title);
    }

    public function test_sub_string(): void
    {
        $method = new ReflectionMethod(DiscordService::class, 'subString');
        $method->setAccessible(true);
        $this->assertEquals('abc', $method->invoke(null, 'abc', 5));
        $this->assertEquals('ab...', $method->invoke(null, 'abcdef', 5));
    }
}
