<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 8. Names the finished ITP from the settled attunement and derived slots.
 *
 * Returns `{title, description}`.
 */
class TitleStage
{
    public const int MAX_TITLE_LENGTH = 255;

    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return JsonSchema::object([
            'title' => JsonSchema::string('Two to five words, sentence case'),
            'description' => JsonSchema::string('One sentence saying what the piece is for'),
        ]);
    }

    /**
     * @return array{title: string, description: string}
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Title,
            StageInput::sections([
                'Settled attunement' => $generation->attunement->text(),
                'Derived slots' => StageInput::derivedSlots($generation->derived_slots),
                'Corrections' => StageInput::corrections($generation),
            ]),
            static::schema(),
            $generation,
        );

        $title = mb_substr(trim($result['title']), 0, self::MAX_TITLE_LENGTH);

        if ($title === '') {
            throw UnusableResponse::missing(ItpStage::Title, 'title', $result);
        }

        return [
            'title' => $title,
            'description' => trim($result['description']),
        ];
    }
}
