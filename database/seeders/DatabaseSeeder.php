<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            IndicatorCategorySeeder::class,
            IndicatorSeeder::class,
        ]);

        // A development login only (factory password "password"): never on a server.
        if (app()->environment('local', 'testing')) {
            User::factory()->admin()->create([
                'nip' => '000000001',
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }
    }
}
