<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CartService $cartService;
    protected User $user;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cartService = new CartService();
        $this->user = User::factory()->create();
        $this->product = Product::factory()->create([
            'stock' => 50,
            'price' => 100,
        ]);
    }

    public function test_guest_can_add_product_to_cart()
    {
        Session::forget('guest_cart');

        $this->cartService->addItem($this->product, 2);

        $cart = Session::get('guest_cart');
        $this->assertNotEmpty($cart);
        $this->assertArrayHasKey($this->product->id, $cart);
        $this->assertEquals(2, $cart[$this->product->id]['quantity']);
    }

    public function test_authenticated_user_can_add_product_to_cart()
    {
        Auth::login($this->user);

        $this->cartService->addItem($this->product, 2);

        $cart = $this->user->cart()->with('items')->first();
        $this->assertNotNull($cart);
        $this->assertEquals(2, $cart->items->first()->quantity);
    }

    public function test_guest_can_retrieve_cart_items()
    {
        Session::put('guest_cart', [
            $this->product->id => [
                'product_id' => $this->product->id,
                'name' => $this->product->name,
                'price' => $this->product->price,
                'quantity' => 3
            ]
        ]);

        $items = $this->cartService->getItems();
        $this->assertNotEmpty($items);
        $this->assertEquals(3, $items[0]['quantity']);
    }

    public function test_authenticated_user_can_retrieve_cart_items()
    {
        Auth::login($this->user);
        $cart = $this->user->cart()->create();
        $cart->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 3,
            'price' => $this->product->price
        ]);

        $items = $this->cartService->getItems();
        $this->assertNotEmpty($items);
        $this->assertEquals(3, $items[0]['quantity']);
    }

    public function test_guest_can_remove_product_from_cart()
    {
        Session::put('guest_cart', [
            $this->product->id => [
                'product_id' => $this->product->id,
                'quantity' => 2
            ]
        ]);

        $this->cartService->removeItem($this->product->id);
        $cart = Session::get('guest_cart');

        $this->assertArrayNotHasKey($this->product->id, $cart);
    }

    public function test_authenticated_user_can_remove_product_from_cart()
    {
        Auth::login($this->user);
        $cart = $this->user->cart()->create();
        $cart->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 3
        ]);

        $this->cartService->removeItem($this->product->id);

        $this->assertEmpty($this->user->cart->items);
    }

    public function test_guest_can_clear_cart()
    {
        Session::put('guest_cart', [
            $this->product->id => [
                'product_id' => $this->product->id,
                'quantity' => 2
            ]
        ]);

        $this->cartService->clearCart();
        $this->assertEmpty(Session::get('guest_cart'));
    }

    public function test_authenticated_user_can_clear_cart()
    {
        Auth::login($this->user);
        $cart = $this->user->cart()->create();
        $cart->items()->create([
            'product_id' => $this->product->id,
            'quantity' => 3
        ]);

        $this->cartService->clearCart();
        $this->assertEmpty($this->user->cart->items);
    }

    public function test_cart_merges_on_login()
    {
        Session::put('guest_cart', [
            $this->product->id => [
                'product_id' => $this->product->id,
                'quantity' => 2,
                'price' => $this->product->price
            ]
        ]);

        Auth::login($this->user);
        $this->cartService->mergeCartsOnLogin();

        $cart = $this->user->cart()->with('items')->first();
        $this->assertNotNull($cart);
        $this->assertEquals(2, $cart->items->first()->quantity);
    }

    public function test_cannot_add_more_than_max_quantity()
    {
        $this->expectException(\Exception::class);
        $this->cartService->addItem($this->product, CartService::MAX_QUANTITY + 1);
    }

    public function test_cannot_add_more_than_available_stock()
    {
        $this->expectException(\Exception::class);
        $this->cartService->addItem($this->product, $this->product->stock + 1);
    }

    public function test_cannot_add_negative_quantity()
    {
        $this->expectException(\Exception::class);
        $this->cartService->addItem($this->product, -5);
    }

    public function test_adding_same_product_twice_increases_quantity(): void
    {
        Auth::login($this->user);

        $this->cartService->addItem($this->product, 2);

        $this->cartService->addItem($this->product, 3);

        $items = $this->cartService->getItems();
        $this->assertCount(1, $items);
        $this->assertEquals(5, $items[0]['quantity']);
    }

    public function test_cannot_add_non_existent_product(): void
    {
        $nonExistentProductId = 9999;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Product not found");

        $nonExistentProduct = new Product(['id' => $nonExistentProductId]);
        $this->cartService->addItem($nonExistentProduct, 1);
    }

    public function test_cannot_add_product_with_zero_quantity(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Quantity must be a positive number");

        $this->cartService->addItem($this->product, 0);
    }

    public function test_performance_with_thousands_of_items(): void
    {
        Auth::login($this->user);
        $cart = $this->user->cart()->create();

        Cache::shouldReceive('remember')->andReturn([]);
        Cache::shouldReceive('put')->andReturn(true);

        $products = Product::factory()->count(1000)->create();

        $startTime = microtime(true);

        $cart->items()->createMany(
            $products->map(fn($p) => [
                'product_id' => $p->id,
                'quantity' => 1,
                'price' => $p->price
            ])->toArray()
        );

        $executionTime = microtime(true) - $startTime;
        $this->assertLessThan(1, $executionTime, "الوقت الفعلي: $executionTime ثانية");

        $startTime = microtime(true);
        $this->cartService->getItems();
        $executionTime = microtime(true) - $startTime;
        $this->assertLessThan(1, $executionTime, "الوقت الفعلي: $executionTime ثانية");
    }
}
