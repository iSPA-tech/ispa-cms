<?php

namespace Tests\Unit\Helpers;

use Tests\TestCase;
use Illuminate\Support\Facades\Session;
use App\Helpers\CartHelper;

class CartHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Session::start();
        Session::flush();
    }

    public function test_add_and_total(): void
    {
        $cart = new CartHelper();
        $cart->add(['id' => 'a', 'sku' => '1', 'name' => 'x', 'quantity' => 2, 'price' => 5]);
        $this->assertFalse($cart->isEmpty());
        $this->assertEquals(10, $cart->total());
    }

    public function test_get_variant_price(): void
    {
        $cart = new CartHelper();
        $variant = [
            'sale_price' => 50,
            'sell_price' => 100,
            'sale_from_date' => now()->subDay()->toDateTimeString(),
            'sale_to_date' => now()->addDay()->toDateTimeString(),
        ];
        $this->assertEquals(50, $cart->getVariantPrice($variant));
    }
}
