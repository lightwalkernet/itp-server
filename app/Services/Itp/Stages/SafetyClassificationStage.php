<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpSafetyRoute;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 0. Routes the raw intake before anything is generated. A verdict below the
 * minimum confidence (`itp.generation.safety_min_confidence`) fails closed to sensitive. A failed
 * call is not a verdict: it throws, so the job retries instead of holding the generation.
 *
 * Returns `{route, categories[], confidence, reason}`.
 */
class SafetyClassificationStage
{
    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return JsonSchema::object([
            'route' => JsonSchema::enum(array_column(ItpSafetyRoute::cases(), 'value'), 'clear, sensitive or block'),
            'categories' => JsonSchema::stringList('Short tags, e.g. health, grief, minor'),
            'confidence' => JsonSchema::number('Confidence in the route chosen, 0.0 to 1.0'),
            'reason' => JsonSchema::string('One sentence, for staff review only'),
        ]);
    }

    /**
     * @return array{route: ItpSafetyRoute, categories: list<string>, confidence: float, reason: string}
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Safety,
            StageInput::sections(['Intake' => StageInput::intake($generation->intake)]),
            static::schema(),
            $generation,
        );

        $route = ItpSafetyRoute::from($result['route']);
        $confidence = max(0.0, min(1.0, (float) $result['confidence']));

        if ($confidence < (float) config('itp.generation.safety_min_confidence')) {
            $route = ItpSafetyRoute::Sensitive;
        }

        return [
            'route' => $route,
            'categories' => array_values(array_filter(array_map('trim', $result['categories']), fn (string $category) => $category !== '')),
            'confidence' => $confidence,
            'reason' => trim($result['reason']),
        ];
    }
}
