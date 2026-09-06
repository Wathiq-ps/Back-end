<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(CurrencySeeder::class);
        $this->call(CountrySeeder::class);
        $this->call(TenantSeeder::class);
        $this->call(AmenitySeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'email' => 'test@example.com',
        ]);
    }
}
