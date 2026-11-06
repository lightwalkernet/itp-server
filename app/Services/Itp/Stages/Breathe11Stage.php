<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Derives the Breathe 11 companion from the settled attunement.
 */
class Breathe11Stage
{
    public function __construct(protected StructuredModel $model) {}

    /**
     * @return list<array{order: int, text: string}>
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Breathe11,
            StageInput::sections([
                'Addressing' => StageInput::addressing($generation->intake),
                'Settled attunement' => $generation->attunement->text(),
            ]),
            JsonSchema::object([
                'lines' => JsonSchema::list(
                    JsonSchema::object(['text' => JsonSchema::string('One Breathe 11 line')]),
                    'The Breathe 11 lines, in order',
                ),
            ]),
            $generation,
        );

        $lines = collect((array) ($result['lines'] ?? []))
            ->map(fn (mixed $line) => is_array($line) ? trim((string) ($line['text'] ?? '')) : '')
            ->filter(fn (string $text) => $text !== '')
            ->values()
            ->map(fn (string $text, int $index) => ['order' => $index + 1, 'text' => $text])
            ->all();

        return $lines !== [] ? $lines : throw UnusableResponse::missing(ItpStage::Breathe11, 'lines');
    }
}
