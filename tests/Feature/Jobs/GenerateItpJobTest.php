<?php

use App\Actions\Itp\SubmitFollowupsAction;
use App\Enums\ItpSafetyRoute;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Jobs\GenerateItpJob;
use App\Models\ItpGeneration;
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
        ->and($generation->derived_slots['central_metaphor'])->toBe('a tide that always returns')
        ->and($generation->attunement->version)->toBe(1)
        ->and($generation->attunement->currentSegments->pluck('text', 'order')->all())->toBe([
            1 => 'Beloved one, breathe in.',
            2 => 'You are already home.',
            3 => 'Rest is where you begin.',
        ])
        ->and(fakeAi()->lastInput(ItpStage::Attunement))->toContain("## Derived slots\n\nreversal_old: I have to earn rest.");
});

it('asks a round of follow-ups when the extraction finds gaps', function () {
    fakeAi()
        ->respondWith(ItpStage::Extraction, ['sufficient' => false, 'gaps' => ['vision', 'scene'], 'slots' => []])
        ->respondWith(ItpStage::Followups, ['questions' => ['What do you see?', 'Where are you?', 'A third one?']]);

    runGenerateJob($this->generation->id);

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(ItpStatus::AwaitingFollowup)
        ->and($generation->followup_rounds)->toBe(1)
        ->and($generation->openFollowups()->pluck('question_text')->all())->toBe(['What do you see?', 'Where are you?'])
        ->and($generation->extraction['gaps'])->toBe(['vision', 'scene'])
        ->and(fakeAi()->lastInput(ItpStage::Followups))->toContain("## Gaps\n\n- vision\n- scene");
    fakeAi()->assertNotCalled(ItpStage::Reversal);
});

it('re-runs the extraction after the answers and asks a second round, with every answer in later inputs', function () {
    fakeAi()
        ->respondWith(ItpStage::Extraction,
            ['sufficient' => false, 'gaps' => ['vision'], 'slots' => []],
            ['sufficient' => false, 'gaps' => ['scene'], 'slots' => []],
            ['sufficient' => true, 'gaps' => [], 'slots' => []],
        )
        ->respondWith(ItpStage::Followups, ['questions' => ['What do you see?']], ['questions' => ['Where are you standing?']]);

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

    foreach ([ItpStage::Extraction, ItpStage::Reversal, ItpStage::Attunement] as $stage) {
        expect(fakeAi()->lastInput($stage))
            ->toContain("Round 1\n\nQ: What do you see?\nA: The sea at dawn.")
            ->toContain("Round 2\n\nQ: Where are you standing?\nA: On the shore.");
    }
});

it('marks a skipped question as skipped in later inputs', function () {
    fakeAi()
        ->respondWith(ItpStage::Extraction, ['sufficient' => false, 'gaps' => ['vision'], 'slots' => []])
        ->respondWith(ItpStage::Followups, ['questions' => ['What do you see?']]);

    runGenerateJob($this->generation->id);
    answerOpenRound($this->generation, '');
    runGenerateJob($this->generation->id);

    expect(fakeAi()->lastInput(ItpStage::Attunement))->toContain("Q: What do you see?\nA: (skipped)");
});

it('generates after the last follow-up round even when gaps remain', function () {
    config(['itp.generation.max_followup_rounds' => 2]);
    fakeAi()->respondWith(ItpStage::Extraction, ...array_fill(0, 3, ['sufficient' => false, 'gaps' => ['vision'], 'slots' => []]));

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
        ->respondWith(ItpStage::Extraction, ['sufficient' => false, 'gaps' => ['vision'], 'slots' => []])
        ->respondWith(ItpStage::Followups, ['questions' => ['  ']]);

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

        expect($this->statuses)->toBe([ItpStatus::Classifying, ItpStatus::Assessing, ItpStatus::Generating, ItpStatus::AwaitingResonance])
            ->and($this->generation->fresh()->safety_route)->toBe(ItpSafetyRoute::Clear);
    });

    it('holds or refuses the intake by its verdict, generating nothing', function (array $verdict, ItpStatus $status, ItpSafetyRoute $route) {
        fakeAi()->respondWith(ItpStage::Safety, $verdict);

        runGenerateJob($this->generation->id);

        expect($this->generation->fresh()->status)->toBe($status)
            ->and($this->generation->fresh()->safety_route)->toBe($route);
        fakeAi()->assertNotCalled(ItpStage::Extraction);
    })->with([
        'sensitive' => [['route' => 'sensitive', 'categories' => ['grief'], 'confidence' => 0.9], ItpStatus::Deferred, ItpSafetyRoute::Sensitive],
        'block' => [['route' => 'block', 'categories' => ['harm'], 'confidence' => 0.95], ItpStatus::Blocked, ItpSafetyRoute::Block],
        'unsure' => [['route' => 'clear', 'categories' => [], 'confidence' => 0.4], ItpStatus::Deferred, ItpSafetyRoute::Sensitive],
    ]);

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
    $this->generation->update(['status' => ItpStatus::Generating, 'derived_slots' => ['reversal_old' => 'Stored.']]);

    runGenerateJob($this->generation->id);

    fakeAi()->assertNotCalled(ItpStage::Extraction);
    fakeAi()->assertNotCalled(ItpStage::Reversal);
    expect($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance)
        ->and(fakeAi()->lastInput(ItpStage::Attunement))->toContain('reversal_old: Stored.');
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
