<?php

namespace Database\Seeders;

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
    }
}
