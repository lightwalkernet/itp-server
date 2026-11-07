<?php

use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Jobs\RegenerateItpSectionJob;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Database\Seeders\PromptSeeder;

beforeEach(function () {
    $this->seed(PromptSeeder::class);

    // Another generation's segments first, so database ids never line up with orders.
    ItpGeneration::factory()->withAttunement(5)->create();

    $this->generation = ItpGeneration::factory()->withStatus(ItpStatus::Regenerating)->withAttunement(4, [1, 3, 3, 8])->create();
    $this->attunement = $this->generation->attunement;
    $this->segments = $this->attunement->currentSegments;
});

/**
 * Flag a section, as FlagSectionAction does.
 */
function flagSection(ItpGeneration $generation, int $part, string $explanation, ?string $requestedChange = null, ?ItpAttunementSegment $pinpointed = null): ItpSegmentFlag
{
    $generation->fresh()->update(['status' => ItpStatus::Regenerating]);

    return ItpSegmentFlag::factory()->forSection($generation, $part)->create([
        'segment_id' => $pinpointed?->id,
        'user_explanation' => $explanation,
        'requested_change' => $requestedChange,
    ]);
}

function runRegenerateJob(ItpSegmentFlag $flag): void
{
    app()->call([new RegenerateItpSectionJob($flag->generation_id, $flag->id), 'handle']);
}

it('writes the section\'s new lines as a proposal, leaving the attunement as it was', function () {
    $flag = flagSection($this->generation, 3, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['segments' => [
        ['id' => 2, 'text' => 'And the tide returns.'],
        ['id' => 1, 'text' => 'You are the shore.'],
        ['id' => 3, 'text' => 'Always, it returns.'],
    ]]);

    runRegenerateJob($flag);

    $proposed = $flag->fresh()->proposedSegments;
    expect($proposed->map->only('order', 'part', 'text', 'version')->all())->toBe([
        ['order' => 1, 'part' => 3, 'text' => 'You are the shore.', 'version' => 2],
        ['order' => 2, 'part' => 3, 'text' => 'And the tide returns.', 'version' => 2],
        ['order' => 3, 'part' => 3, 'text' => 'Always, it returns.', 'version' => 2],
    ])
        ->and($proposed->every->isProposed())->toBeTrue()
        ->and($this->attunement->fresh()->version)->toBe(1)
        ->and($this->attunement->fresh()->currentSegments->modelKeys())->toBe($this->segments->modelKeys())
        ->and($flag->fresh()->status)->toBe(ItpSegmentFlagStatus::Regenerated)
        ->and($flag->fresh()->resulting_segment_ids)->toBe($proposed->modelKeys())
        ->and($flag->fresh()->isAwaitingChoice())->toBeTrue()
        ->and($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance);
});

it('shows the model every line by its order and part, the flagged section, the pinpointed line and the flag', function () {
    $flag = flagSection($this->generation, 3, 'Too generic.', 'Mention the sea.', $this->segments[2]);

    runRegenerateJob($flag);

    $input = fakeAi()->lastInput(ItpStage::Regeneration);
    preg_match('/## Attunement segments\n\n(\[.*?\n\])/s', $input, $listed);
    preg_match('/## Flagged section\n\n(\{.*?\n\})/s', $input, $section);
    preg_match('/## Pinpointed line\n\n(\{.*?\n\})/s', $input, $pinpointed);

    expect(json_decode($listed[1], true))->toBe($this->segments->map(fn (ItpAttunementSegment $segment) => [
        'id' => $segment->order,
        'part' => $segment->part,
        'text' => $segment->text,
    ])->all())
        ->and(json_decode($section[1], true))->toBe([
            'part' => 3,
            'segments' => [
                ['id' => 2, 'text' => $this->segments[1]->text],
                ['id' => 3, 'text' => $this->segments[2]->text],
            ],
        ])
        ->and(json_decode($pinpointed[1], true))->toBe(['id' => 3, 'text' => $this->segments[2]->text])
        ->and($input)
        ->toContain("## Flag\n\n{\n    \"explanation\": \"Too generic.\",\n    \"requested_change\": \"Mention the sea.\"\n}")
        ->toContain('## Derived slots')
        ->not->toContain('## Corrections')
        ->not->toContain("\"id\": {$this->segments[2]->id},");
});

it('leaves the pinpointed line out when the user pointed to none', function () {
    runRegenerateJob(flagSection($this->generation, 8, 'Too abrupt.'));

    expect(fakeAi()->lastInput(ItpStage::Regeneration))->not->toContain('## Pinpointed line');
});

it('treats an attunement from before v2 as one section, part 1', function () {
    $legacy = ItpGeneration::factory()->withStatus(ItpStatus::Regenerating)->withLegacyAttunement(3)->create();
    $flag = flagSection($legacy, 1, 'Not me.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['segments' => [['id' => 1, 'text' => 'A.'], ['id' => 2, 'text' => 'B.']]]);

    runRegenerateJob($flag);

    preg_match('/## Flagged section\n\n(\{.*?\n\})/s', fakeAi()->lastInput(ItpStage::Regeneration), $section);
    expect(json_decode($section[1], true)['segments'])->toHaveCount(3)
        ->and($flag->fresh()->proposedSegments->map->only('order', 'part', 'text')->all())->toBe([
            ['order' => 1, 'part' => 1, 'text' => 'A.'],
            ['order' => 2, 'part' => 1, 'text' => 'B.'],
        ]);
});

it('folds each flag into the corrections, and passes the earlier ones on with their choice', function () {
    $first = flagSection($this->generation, 3, 'Too generic.', 'Mention the sea.', $this->segments[1]);
    runRegenerateJob($first);
    $first->update(['choice' => 'original', 'chosen_at' => now()]);
    $this->generation->fresh()->recordChoice($first->fresh());

    $second = flagSection($this->generation, 8, 'Too abrupt.');
    runRegenerateJob($second);

    $section = $this->segments[1]->text."\n".$this->segments[2]->text;
    expect($this->generation->fresh()->corrections)->toBe([
        ['flag_id' => $first->id, 'part' => 3, 'section' => $section, 'line' => $this->segments[1]->text, 'explanation' => 'Too generic.', 'requested_change' => 'Mention the sea.', 'choice' => 'original'],
        ['flag_id' => $second->id, 'part' => 8, 'section' => $this->segments[3]->text, 'line' => null, 'explanation' => 'Too abrupt.', 'requested_change' => null],
    ]);

    preg_match('/## Corrections\n\n(\[.*?\n\])/s', fakeAi()->lastInput(ItpStage::Regeneration), $corrections);
    expect(json_decode($corrections[1], true))->toBe([
        ['part' => 3, 'section' => $section, 'line' => $this->segments[1]->text, 'explanation' => 'Too generic.', 'requested_change' => 'Mention the sea.', 'choice' => 'original'],
    ]);
});

it('rejects a response with a repeated id or no lines, changing nothing', function (array $segments, string $problem) {
    $flag = flagSection($this->generation, 3, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['segments' => $segments]);

    expect(fn () => runRegenerateJob($flag))->toThrow(UnusableResponse::class, $problem);

    expect($this->attunement->segments()->count())->toBe(4)
        ->and($flag->fresh()->status)->toBe(ItpSegmentFlagStatus::Open);
})->with([
    'the same id twice' => [[['id' => 1, 'text' => 'A.'], ['id' => 1, 'text' => 'B.']], 'segments id 1 appears twice'],
    'nothing' => [[['id' => 1, 'text' => ' ']], 'The [regeneration] stage returned no segments.'],
]);

it('records a correction only once when the job is retried', function () {
    $flag = flagSection($this->generation, 3, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, new RuntimeException('Overloaded'));

    expect(fn () => runRegenerateJob($flag))->toThrow(RuntimeException::class);
    runRegenerateJob($flag);

    expect($this->generation->fresh()->corrections)->toHaveCount(1);
});

it('does nothing for a flag that was already regenerated', function () {
    $flag = flagSection($this->generation, 3, 'Too generic.');
    runRegenerateJob($flag);
    $this->generation->fresh()->update(['status' => ItpStatus::Regenerating]);

    runRegenerateJob($flag);

    fakeAi()->assertCalled(ItpStage::Regeneration, 1);
    expect($flag->fresh()->proposedSegments)->toHaveCount(1);
});

it('marks the generation failed with the error once it gives up', function () {
    $flag = flagSection($this->generation, 3, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, new RuntimeException('Overloaded'));

    expect(fn () => dispatch(new RegenerateItpSectionJob($flag->generation_id, $flag->id)))->toThrow(RuntimeException::class);

    expect($this->generation->fresh()->status)->toBe(ItpStatus::Failed)
        ->and($this->generation->fresh()->last_error)->toBe('RuntimeException: Overloaded')
        ->and($this->attunement->segments()->count())->toBe(4)
        ->and($flag->fresh()->status)->toBe(ItpSegmentFlagStatus::Open);
});

it('keeps the raw output of an unusable response in the error Studio shows', function () {
    $flag = flagSection($this->generation, 3, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['segments' => [['id' => 1, 'text' => ' ']]]);

    expect(fn () => dispatch(new RegenerateItpSectionJob($flag->generation_id, $flag->id)))->toThrow(UnusableResponse::class);

    expect($this->generation->fresh()->status)->toBe(ItpStatus::Failed)
        ->and($this->generation->fresh()->last_error)
        ->toStartWith('App\Ai\Exceptions\UnusableResponse: The [regeneration] stage returned no segments.')
        ->toContain('Raw output:')
        ->toContain('{"segments":[{"id":1,"text":" "}]}');
});
