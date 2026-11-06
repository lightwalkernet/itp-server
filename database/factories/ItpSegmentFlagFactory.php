<?php

namespace Database\Factories;

use App\Enums\ItpSegmentFlagStatus;
use App\Models\ItpAttunementSegment;
use App\Models\ItpSegmentFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItpSegmentFlag>
 */
class ItpSegmentFlagFactory extends Factory
{
    /**
     * Define the model's default state. The flag belongs to its segment's generation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'segment_id' => ItpAttunementSegment::factory(),
            'generation_id' => fn (array $attributes) => ItpAttunementSegment::find($attributes['segment_id'])->attunement->generation_id,
            'user_explanation' => fake()->sentence(),
            'requested_change' => fake()->optional()->sentence(),
            'status' => ItpSegmentFlagStatus::Open,
        ];
    }
}
