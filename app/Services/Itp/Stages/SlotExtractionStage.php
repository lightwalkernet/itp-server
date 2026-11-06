<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpAddressStyle;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 1. Extracts the literal slots from the intake and every follow-up answer so far,
 * and judges whether they are sufficient, naming each gap. Runs again after each round of
 * answers. The result is stored whole as the generation's `extraction`.
 */
class SlotExtractionStage
{
    /**
     * The literal string slots, in the library's order.
     *
     * @var array<string, string>
     */
    public const array STRING_SLOTS = [
        'display_name' => 'As given',
        'address_style' => 'beloved_name or beloved_one, as given',
        'dedication' => 'The area of life this is for, in their words',
        'concrete_outcome' => 'The specific result named, exactly as written, or empty',
        'proper_nouns' => 'Every real name of a person, animal, place, business or project',
        'challenge' => 'What makes it feel hard or out of reach',
        'old_story_raw' => 'The belief, wound or history they described',
        'golden_phrase' => 'One exact phrase of theirs that carries the belief, verbatim, or empty',
        'protective_note' => 'What they said the belief protected or enabled, or empty',
        'vision' => 'How life looks and feels after the shift',
        'scene' => 'The specific moment or sign they named, or empty',
        'soulful_detail' => 'The place, person, memory, symbol or element of nature offered',
        'avoid' => 'Anything they asked not to be included, or empty',
        'register_signals' => 'Their own words indicating spiritual framing, verbatim; empty if none',
        'tone_signals' => 'Words indicating emotional temperature',
    ];

    /**
     * The slots that are lists of strings.
     *
     * @var list<string>
     */
    public const array LIST_SLOTS = ['proper_nouns', 'register_signals', 'tone_signals'];

    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $slots = collect(self::STRING_SLOTS)->map(fn (string $description, string $slot) => match (true) {
            $slot === 'address_style' => JsonSchema::enum(array_column(ItpAddressStyle::cases(), 'value'), $description),
            in_array($slot, self::LIST_SLOTS, true) => JsonSchema::stringList($description),
            default => JsonSchema::string($description),
        })->all();

        return JsonSchema::object([
            ...$slots,
            'sufficient' => JsonSchema::boolean('Whether there is enough to find an identity-level shift and write a piece only for this person'),
            'gaps' => JsonSchema::list(JsonSchema::object([
                'slot' => JsonSchema::string('The slot that is missing or thin'),
                'why_thin' => JsonSchema::string('Why, precisely enough to write a follow-up question from'),
            ]), 'Empty when sufficient'),
        ]);
    }

    /**
     * @return array<string, mixed> The library's extraction object: the slots, `sufficient` and `gaps`
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Extraction,
            StageInput::sections([
                'Intake' => StageInput::intake($generation->intake),
                'Follow-up answers' => StageInput::followupAnswers($generation),
            ]),
            static::schema(),
            $generation,
        );

        $result['gaps'] = array_values(array_filter(
            $result['gaps'],
            fn (array $gap) => trim($gap['slot']) !== '' || trim($gap['why_thin']) !== '',
        ));

        return $result;
    }
}
