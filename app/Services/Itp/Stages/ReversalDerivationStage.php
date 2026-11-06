<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 3, the engine. From the extracted slots (after the method core), derives the
 * single identity-level shift and the creative direction. The result is stored whole as the
 * generation's `derived_slots`.
 */
class ReversalDerivationStage
{
    /**
     * @var list<string>
     */
    public const array REGISTER_MODES = ['responsive_life', 'spiritual'];

    /**
     * @var list<string>
     */
    public const array TEMPERATURES = ['tender', 'propulsive', 'ceremonial', 'playful', 'steady'];

    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return JsonSchema::object([
            'old_spell' => JsonSchema::string('The limiting belief, as a first-person, identity-level sentence'),
            'protective_function' => JsonSchema::string('What the belief kept them safe from or helped them achieve'),
            'itp_shift' => JsonSchema::string('The new truth, alchemised from the old spell, present tense'),
            'reframe_family' => JsonSchema::string('The family the shift belongs to'),
            'central_metaphor' => JsonSchema::string('One fresh image from this person\'s world'),
            'essence_recognition' => JsonSchema::string('One line naming what they already carry or have lived'),
            'evidence_domains' => JsonSchema::stringList('The concrete places and details the shift will show up in'),
            'register' => JsonSchema::object([
                'mode' => JsonSchema::enum(self::REGISTER_MODES, 'spiritual only when register signals are present'),
                'words_to_echo' => JsonSchema::stringList('Their exact spiritual words to echo; empty for responsive_life'),
                'notes' => JsonSchema::string('Notes on the register'),
            ]),
            'temperature' => JsonSchema::enum(self::TEMPERATURES, 'Inferred from how they wrote'),
        ]);
    }

    /**
     * @return array<string, mixed> The library's derived-slots object
     */
    public function run(ItpGeneration $generation): array
    {
        return $this->model->generate(
            ItpStage::Reversal,
            StageInput::sections([
                'Slots' => StageInput::slots($generation->extraction),
            ]),
            static::schema(),
            $generation,
        );
    }
}
