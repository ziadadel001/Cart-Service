# 🛒 Cart Service API - Laravel 12

## 🚀 Overview
The **Cart Service** provides a powerful and efficient cart management system within a Laravel 12 e-commerce API. It enables users to add, retrieve, update, and remove products from their cart while ensuring smooth handling for both guests and authenticated users. The service supports cart merging upon login, quantity updates, and session-based storage for guests, ensuring a seamless shopping experience.

## 🔑 Features

- **Guest & Authenticated User Support**
  - Guests can add products to their cart (stored in the session).
  - Authenticated users’ cart data is stored in the database.
  - When a guest logs in, their cart is merged with their existing user cart.
- **Cart Functionality**
  - Add, retrieve, update, and remove items from the cart.
  - Prevent adding negative values or exceeding stock limits.
  - Clear cart functionality.
  - High performance, even with thousands of items.



## Database Structure
To properly implement the cart system, you need to have four database tables:

### 1. Users Table (`users`)
Stores user information.

### 2. Products Table (`products`)
Stores product details.

### 3. Carts Table (`carts`)
Stores cart details, linked to users.

### 4. Cart Items Table (`cart_items`)
Stores products added to the cart.

## Relationships

### In `User` Model:
```php
public function cart(): HasOne
{
    return $this->hasOne(Cart::class);
}
```

### In `Product` Model:
```php
public function cartItems(): HasMany
{
    return $this->hasMany(CartItem::class);
}
```

### In `Cart` Model:
```php
public function user() : BelongsTo
{
    return $this->belongsTo(User::class);
}

public function items(): HasMany
{
    return $this->hasMany(CartItem::class);
}
```

### In `CartItem` Model:
```php
public function cart() : BelongsTo
{
    return $this->belongsTo(Cart::class);
}

public function product() : BelongsTo
{
    return $this->belongsTo(Product::class);
}
```

## 🔹 Code Usage
## 1. Injecting `CartService` into the Controller
Use **Dependency Injection** in the constructor.

```php
use App\Services\CartService;

class CartController extends Controller
{
    protected CartService $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }
}
```

## 2. Adding a Product to the Cart
Use `addItem` to add the product to the cart.

```php
public function addToCart(Product $product)
{
    $this->cartService->addItem($product, 1);
    return response()->json(['message' => 'Product added to cart']);
}
```

## 3. Retrieving Items from the Cart
Use `getItems` to retrieve all items.

```php
public function getCartItems()
{
    $items = $this->cartService->getItems();
    return response()->json($items);
}
```

## 4. Merging Guest Cart on Login
Call `mergeCartsOnLogin` when the user logs in.

```php
public function mergeCartOnLogin()
{
    $this->cartService->mergeCartsOnLogin();
}
```

## 5. Removing an Item from the Cart
Use `removeItem` to remove a product from the cart.

```php
public function removeFromCart(Product $product)
{
    $this->cartService->removeItem($product);
    return response()->json(['message' => 'Product removed from cart']);
}
```
## 6. Updating Item Quantity in the Cart
Use `updateQuantity` to modify the quantity of a product.

```php
public function updateCartQuantity(Product $product, int $quantity)
{
    $this->cartService->updateQuantity($product, $quantity);
    return response()->json(['message' => 'Cart updated successfully']);
}
```
## 7. Clearing the Cart
Use `clearCart` to remove all items.
```php
public function clearCart()
{
    $this->cartService->clearCart();
    return response()->json(['message' => 'Cart cleared']);
}
```

### Notes
- The cart is stored in the **session** for guests.
- The cart is saved in the **database** for registered users.

## 🧪 PHPUnit Test Results

```bash
 php artisan test --filter=CartServiceTest

   PASS  Tests\Feature\CartServiceTest
  ✓ guest can add product to cart                                                                                    0.96s
  ✓ authenticated user can add product to cart                                                                       0.06s
  ✓ guest can retrieve cart items                                                                                    0.04s
  ✓ authenticated user can retrieve cart items                                                                       0.04s
  ✓ guest can remove product from cart                                                                               0.04s
  ✓ authenticated user can remove product from cart                                                                  0.04s
  ✓ guest can clear cart                                                                                             0.04s
  ✓ authenticated user can clear cart                                                                                0.04s
  ✓ cart merges on login                                                                                             0.03s
  ✓ cannot add more than max quantity                                                                                0.03s
  ✓ cannot add more than available stock                                                                             0.03s
  ✓ cannot add negative quantity                                                                                     0.03s
  ✓ adding same product twice increases quantity                                                                     0.04s
  ✓ cannot add non existent product                                                                                  0.04s
  ✓ cannot add product with zero quantity                                                                            0.03s
  ✓ performance with thousands of items                                                                              0.87s
  Tests:    16 passed (26 assertions)
  Duration: 2.51s
```

## ⚡ Performance

The system was tested with **1,000 products**, ensuring fast response times and optimal database queries. Redis is used for caching cart data, improving speed and efficiency.


## 📌 Next Steps

- Implement **coupon & discount system**.
- Add **multi-cart support** for different user sessions.
- Optimize for **API response caching** with Redis.

---

**Developed by Ziad Adel Gomaa** 🚀
