<?php

use App\Actions\Itp\SubmitFollowupsAction;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpSafetyRoute;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Jobs\GenerateItpJob;
use App\Models\ItpGeneration;
use App\Services\Itp\StageInput;
use Database\Factories\ItpGenerationFactory;
use Database\Seeders\PromptSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PromptSeeder::class);

    $this->generation = ItpGeneration::factory()->create();

    $this->statuses = [];
    ItpGeneration::updating(function (ItpGeneration $generation) {
        if ($generation->isDirty('status')) {
            $this->statuses[] = $generation->status;
        }
    });
});

/**
 * Run one attempt of the job, as a worker would, minus the queue: an exception propagates and
 * nothing marks the generation failed.
 */
function runGenerateJob(string $generationId): void
{
    app()->call([new GenerateItpJob($generationId), 'handle']);
}

/**
 * A follow-ups response asking each question, in the library's shape.
 *
 * @return array{questions: list<array{gap_slot: string, question_text: string}>}
 */
function followupQuestions(string ...$questions): array
{
    return ['questions' => array_map(fn (string $question) => ['gap_slot' => 'scene', 'question_text' => $question], $questions)];
}

/**
 * Answer every open question of the generation, as the API does, without running the job.
 */
function answerOpenRound(ItpGeneration $generation, string $answer): void
{
    Queue::fake();

    app(SubmitFollowupsAction::class)->execute($generation->fresh(), $generation->fresh()->openFollowups()
        ->map(fn ($followup) => ['followup_id' => $followup->id, 'answer_text' => $answer])
        ->all());
}

it('skips the safety check by default and goes straight to assessing', function () {
    runGenerateJob($this->generation->id);

    fakeAi()->assertNotCalled(ItpStage::Safety);
    expect($this->statuses)->toBe([ItpStatus::Assessing, ItpStatus::Generating, ItpStatus::AwaitingResonance])
        ->and($this->generation->fresh()->safety_route)->toBeNull();
});

it('writes the attunement when the intake is sufficient', function () {
    runGenerateJob($this->generation->id);

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(ItpStatus::AwaitingResonance)
        ->and($generation->extraction)->toBe(ItpGenerationFactory::extraction())
        ->and($generation->derived_slots)->toBe(ItpGenerationFactory::derivedSlots())
        ->and($generation->attunement->version)->toBe(1)
        ->and($generation->attunement->currentSegments->pluck('text', 'order')->all())->toBe([
            1 => 'Beloved one, breathe in.',
            2 => 'You are already home.',
            3 => 'Rest is where you begin.',
        ])
        ->and(fakeAi()->lastInput(ItpStage::Reversal))->toBe("## Slots\n\n".StageInput::slots(ItpGenerationFactory::extraction()))
        ->and(fakeAi()->lastInput(ItpStage::Attunement))
        ->toContain("## Address style\n\n{$generation->intake->address_style->value}")
        ->toContain('## Slots')
        ->toContain("## Derived slots\n\n{\n    \"old_spell\": \"I have to earn rest.\",");
});

it('asks a round of follow-ups when the extraction finds gaps', function () {
    fakeAi()
        ->respondWith(ItpStage::Extraction, ItpGenerationFactory::insufficientExtraction('vision', 'scene'))
        ->respondWith(ItpStage::Followups, ['questions' => [
            ['gap_slot' => 'vision', 'question_text' => 'What do you see?'],
            ['gap_slot' => 'scene', 'question_text' => 'Where are you?'],
            ['gap_slot' => 'scene', 'question_text' => 'A third one?'],
        ]]);

    runGenerateJob($this->generation->id);

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(ItpStatus::AwaitingFollowup)
        ->and($generation->followup_rounds)->toBe(1)
        ->and($generation->openFollowups()->pluck('question_text', 'gap_slot')->all())->toBe(['vision' => 'What do you see?', 'scene' => 'Where are you?'])
        ->and($generation->extraction['gaps'])->toBe([['slot' => 'vision', 'why_thin' => 'No vision given.'], ['slot' => 'scene', 'why_thin' => 'No scene given.']])
        ->and(fakeAi()->lastInput(ItpStage::Followups))
        ->toStartWith('## Slots')
        ->toContain("## Gaps\n\n".StageInput::gaps($generation->extraction))
        ->not->toContain('## Intake');
    fakeAi()->assertNotCalled(ItpStage::Reversal);
});

it('re-runs the extraction after the answers and asks a second round, with every answer in later inputs', function () {
    fakeAi()
        ->respondWith(ItpStage::Extraction,
            ItpGenerationFactory::insufficientExtraction('vision'),
            ItpGenerationFactory::insufficientExtraction('scene'),
            ItpGenerationFactory::extraction(['scene' => 'On the shore.']),
        )
        ->respondWith(ItpStage::Followups, followupQuestions('What do you see?'), followupQuestions('Where are you standing?'));

    runGenerateJob($this->generation->id);
    answerOpenRound($this->generation, 'The sea at dawn.');
    runGenerateJob($this->generation->id);

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(ItpStatus::AwaitingFollowup)
        ->and($generation->followup_rounds)->toBe(2)
        ->and($generation->openFollowups()->pluck('question_text')->all())->toBe(['Where are you standing?'])
        ->and(fakeAi()->lastInput(ItpStage::Extraction))->toContain("## Follow-up answers\n\nRound 1\n\nQ: What do you see?\nA: The sea at dawn.")
        ->and(fakeAi()->lastInput(ItpStage::Followups))->toContain('Q: What do you see?');

    answerOpenRound($generation, 'On the shore.');
    runGenerateJob($this->generation->id);

    expect($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance);
    fakeAi()->assertCalled(ItpStage::Extraction, 3);

    expect(fakeAi()->lastInput(ItpStage::Extraction))
        ->toContain("Round 1\n\nQ: What do you see?\nA: The sea at dawn.")
        ->toContain("Round 2\n\nQ: Where are you standing?\nA: On the shore.")
        ->and(fakeAi()->lastInput(ItpStage::Reversal))->toContain('"scene": "On the shore."');
});

it('marks a skipped question as skipped in later inputs', function () {
    fakeAi()
        ->respondWith(ItpStage::Extraction, ItpGenerationFactory::insufficientExtraction('vision'))
        ->respondWith(ItpStage::Followups, followupQuestions('What do you see?'));

    runGenerateJob($this->generation->id);
    answerOpenRound($this->generation, '');
    runGenerateJob($this->generation->id);

    expect(fakeAi()->lastInput(ItpStage::Extraction))->toContain("Q: What do you see?\nA: (skipped)");
});

it('generates after the last follow-up round even when gaps remain', function () {
    config(['itp.generation.max_followup_rounds' => 2]);
    fakeAi()->respondWith(ItpStage::Extraction, ...array_fill(0, 3, ItpGenerationFactory::insufficientExtraction('vision')));

    runGenerateJob($this->generation->id);
    answerOpenRound($this->generation, 'The sea.');
    runGenerateJob($this->generation->id);
    answerOpenRound($this->generation, 'The shore.');
    runGenerateJob($this->generation->id);

    expect($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance)
        ->and($this->generation->fresh()->followup_rounds)->toBe(2);
    fakeAi()->assertCalled(ItpStage::Followups, 2);
});

it('goes on to generating when the follow-up stage asks nothing', function () {
    fakeAi()
        ->respondWith(ItpStage::Extraction, ItpGenerationFactory::insufficientExtraction('vision'))
        ->respondWith(ItpStage::Followups, ['questions' => [['gap_slot' => 'vision', 'question_text' => '  ']]]);

    runGenerateJob($this->generation->id);

    expect($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance)
        ->and($this->generation->followups()->count())->toBe(0);
});

describe('with the safety check on', function () {
    beforeEach(function () {
        config(['itp.generation.safety_check' => true]);
    });

    it('classifies first and goes on when the intake is clear', function () {
        runGenerateJob($this->generation->id);

        $generation = $this->generation->fresh();
        expect($this->statuses)->toBe([ItpStatus::Classifying, ItpStatus::Assessing, ItpStatus::Generating, ItpStatus::AwaitingResonance])
            ->and($generation->safety_route)->toBe(ItpSafetyRoute::Clear)
            ->and($generation->safety_confidence)->toBe(0.98)
            ->and($generation->safety_reason)->toBe('Ordinary rest and confidence goals.')
            ->and(fakeAi()->lastInput(ItpStage::Safety))->toStartWith("## Intake\n\nDisplay name: {$generation->intake->display_name}");
    });

    it('holds or refuses the intake by its verdict, generating nothing', function (array $verdict, ItpStatus $status, ItpSafetyRoute $route) {
        fakeAi()->respondWith(ItpStage::Safety, $verdict);

        runGenerateJob($this->generation->id);

        expect($this->generation->fresh()->status)->toBe($status)
            ->and($this->generation->fresh()->safety_route)->toBe($route);
        fakeAi()->assertNotCalled(ItpStage::Extraction);
    })->with([
        'sensitive' => [['route' => 'sensitive', 'categories' => ['grief'], 'confidence' => 0.9, 'reason' => 'Recent loss.'], ItpStatus::Deferred, ItpSafetyRoute::Sensitive],
        'block' => [['route' => 'block', 'categories' => ['harm'], 'confidence' => 0.95, 'reason' => 'Harm to others.'], ItpStatus::Blocked, ItpSafetyRoute::Block],
        'unsure' => [['route' => 'clear', 'categories' => [], 'confidence' => 0.4, 'reason' => 'Unclear.'], ItpStatus::Deferred, ItpSafetyRoute::Sensitive],
    ]);

    it('treats a verdict below the configured confidence as sensitive', function () {
        config(['itp.generation.safety_min_confidence' => 0.9]);
        fakeAi()->respondWith(ItpStage::Safety, ['route' => 'clear', 'categories' => [], 'confidence' => 0.85, 'reason' => 'Probably fine.']);

        runGenerateJob($this->generation->id);

        expect($this->generation->fresh())
            ->status->toBe(ItpStatus::Deferred)
            ->safety_route->toBe(ItpSafetyRoute::Sensitive)
            ->safety_confidence->toBe(0.85);
    });

    it('fails outright on a route outside the library\'s set', function () {
        fakeAi()->respondWith(ItpStage::Safety, ['route' => 'maybe', 'categories' => [], 'confidence' => 0.9, 'reason' => '']);

        expect(fn () => runGenerateJob($this->generation->id))->toThrow(UnusableResponse::class, '$.route must be one of clear, sensitive, block');

        expect($this->generation->fresh()->safety_route)->toBeNull();
    });

    it('throws to retry on an outage instead of deferring', function () {
        fakeAi()->respondWith(ItpStage::Safety, new RuntimeException('Overloaded'));

        expect(fn () => runGenerateJob($this->generation->id))->toThrow(RuntimeException::class, 'Overloaded');

        expect($this->generation->fresh()->status)->toBe(ItpStatus::Classifying)
            ->and($this->generation->fresh()->safety_route)->toBeNull();

        runGenerateJob($this->generation->id);

        expect($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance);
    });
});

it('resumes from its status without repeating stored steps', function () {
    $this->generation->update(['status' => ItpStatus::Generating, 'derived_slots' => ['old_spell' => 'Stored.']]);

    runGenerateJob($this->generation->id);

    fakeAi()->assertNotCalled(ItpStage::Extraction);
    fakeAi()->assertNotCalled(ItpStage::Reversal);
    expect($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance)
        ->and(fakeAi()->lastInput(ItpStage::Attunement))->toContain('"old_spell": "Stored."');
});

it('does nothing for a generation that is waiting on the user or finished', function (ItpStatus $status) {
    $this->generation->update(['status' => $status]);

    runGenerateJob($this->generation->id);

    expect(fakeAi()->calls())->toBe([])
        ->and($this->generation->fresh()->status)->toBe($status);
})->with([ItpStatus::AwaitingFollowup, ItpStatus::AwaitingResonance, ItpStatus::Complete, ItpStatus::Deferred]);

it('marks the generation failed with the error once it gives up', function () {
    fakeAi()->respondWith(ItpStage::Attunement, new RuntimeException('Overloaded'));

    expect(fn () => dispatch(new GenerateItpJob($this->generation->id)))->toThrow(RuntimeException::class);

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(ItpStatus::Failed)
        ->and($generation->last_error)->toBe('RuntimeException: Overloaded')
        ->and($generation->status->userMessage())->toBe(config('itp.messages.failed'));
});
