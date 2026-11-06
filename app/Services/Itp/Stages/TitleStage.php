<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Names the finished ITP: a short title and a one-sentence description.
 */
class TitleStage
{
    public const int MAX_TITLE_LENGTH = 255;

    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array{title: string, description: string}
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Title,
            StageInput::sections([
                'Addressing' => StageInput::addressing($generation->intake),
                'Settled attunement' => $generation->attunement->text(),
            ]),
            JsonSchema::object([
                'title' => JsonSchema::string('A short title'),
                'description' => JsonSchema::string('One sentence describing the ITP'),
            ]),
            $generation,
        );

        $title = mb_substr(trim((string) ($result['title'] ?? '')), 0, self::MAX_TITLE_LENGTH);

        if ($title === '') {
            throw UnusableResponse::missing(ItpStage::Title, 'title');
        }

        return [
            'title' => $title,
            'description' => trim((string) ($result['description'] ?? '')),
        ];
    }
}
