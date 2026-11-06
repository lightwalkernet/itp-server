<?php

namespace Database\Factories;

use App\Models\ItpAttunement;
use App\Models\ItpGeneration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItpAttunement>
 */
class ItpAttunementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'generation_id' => ItpGeneration::factory(),
            'version' => 1,
        ];
    }
}
