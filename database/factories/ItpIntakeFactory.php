<?php

namespace Database\Factories;

use App\Enums\ItpAddressStyle;
use App\Models\ItpIntake;
use Illuminate\Database\Eloquent\Factories\Factory;
use Itp\Core\Models\User;

/**
 * @extends Factory<ItpIntake>
 */
class ItpIntakeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'display_name' => fake()->firstName(),
            'address_style' => fake()->randomElement(ItpAddressStyle::cases()),
            'q1_dedication' => fake()->paragraph(),
            'q2_challenge' => fake()->paragraph(),
            'q3_old_story' => fake()->paragraph(),
            'q3_phrase' => fake()->optional()->sentence(),
            'q3_protective' => fake()->optional()->sentence(),
            'q4_vision' => fake()->paragraph(),
            'q4_scene' => fake()->optional()->sentence(),
            'q5_soulful' => fake()->paragraph(),
            'avoid' => fake()->optional()->sentence(),
        ];
    }
}
