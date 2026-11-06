<?php

namespace Database\Factories;

use App\Models\ItpFollowup;
use App\Models\ItpGeneration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItpFollowup>
 */
class ItpFollowupFactory extends Factory
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
            'round' => 1,
            'question_text' => fake()->sentence().'?',
            'answer_text' => null,
        ];
    }

    public function answered(?string $answer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'answer_text' => $answer ?? fake()->paragraph(),
            'answered_at' => now(),
        ]);
    }
}
