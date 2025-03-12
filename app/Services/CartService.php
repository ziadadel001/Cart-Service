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
    // Keys for session and cache storage
    protected const SESSION_KEY = 'guest_cart'; // Session key for guest cart
    protected const CACHE_KEY = 'user_cart:'; // Cache key prefix for user cart
    protected const CACHE_TTL = 60; // Cache time-to-live in minutes
    public const MAX_QUANTITY = 100; // Maximum allowed quantity per product

    /**
     * Add a product to the cart (for guests or authenticated users).
     *
     * @param Product $product The product to add
     * @param int $quantity The quantity to add
     * @return void
     */
    public function addItem(Product $product, int $quantity = 1): void
    {
        // Check if the product exists
        if (!$product->exists) {
            throw new \Exception("Product not found");
        }

        // Validate the item (quantity, stock, etc.)
        $this->validateItem($product, $quantity);

        // Add to database cart for authenticated users, or session cart for guests
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
            ? $this->getDatabaseCart() // Database cart for authenticated users
            : $this->getSessionCart(); // Session cart for guests
    }

    // ------ Guest-specific methods (Session-based) ------ //

    /**
     * Add a product to the session-based cart (for guests).
     *
     * @param Product $product The product to add
     * @param int $quantity The quantity to add
     * @return void
     */
    private function addToSessionCart(Product $product, int $quantity): void
    {
        try {
            // Retrieve the cart from the session
            $cart = Session::get(self::SESSION_KEY, []);

            // If the product already exists in the cart, update the quantity
            if (isset($cart[$product->id])) {
                $newQuantity = $cart[$product->id]['quantity'] + $quantity;
                $this->validateItem($product, $newQuantity);
                $cart[$product->id]['quantity'] = $newQuantity;
            } else {
                // If the product is new, add it to the cart
                $cart[$product->id] = $this->formatSessionItem($product, $quantity);
            }

            // Save the updated cart back to the session
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

    // ------ User-specific methods (Database + Cache) ------ //

    /**
     * Add a product to the database-based cart (for authenticated users).
     *
     * @param Product $product The product to add
     * @param int $quantity The quantity to add
     * @return void
     */
    private function addToDatabaseCart(Product $product, int $quantity): void
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        // Use a database transaction to ensure data consistency
        DB::transaction(function () use ($product, $quantity, $cacheKey) {
            try {
                // Invalidate the cache before making changes
                Cache::forget($cacheKey);

                // Retrieve or create the user's cart
                $cart = Auth::user()->cart()->firstOrCreate();
                $cartItem = $this->resolveCartItem($cart, $product, $quantity);

                // Save the cart item
                $cartItem->save();

                // Format the cart items before caching
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

    /**
     * Retrieve the database-based cart (for authenticated users).
     *
     * @return array
     */
    private function getDatabaseCart(): array
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        // Retrieve the cart from the cache or database
        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            $cart = Auth::user()->cart()->with('items.product')->first();
            return $cart ? $this->formatDatabaseItems($cart->items) : [];
        });
    }

    /**
     * Validate the product and quantity before adding to the cart.
     *
     * @param Product $product The product to validate
     * @param int $quantity The quantity to validate
     * @return void
     * @throws \Exception If validation fails
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
     * @param Cart $cart The user's cart
     * @param Product $product The product to resolve
     * @param int $quantity The quantity to add
     * @return CartItem
     */
    private function resolveCartItem(Cart $cart, Product $product, int $quantity): CartItem
    {
        return tap(
            $cart->items()
                ->where('product_id', $product->id)
                ->lockForUpdate() // Lock the row to prevent race conditions
                ->firstOrNew(
                    ['product_id' => $product->id],
                    [
                        'quantity' => 0, // Default quantity
                        'price' => $product->price // Default price
                    ]
                ),
            function (CartItem $item) use ($quantity, $product) {
                $newQuantity = $item->quantity + $quantity;
                $this->validateItem($product, $newQuantity);
                $item->quantity = $newQuantity; // Update the quantity
                $item->price = $product->price; // Update the price
            }
        );
    }

    /**
     * Format a session cart item.
     *
     * @param Product $product The product to format
     * @param int $quantity The quantity to format
     * @return array
     */
    private function formatSessionItem(Product $product, int $quantity): array
    {
        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'quantity' => $quantity,
        ];
    }

    /**
     * Format database cart items for caching.
     *
     * @param mixed $items The cart items to format
     * @return array
     */
    private function formatDatabaseItems($items): array
    {
        return $items->map(function ($item) {
            return [
                'product_id' => $item->product_id,
                'name' => $item->product->name,
                'price' => $item->price,
                'quantity' => $item->quantity,
            ];
        })->toArray(); // Convert to array
    }

    /**
     * Merge the guest cart with the user's cart upon login.
     *
     * @return void
     */
    public function mergeCartsOnLogin(): void
    {
        DB::transaction(function () {
            try {
                // Retrieve the guest cart from the session
                $sessionCart = Session::get(self::SESSION_KEY, []);
                $cart = Auth::user()->cart()->firstOrCreate();

                // Merge each item from the guest cart into the user's cart
                collect($sessionCart)->each(function ($item) use ($cart) {
                    $this->mergeCartItem($cart, $item);
                });

                // Clear the guest cart from the session
                Session::forget(self::SESSION_KEY);
                Cache::forget(self::CACHE_KEY . Auth::id());
            } catch (QueryException $e) {
                Log::error('Cart Merge Error: ' . $e->getMessage());
                throw new \Exception("Failed to merge cart");
            }
        });
    }

    /**
     * Merge an individual cart item from the guest cart into the user's cart.
     *
     * @param Cart $cart The user's cart
     * @param array $item The item to merge
     * @return void
     */
    private function mergeCartItem(Cart $cart, array $item): void
    {
        $productId = $item['product_id'] ?? null;

        // Validate the product ID
        if (!is_numeric($productId)) {
            Log::warning('Invalid product_id during cart merge: ' . print_r($productId, true));
            return;
        }

        // Find the product
        $product = Product::find((int)$productId);

        // If the product doesn't exist, skip it
        if (!$product instanceof Product) {
            Log::warning('Product not found during merge: ' . $productId);
            return;
        }

        // Find or create the cart item
        $existingItem = $cart->items()->firstOrNew(
            ['product_id' => $product->id],
            ['quantity' => 0]
        );

        // Calculate the new quantity
        $newQuantity = $existingItem->quantity + $item['quantity'];

        try {
            // Validate the new quantity
            $this->validateItem($product, $newQuantity);
        } catch (\Exception $e) {
            Log::error('Validation failed during merge: ' . $e->getMessage());
            return;
        }

        // Update the cart item
        $existingItem->quantity = $newQuantity;
        $existingItem->price = $product->price;
        $existingItem->save();
    }

    /**
     * Remove an item from the cart.
     *
     * @param int $productId The ID of the product to remove
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

    // ------ Guest removal methods ------ //

    /**
     * Remove a specific item from the session-based cart (for guests).
     *
     * @param int $productId The ID of the product to remove
     * @return void
     */
    private function removeFromSessionCart(int $productId): void
    {
        try {
            // Retrieve the cart from the session
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

    // ------ User removal methods ------ //

    /**
     * Remove a specific item from the database-based cart (for authenticated users).
     *
     * @param int $productId The ID of the product to remove
     * @return void
     */
    private function removeFromDatabaseCart(int $productId): void
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        DB::transaction(function () use ($productId, $cacheKey) {
            try {
                // Invalidate the cache before making changes
                Cache::forget($cacheKey);

                // Retrieve the user's cart
                $cart = Auth::user()->cart()->first();
                if ($cart) {
                    // Remove the item from the cart
                    $cart->items()->where('product_id', $productId)->delete();

                    // Update the cache with the remaining items
                    $formattedItems = $this->formatDatabaseItems($cart->fresh()->items);
                    Cache::put($cacheKey, $formattedItems, self::CACHE_TTL);
                }
            } catch (QueryException $e) {
                Log::error('Database Cart Removal Error: ' . $e->getMessage());
                throw new \Exception("Failed to remove item");
            }
        });
    }

    /**
     * Clear all items from the database-based cart (for authenticated users).
     *
     * @return void
     */
    private function clearDatabaseCart(): void
    {
        $cacheKey = self::CACHE_KEY . Auth::id();

        DB::transaction(function () use ($cacheKey) {
            try {
                // Retrieve the user's cart
                $cart = Auth::user()->cart()->first();
                if ($cart) {
                    // Remove all items from the cart
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
