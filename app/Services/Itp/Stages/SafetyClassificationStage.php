<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpSafetyRoute;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Classifies the intake before anything is generated. An unclear verdict (unknown route or low
 * confidence) fails closed to sensitive. A failed call is not a verdict: it throws, so the job
 * retries instead of holding the generation.
 */
class SafetyClassificationStage
{
    public const float MIN_CONFIDENCE = 0.7;

    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array{route: ItpSafetyRoute, categories: list<string>, confidence: float}
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Safety,
            StageInput::sections(['Intake' => StageInput::intake($generation->intake)]),
            JsonSchema::object([
                'route' => ['type' => 'string', 'enum' => array_column(ItpSafetyRoute::cases(), 'value'), 'description' => 'clear, sensitive or block'],
                'categories' => JsonSchema::list(['type' => 'string'], 'Flagged categories, if any'),
                'confidence' => ['type' => 'number', 'description' => 'Confidence from 0 to 1'],
            ]),
            $generation,
        );

        $route = ItpSafetyRoute::tryFrom((string) ($result['route'] ?? ''));
        $confidence = (float) ($result['confidence'] ?? 0);

        if ($route === null || $confidence < self::MIN_CONFIDENCE) {
            $route = ItpSafetyRoute::Sensitive;
        }

        return [
            'route' => $route,
            'categories' => array_values(array_filter((array) ($result['categories'] ?? []), 'is_string')),
            'confidence' => $confidence,
        ];
    }
}
