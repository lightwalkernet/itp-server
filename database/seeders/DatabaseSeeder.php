<?php

namespace Database\Seeders;

use App\Models\Staff;
use Illuminate\Database\Seeder;
use Itp\Core\Models\User;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events stay on: synced models stamp their change number in them.
     */
    public function run(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'first_name' => 'Test',
        ]);

        Staff::factory()->owner()->create([
            'email' => 'owner@example.com',
            'name' => 'Test Owner',
        ]);

        Staff::factory()->editor()->create([
            'email' => 'editor@example.com',
            'name' => 'Test Editor',
        ]);

        $this->call(PromptSeeder::class);
    }
}
