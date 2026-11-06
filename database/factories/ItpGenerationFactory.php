<?php

namespace Database\Factories;

use App\Enums\ItpStatus;
use App\Models\ItpAttunement;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpIntake;
use Illuminate\Database\Eloquent\Factories\Factory;
use Itp\Core\Models\User;

/**
 * @extends Factory<ItpGeneration>
 */
class ItpGenerationFactory extends Factory
{
    /**
     * Define the model's default state. The intake belongs to the same user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'intake_id' => fn (array $attributes) => ItpIntake::factory()->state(['user_id' => $attributes['user_id']]),
            'status' => ItpStatus::Queued,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withStatus(ItpStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * Awaiting resonance, with an attunement of `$segments` lines.
     */
    public function awaitingResonance(int $segments = 3): static
    {
        return $this->withStatus(ItpStatus::AwaitingResonance)->withAttunement($segments);
    }

    /**
     * Give the generation an attunement (version 1) of `$segments` lines.
     */
    public function withAttunement(int $segments = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'derived_slots' => ItpGenerationFactory::derivedSlots(),
        ])->afterCreating(function (ItpGeneration $generation) use ($segments) {
            $attunement = ItpAttunement::factory()->for($generation, 'generation')->create();

            foreach (range(1, $segments) as $order) {
                ItpAttunementSegment::factory()->for($attunement, 'attunement')->create(['order' => $order]);
            }
        });
    }

    /**
     * A full set of reversal-stage slots.
     *
     * @return array<string, string>
     */
    public static function derivedSlots(): array
    {
        return [
            'reversal_old' => 'I have to earn rest.',
            'reversal_new' => 'Rest is where I begin.',
            'register' => 'tender',
            'temperature' => 'warm',
            'central_metaphor' => 'a tide that always returns',
            'mirrored_vocab' => 'steady, home, breathe',
        ];
    }
}
