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
    protected const MAX_QUANTITY = 100;

    /**
     * Add a product to the cart (either session-based for guests or database for authenticated users).
     *
     * @param Product $product
     * @param int $quantity
     * @return void
     */
    public function addItem(Product $product, int $quantity = 1): void
    {
        $this->validateItem($product, $quantity);

        if (Auth::check()) {
            $this->addToDatabaseCart($product, $quantity);
        } else {
            $this->addToSessionCart($product, $quantity);
        }
    }

    /**
     * Retrieve cart items for the current user.
     *
     * @return array
     */
    public function getItems(): array
    {
        return Auth::check()
            ? $this->getDatabaseCart()
            : $this->getSessionCart();
    }

    // ------ Guest Methods ------ //

    /**
     * Add a product to the session-based cart (for guests).
     *
     * @param Product $product
     * @param int $quantity
     * @return void
     */
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

    /**
     * Retrieve the session-based cart (for guests).
     *
     * @return array
     */
    private function getSessionCart(): array
    {
        return array_values(Session::get(self::SESSION_KEY, []));
    }

    // ------ User Methods ------ //

    /**
     * Add a product to the database cart (for authenticated users).
     *
     * @param Product $product
     * @param int $quantity
     * @return void
     */
    private function addToDatabaseCart(Product $product, int $quantity): void
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        DB::transaction(function () use ($product, $quantity, $cacheKey) {
            try {
                Cache::forget($cacheKey);

                $cart = Auth::user()->cart()->firstOrCreate();
                $cartItem = $this->resolveCartItem($cart, $product, $quantity);

                $cartItem->save();
                Cache::put($cacheKey, $cart->fresh()->load('items.product'), self::CACHE_TTL);
            } catch (QueryException $e) {
                Log::error('Database Cart Error: ' . $e->getMessage());
                throw new \Exception("Failed to update cart");
            }
        });
    }

    /**
     * Retrieve the database cart (for authenticated users).
     *
     * @return array
     */
    private function getDatabaseCart(): array
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $cart = Auth::user()->cart()->with('items.product')->first();
            return $cart ? $this->formatDatabaseItems($cart->items) : [];
        });
    }

    // ------ Shared Helpers ------ //

    /**
     * Validate the product and quantity before adding to the cart.
     *
     * @param Product $product
     * @param int $quantity
     * @return void
     * @throws \Exception
     */
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

    /**
     * Resolve the cart item by finding or creating it and updating the quantity.
     *
     * @param Cart $cart
     * @param Product $product
     * @param int $quantity
     * @return CartItem
     */
    private function resolveCartItem(Cart $cart, Product $product, int $quantity): CartItem
    {
        return tap(
            $cart->items()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrNew(
                    ['product_id' => $product->id],
                    ['quantity' => 0]
                ),
            function (CartItem $item) use ($quantity, $product) {
                $newQuantity = $item->quantity + $quantity;
                $this->validateItem($product, $newQuantity);
                $item->quantity = $newQuantity;
                $item->price = $product->price;
            }
        );
    }

    // ------ Formatting Methods ------ //

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

    // ------ Cart Merging ------ //

    /**
     * Merge session cart items with the database cart when a guest logs in.
     *
     * @return void
     */
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

    /**
     * Merge an individual cart item from session to the database cart.
     *
     * @param Cart $cart
     * @param array $item
     * @return void
     */
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

    /**
     * Remove an item from the cart.
     * This method checks if the user is authenticated. 
     * - If authenticated, it removes the item from the database cart.
     * - Otherwise, it removes the item from the session cart.
     *
     * @param int $productId The ID of the product to be removed.
     * @return void
     */
    public function removeItem(int $productId): void
    {
        if (Auth::check()) {
            $this->removeFromDatabaseCart($productId);
        } else {
            $this->removeFromSessionCart($productId);
        }
    }

    /**
     * Clear all items from the cart.
     * This method checks if the user is authenticated. 
     * - If authenticated, it clears the database cart.
     * - Otherwise, it clears the session cart.
     *
     * @return void
     */
    public function clearCart(): void
    {
        if (Auth::check()) {
            $this->clearDatabaseCart();
        } else {
            $this->clearSessionCart();
        }
    }

    // ------ Guest Removal Methods ------ //

    /**
     * Remove a specific item from the session-based cart (for guests).
     *
     * @param int $productId The ID of the product to be removed.
     * @return void
     */
    private function removeFromSessionCart(int $productId): void
    {
        try {
            // Retrieve the current session cart
            $cart = Session::get(self::SESSION_KEY, []);

            // If the product exists in the cart, remove it
            if (isset($cart[$productId])) {
                unset($cart[$productId]);
                Session::put(self::SESSION_KEY, $cart);
            }
        } catch (\Exception $e) {
            Log::error('Session Cart Removal Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Clear the entire session-based cart (for guests).
     *
     * @return void
     */
    private function clearSessionCart(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    // ------ User Removal Methods ------ //

    /**
     * Remove a specific item from the database cart (for authenticated users).
     * This method uses a database transaction to ensure data integrity.
     *
     * @param int $productId The ID of the product to be removed.
     * @return void
     * @throws \Exception If there is a database error.
     */
    private function removeFromDatabaseCart(int $productId): void
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        DB::transaction(function () use ($productId, $cacheKey) {
            try {
                // Invalidate the cache before modifying the cart
                Cache::forget($cacheKey);

                // Retrieve the authenticated user's cart
                $cart = Auth::user()->cart()->first();
                if ($cart) {
                    // Remove the item from the cart
                    $cart->items()->where('product_id', $productId)->delete();

                    // Refresh the cache with the updated cart data
                    Cache::put($cacheKey, $cart->fresh()->load('items.product'), self::CACHE_TTL);
                }
            } catch (QueryException $e) {
                Log::error('Database Cart Removal Error: ' . $e->getMessage());
                throw new \Exception("Failed to remove item");
            }
        });
    }

    /**
     * Clear all items from the database cart (for authenticated users).
     * This method uses a database transaction to ensure atomicity.
     *
     * @return void
     * @throws \Exception If there is a database error.
     */
    private function clearDatabaseCart(): void
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        DB::transaction(function () use ($cacheKey) {
            try {
                // Retrieve the authenticated user's cart
                $cart = Auth::user()->cart()->first();
                if ($cart) {
                    // Remove all cart items
                    $cart->items()->delete();

                    // Invalidate the cache
                    Cache::forget($cacheKey);
                }
            } catch (QueryException $e) {
                Log::error('Database Cart Clear Error: ' . $e->getMessage());
                throw new \Exception("Failed to clear cart");
            }
        });
    }
}
