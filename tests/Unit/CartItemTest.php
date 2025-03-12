<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CartItemTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_belongs_to_a_cart_and_a_product()
    {
        $cart = Cart::factory()->create();
        $product = Product::factory()->create();
        $cartItem = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2
        ]);

        $this->assertEquals($cart->id, $cartItem->cart->id);
        $this->assertEquals($product->id, $cartItem->product->id);
    }
}
