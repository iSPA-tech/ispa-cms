<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use App\Helpers\AppHelpers;

class AppHelpersTest extends TestCase
{
    public function test_make_key(): void
    {
        $data = ['a' => 1, 'b' => 2];
        $this->assertEquals('a:1|b:2', AppHelpers::makeKey($data));
    }

    public function test_rank_id(): void
    {
        $this->assertEquals(3, AppHelpers::rankId('Class'));
        $this->assertEquals(0, AppHelpers::rankId('Unknown'));
    }
}
