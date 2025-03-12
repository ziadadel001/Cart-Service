<?php
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_a_product()
    {
        $product = Product::factory()->create([
            'name' => 'Laptop',
            'description' => 'A high-end gaming laptop',
            'price' => 1500.99,
            'stock' => 10
        ]);

        $this->assertDatabaseHas('products', [
            'name' => 'Laptop',
            'price' => 1500.99
        ]);
    }
}
