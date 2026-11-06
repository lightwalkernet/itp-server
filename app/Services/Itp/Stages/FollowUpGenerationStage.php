<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Asks one or two targeted questions about the gaps the extraction found. Sees the earlier
 * rounds, so it never asks the same thing twice.
 */
class FollowUpGenerationStage
{
    public const int MAX_QUESTIONS = 2;

    public function __construct(protected StructuredModel $model) {}

    /**
     * @param  list<string>  $gaps
     * @return list<string>
     */
    public function run(ItpGeneration $generation, array $gaps): array
    {
        $result = $this->model->generate(
            ItpStage::Followups,
            StageInput::sections([
                'Intake' => StageInput::intake($generation->intake),
                'Follow-up answers' => StageInput::followupAnswers($generation),
                'Gaps' => collect($gaps)->map(fn (string $gap) => "- {$gap}")->implode("\n"),
                'Round' => StageInput::fields([
                    'This round' => (string) ($generation->followup_rounds + 1),
                    'Last possible round' => (string) config('itp.generation.max_followup_rounds'),
                ]),
            ]),
            JsonSchema::object([
                'questions' => JsonSchema::list(['type' => 'string'], 'One or two follow-up questions'),
            ]),
            $generation,
        );

        return collect((array) ($result['questions'] ?? []))
            ->filter(fn (mixed $question) => is_string($question) && trim($question) !== '')
            ->map(fn (string $question) => trim($question))
            ->take(self::MAX_QUESTIONS)
            ->values()
            ->all();
    }
}
