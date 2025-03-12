<?php

namespace Database\Seeders;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        //  Admin User 
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'phone' => '0123456789',
            'password' => Hash::make('password'), // Default password
        ]);

        // Create 10 Fake Users
        for ($i = 0; $i < 10; $i++) {
            User::create([
                'name' => $faker->name(),
                'email' => $faker->unique()->safeEmail(),
                'phone' => $faker->phoneNumber(),
                'password' => Hash::make('password'), // Default password
            ]);
        }
    }
}
