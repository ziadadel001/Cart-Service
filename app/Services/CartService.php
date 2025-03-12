<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\QueryException;

final class CartService
{
    protected const SESSION_KEY = 'guest_cart';
    protected const CACHE_KEY = 'user_cart:';
    protected const CACHE_TTL = 60; // 60 minutes
    public const MAX_QUANTITY = 100;

    public function addItem(Product $product, int $quantity = 1): void
    {
        if (!$product->exists) {
            throw new \Exception("Product not found");
        }

        $this->validateItem($product, $quantity);

        if (Auth::check()) {
            $this->addToDatabaseCart($product, $quantity);
        } else {
            $this->addToSessionCart($product, $quantity);
        }
    }

    public function getItems(): array
    {
        return Auth::check()
            ? $this->getDatabaseCart()
            : $this->getSessionCart();
    }

    private function addToSessionCart(Product $product, int $quantity): void
    {
        try {
            $cart = Session::get(self::SESSION_KEY, []);

            if (isset($cart[$product->id])) {
                $newQuantity = $cart[$product->id]['quantity'] + $quantity;
                $this->validateItem($product, $newQuantity);
                $cart[$product->id]['quantity'] = $newQuantity;
            } else {
                $cart[$product->id] = $this->formatSessionItem($product, $quantity);
            }

            Session::put(self::SESSION_KEY, $cart);
        } catch (\Exception $e) {
            Log::error('Session Cart Error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function getSessionCart(): array
    {
        return array_values(Session::get(self::SESSION_KEY, []));
    }


    private function addToDatabaseCart(Product $product, int $quantity): void
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        DB::transaction(function () use ($product, $quantity, $cacheKey) {
            try {
                Cache::forget($cacheKey);

                $cart = Auth::user()->cart()->firstOrCreate();
                $cartItem = $this->resolveCartItem($cart, $product, $quantity);

                $cartItem->save();
                // Instead of caching the entire Cart model, cache the formatted items array.
                $formattedCartItems = $this->formatDatabaseItems(
                    $cart->fresh()->load('items.product')->items
                );
                Cache::put($cacheKey, $formattedCartItems, self::CACHE_TTL);
            } catch (QueryException $e) {
                Log::error('Database Cart Error: ' . $e->getMessage());
                throw new \Exception("Failed to update cart");
            }
        });
    }

    private function getDatabaseCart(): array
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $cart = Auth::user()->cart()->with('items.product')->first();
            return $cart ? $this->formatDatabaseItems($cart->items) : [];
        });
    }

    private function validateItem(Product $product, int $quantity): void
    {
        if ($quantity < 1) {
            throw new \Exception("Quantity must be a positive number");
        }

        if ($product->stock < $quantity) {
            throw new \Exception("Requested quantity is not available for product: {$product->name}");
        }

        if ($quantity > self::MAX_QUANTITY) {
            throw new \Exception("Maximum quantity allowed: " . self::MAX_QUANTITY);
        }
    }

    private function resolveCartItem(Cart $cart, Product $product, int $quantity): CartItem
    {
        return tap(
            $cart->items()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrNew(
                    ['product_id' => $product->id],
                    [
                        'quantity' => 0,
                        'price' => $product->price
                    ]
                ),
            function (CartItem $item) use ($quantity, $product) {
                $newQuantity = $item->quantity + $quantity;
                $this->validateItem($product, $newQuantity);
                $item->quantity = $newQuantity;
                $item->price = $product->price;
            }
        );
    }

    private function formatSessionItem(Product $product, int $quantity): array
    {
        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'quantity' => $quantity,
        ];
    }

    private function formatDatabaseItems($items): array
    {
        return $items->map(function ($item) {
            return [
                'product_id' => $item->product_id,
                'name' => $item->product->name,
                'price' => $item->price,
                'quantity' => $item->quantity,
            ];
        })->toArray();
    }

    public function mergeCartsOnLogin(): void
    {
        DB::transaction(function () {
            try {
                $sessionCart = Session::get(self::SESSION_KEY, []);
                $cart = Auth::user()->cart()->firstOrCreate();

                collect($sessionCart)->each(function ($item) use ($cart) {
                    $this->mergeCartItem($cart, $item);
                });

                Session::forget(self::SESSION_KEY);
                Cache::forget(self::CACHE_KEY . Auth::id());
            } catch (QueryException $e) {
                Log::error('Cart Merge Error: ' . $e->getMessage());
                throw new \Exception("Failed to merge cart");
            }
        });
    }

    private function mergeCartItem(Cart $cart, array $item): void
    {
        $productId = $item['product_id'] ?? null;

        if (!is_numeric($productId)) {
            Log::warning('Invalid product_id during cart merge: ' . print_r($productId, true));
            return;
        }

        $product = Product::find((int)$productId);

        if (!$product instanceof Product) {
            Log::warning('Product not found during merge: ' . $productId);
            return;
        }

        $existingItem = $cart->items()->firstOrNew(
            ['product_id' => $product->id],
            ['quantity' => 0]
        );

        $newQuantity = $existingItem->quantity + $item['quantity'];

        try {
            $this->validateItem($product, $newQuantity);
        } catch (\Exception $e) {
            Log::error('Validation failed during merge: ' . $e->getMessage());
            return;
        }

        $existingItem->quantity = $newQuantity;
        $existingItem->price = $product->price;
        $existingItem->save();
    }

    public function removeItem(int $productId): void
    {
        if (Auth::check()) {
            $this->removeFromDatabaseCart($productId);
        } else {
            $this->removeFromSessionCart($productId);
        }
    }

    public function clearCart(): void
    {
        if (Auth::check()) {
            $this->clearDatabaseCart();
        } else {
            $this->clearSessionCart();
        }
    }

    private function removeFromSessionCart(int $productId): void
    {
        try {
            $cart = Session::get(self::SESSION_KEY, []);

            if (isset($cart[$productId])) {
                unset($cart[$productId]);
                Session::put(self::SESSION_KEY, $cart);
            }
        } catch (\Exception $e) {
            Log::error('Session Cart Removal Error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function clearSessionCart(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    private function removeFromDatabaseCart(int $productId): void
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        DB::transaction(function () use ($productId, $cacheKey) {
            try {
                Cache::forget($cacheKey);

                $cart = Auth::user()->cart()->first();
                if ($cart) {
                    $cart->items()->where('product_id', $productId)->delete();
                    Cache::put($cacheKey, $cart->fresh()->load('items.product'), self::CACHE_TTL);
                }
            } catch (QueryException $e) {
                Log::error('Database Cart Removal Error: ' . $e->getMessage());
                throw new \Exception("Failed to remove item");
            }
        });
    }

    private function clearDatabaseCart(): void
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        DB::transaction(function () use ($cacheKey) {
            try {
                $cart = Auth::user()->cart()->first();
                if ($cart) {
                    $cart->items()->delete();
                    Cache::forget($cacheKey);
                }
            } catch (QueryException $e) {
                Log::error('Database Cart Clear Error: ' . $e->getMessage());
                throw new \Exception("Failed to clear cart");
            }
        });
    }
}