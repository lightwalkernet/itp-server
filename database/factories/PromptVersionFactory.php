<?php

namespace Database\Factories;

use App\Enums\ItpStage;
use App\Models\PromptVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromptVersion>
 */
class PromptVersionFactory extends Factory
{
    /**
     * Define the model's default state. The model numbers the version when it is created.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stage' => ItpStage::Attunement,
            'body' => fake()->paragraph(),
        ];
    }

    public function forStage(ItpStage $stage): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => $stage,
        ]);
    }

    public function active(): static
    {
        return $this->afterCreating(fn (PromptVersion $promptVersion) => $promptVersion->activate());
    }
}
