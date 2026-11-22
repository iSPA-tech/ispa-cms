<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\Logger\TelegramService;

class TelegramServiceTest extends TestCase
{
    public function test_set_title_returns_string(): void
    {
        $ref = new \ReflectionClass(TelegramService::class);
        $method = $ref->getMethod('setTitle');
        $method->setAccessible(true);

        $title = $method->invoke(null, 'Hello');

        $this->assertIsString($title);
        $this->assertStringContainsString('Hello', $title);
    }
}
