<?php

namespace Tests\Unit\Helpers;

use Tests\TestCase;

class CommonFunctionsTest extends TestCase
{
    public function test_get_currency_name(): void
    {
        $this->assertEquals('USD', get_currency_name('$'));
        $this->assertEquals('VND', get_currency_name('đ'));
    }

    public function test_get_language_code(): void
    {
        $this->assertEquals('vi', get_language_code());
    }
}
