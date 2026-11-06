<?php

namespace Database\Factories;

use App\Enums\ItpCompanionType;
use App\Models\ItpCompanion;
use App\Models\ItpGeneration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItpCompanion>
 */
class ItpCompanionFactory extends Factory
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
            'type' => ItpCompanionType::Breathe11,
            'content' => [['order' => 1, 'text' => fake()->sentence()]],
            'from_attunement_version' => 1,
        ];
    }

    public function quantumWhys(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ItpCompanionType::QuantumWhys,
            'content' => ['Why is it so easy for me to rest?'],
        ]);
    }
}
