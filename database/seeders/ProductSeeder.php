<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Product;
use Faker\Factory as Faker;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        for ($i = 0; $i < 10; $i++) { // Generate 10 fake products
            Product::create([
                'name' => $faker->word(), // Random product name
                'description' => $faker->sentence(10), // Random description (10 words)
                'price' => $faker->randomFloat(2, 100, 5000), // Price between 100 and 5000
                'stock' => $faker->numberBetween(1, 50), // Stock between 1 and 50
            ]);
        }
    }
}
