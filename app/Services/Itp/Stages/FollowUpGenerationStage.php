<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 2. Asks one or two questions about the gaps in the stored extraction. It is
 * given the slots and gaps, and the earlier rounds, so it never asks the same thing twice.
 *
 * Returns `{questions: [{gap_slot, question_text}]}`.
 */
class FollowUpGenerationStage
{
    public const int MAX_QUESTIONS = 2;

    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return JsonSchema::object([
            'questions' => JsonSchema::list(JsonSchema::object([
                'gap_slot' => JsonSchema::string('The gap slot this question fills'),
                'question_text' => JsonSchema::string('The question, as the person will read it'),
            ]), 'One or two follow-up questions'),
        ]);
    }

    /**
     * @return list<array{gap_slot: ?string, question_text: string}>
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Followups,
            StageInput::sections([
                'Slots' => StageInput::slots($generation->extraction),
                'Gaps' => StageInput::gaps($generation->extraction),
                'Follow-up answers' => StageInput::followupAnswers($generation),
            ]),
            static::schema(),
            $generation,
        );

        return collect($result['questions'])
            ->map(fn (array $question) => [
                'gap_slot' => filled(trim($question['gap_slot'])) ? mb_substr(trim($question['gap_slot']), 0, 64) : null,
                'question_text' => trim($question['question_text']),
            ])
            ->filter(fn (array $question) => $question['question_text'] !== '')
            ->take(self::MAX_QUESTIONS)
            ->values()
            ->all();
    }
}
