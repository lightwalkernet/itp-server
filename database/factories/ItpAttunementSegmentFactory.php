<?php

namespace Database\Factories;

use App\Models\ItpAttunement;
use App\Models\ItpAttunementSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItpAttunementSegment>
 */
class ItpAttunementSegmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attunement_id' => ItpAttunement::factory(),
            'order' => 1,
            'text' => fake()->sentence(),
            'version' => 1,
        ];
    }

    public function superseded(): static
    {
        return $this->state(fn (array $attributes) => [
            'superseded_at' => now(),
        ]);
    }
}
