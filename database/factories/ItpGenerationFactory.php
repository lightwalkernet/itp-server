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
            'extraction' => ItpGenerationFactory::extraction(),
            'derived_slots' => ItpGenerationFactory::derivedSlots(),
        ])->afterCreating(function (ItpGeneration $generation) use ($segments) {
            $attunement = ItpAttunement::factory()->for($generation, 'generation')->create();

            foreach (range(1, $segments) as $order) {
                ItpAttunementSegment::factory()->for($attunement, 'attunement')->create(['order' => $order]);
            }
        });
    }

    /**
     * A full set of reversal-stage derived slots, in the prompt library's shape.
     *
     * @return array<string, mixed>
     */
    public static function derivedSlots(): array
    {
        return [
            'old_spell' => 'I have to earn rest.',
            'protective_function' => 'Earning kept me useful, and being useful kept me loved.',
            'itp_shift' => 'Rest is where I begin.',
            'reframe_family' => 'proving → embodying, remembering',
            'central_metaphor' => 'a tide that always returns',
            'essence_recognition' => 'You have carried your family through every storm.',
            'evidence_domains' => ['the sea at dawn', 'my daughter'],
            'register' => ['mode' => 'responsive_life', 'words_to_echo' => [], 'notes' => 'No spiritual language given.'],
            'temperature' => 'tender',
        ];
    }

    /**
     * A full extraction result, in the prompt library's shape: sufficient, with no gaps.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function extraction(array $overrides = []): array
    {
        return [
            'display_name' => 'Ann',
            'address_style' => 'beloved_name',
            'dedication' => 'To my daughter.',
            'concrete_outcome' => '',
            'proper_nouns' => [],
            'challenge' => 'I never rest.',
            'old_story_raw' => 'I have to earn my place.',
            'golden_phrase' => '',
            'protective_note' => '',
            'vision' => 'Standing on stage, at ease.',
            'scene' => '',
            'soulful_detail' => 'The sea at dawn.',
            'avoid' => '',
            'register_signals' => [],
            'tone_signals' => ['exhausted'],
            'sufficient' => true,
            'gaps' => [],
            ...$overrides,
        ];
    }

    /**
     * An insufficient extraction result with one gap per slot named.
     *
     * @return array<string, mixed>
     */
    public static function insufficientExtraction(string ...$slots): array
    {
        return static::extraction([
            'sufficient' => false,
            'gaps' => array_map(fn (string $slot) => ['slot' => $slot, 'why_thin' => "No {$slot} given."], $slots),
        ]);
    }
}
