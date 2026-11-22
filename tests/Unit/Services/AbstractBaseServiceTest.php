<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\AbstractBaseService;

class DummyService extends AbstractBaseService {}

class AbstractBaseServiceTest extends TestCase
{
    public function test_fill_and_property_access(): void
    {
        $service = new DummyService(['foo' => 'bar']);
        $this->assertSame('bar', $service->foo);
        $this->assertTrue(isset($service->foo));

        $service->foo = 'baz';
        $this->assertSame('baz', $service->foo);

        unset($service->foo);
        $this->assertFalse(isset($service->foo));
    }

    public function test_to_array_and_json_and_get(): void
    {
        $service = new DummyService(['a' => 1]);
        $this->assertEquals(['a' => 1], $service->toArray());
        $this->assertEquals('{"a":1}', $service->toJson());
        $this->assertSame(1, $service->get('a'));
        $this->assertSame(2, $service->get('b', 2));
    }
}
