<?php

namespace Database\Factories;

use App\Enums\ItpSegmentFlagStatus;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItpSegmentFlag>
 */
class ItpSegmentFlagFactory extends Factory
{
    /**
     * Define the model's default state: a flag on the section of the line it pinpoints, in that
     * line's generation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'segment_id' => ItpAttunementSegment::factory(),
            'generation_id' => fn (array $attributes) => ItpAttunementSegment::find($attributes['segment_id'])->attunement->generation_id,
            'part' => fn (array $attributes) => ItpAttunementSegment::find($attributes['segment_id'])?->sectionPart(),
            'user_explanation' => fake()->sentence(),
            'requested_change' => fake()->optional()->sentence(),
            'status' => ItpSegmentFlagStatus::Open,
        ];
    }

    /**
     * A flag on a whole section, pinpointing no line.
     */
    public function forSection(ItpGeneration $generation, int $part): static
    {
        return $this->state(fn (array $attributes) => [
            'generation_id' => $generation->id,
            'part' => $part,
            'segment_id' => null,
        ]);
    }

    /**
     * A line flag from before prompt library v2: no part.
     */
    public function legacy(): static
    {
        return $this->state(fn (array $attributes) => [
            'part' => null,
        ]);
    }
}
