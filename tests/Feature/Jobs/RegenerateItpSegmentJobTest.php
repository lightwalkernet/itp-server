<?php

use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Jobs\RegenerateItpSegmentJob;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Database\Seeders\PromptSeeder;

beforeEach(function () {
    $this->seed(PromptSeeder::class);

    // Another generation's segments first, so database ids never line up with orders.
    ItpGeneration::factory()->withAttunement(5)->create();

    $this->generation = ItpGeneration::factory()->withStatus(ItpStatus::Regenerating)->withAttunement(4)->create();
    $this->attunement = $this->generation->attunement;
    $this->segments = $this->attunement->currentSegments;
    $this->flagged = $this->segments[1];
});

/**
 * Flag a segment, as FlagSegmentAction does.
 */
function flagSegment(ItpGeneration $generation, ItpAttunementSegment $segment, string $explanation, ?string $requestedChange = null): ItpSegmentFlag
{
    $generation->fresh()->update(['status' => ItpStatus::Regenerating]);

    return ItpSegmentFlag::factory()->create([
        'segment_id' => $segment->id,
        'user_explanation' => $explanation,
        'requested_change' => $requestedChange,
    ]);
}

function runRegenerateJob(ItpSegmentFlag $flag): void
{
    app()->call([new RegenerateItpSegmentJob($flag->generation_id, $flag->id), 'handle']);
}

it('replaces the flagged segment in place and supersedes the old one', function () {
    $flag = flagSegment($this->generation, $this->flagged, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['segments' => [['id' => 2, 'text' => 'Here, the tide returns.']]]);

    runRegenerateJob($flag);

    $replacement = ItpAttunementSegment::latest('id')->first();
    expect($replacement->order)->toBe(2)
        ->and($replacement->text)->toBe('Here, the tide returns.')
        ->and($replacement->version)->toBe(2)
        ->and($this->flagged->fresh()->superseded_at)->not->toBeNull()
        ->and($this->attunement->fresh()->version)->toBe(2)
        ->and($this->attunement->fresh()->currentSegments->pluck('id')->all())->toBe([
            $this->segments[0]->id,
            $replacement->id,
            $this->segments[2]->id,
            $this->segments[3]->id,
        ])
        ->and($flag->fresh()->status)->toBe(ItpSegmentFlagStatus::Regenerated)
        ->and($flag->fresh()->resulting_segment_id)->toBe($replacement->id)
        ->and($flag->fresh()->resulting_segment_ids)->toBe([$replacement->id])
        ->and($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance);
});

it('shows the model every segment by its order, never its database id, with the flagged one marked', function () {
    $flag = flagSegment($this->generation, $this->flagged, 'Too generic.', 'Mention the sea.');

    runRegenerateJob($flag);

    $expectedSegments = $this->segments->map(fn (ItpAttunementSegment $segment) => [
        'id' => $segment->order,
        'text' => $segment->text,
        ...($segment->is($this->flagged) ? ['flagged' => true] : []),
    ])->all();

    $input = fakeAi()->lastInput(ItpStage::Regeneration);
    preg_match('/## Attunement segments\n\n(\[.*?\n\])/s', $input, $listed);

    expect(json_decode($listed[1], true))->toBe($expectedSegments)
        ->and($input)
        ->toContain("## Flagged segment\n\n{\n    \"id\": 2,")
        ->toContain("## Flag\n\n{\n    \"explanation\": \"Too generic.\",\n    \"requested_change\": \"Mention the sea.\"\n}")
        ->toContain('## Derived slots')
        ->not->toContain("\"id\": {$this->flagged->id},");
});

it('replaces the flagged segment and one adjacent segment when the model needs both', function (int $adjacentOrder) {
    $flag = flagSegment($this->generation, $this->flagged, 'The image jars.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['segments' => [
        ['id' => $adjacentOrder, 'text' => 'A softer bridge.'],
        ['id' => 2, 'text' => 'Here, the tide returns.'],
    ]]);

    runRegenerateJob($flag);

    $current = $this->attunement->fresh()->currentSegments;
    $adjacent = $current->firstWhere('order', $adjacentOrder);
    $replacement = $current->firstWhere('order', 2);

    expect($current->pluck('order')->all())->toBe([1, 2, 3, 4])
        ->and($replacement->text)->toBe('Here, the tide returns.')
        ->and($adjacent->text)->toBe('A softer bridge.')
        ->and($adjacent->version)->toBe(2)
        ->and($this->segments->firstWhere('order', $adjacentOrder)->fresh()->isSuperseded())->toBeTrue()
        ->and($this->attunement->fresh()->version)->toBe(2)
        ->and($flag->fresh()->resulting_segment_id)->toBe($replacement->id)
        ->and($flag->fresh()->resulting_segment_ids)->toBe(collect([$adjacent, $replacement])->sortBy('order')->pluck('id')->values()->all());
})->with(['the one before' => 1, 'the one after' => 3]);

it('rejects a response that does not stick to the flagged segment and one neighbour, changing nothing', function (array $segments, string $problem) {
    $flag = flagSegment($this->generation, $this->flagged, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['segments' => $segments]);

    expect(fn () => runRegenerateJob($flag))->toThrow(UnusableResponse::class, $problem);

    expect($this->attunement->fresh()->version)->toBe(1)
        ->and($this->attunement->segments()->count())->toBe(4)
        ->and($flag->fresh()->status)->toBe(ItpSegmentFlagStatus::Open);
})->with([
    'a segment two away' => [[['id' => 2, 'text' => 'New.'], ['id' => 4, 'text' => 'Also new.']], 'segment id 4 is neither the flagged segment (2) nor next to it'],
    'only a neighbour' => [[['id' => 3, 'text' => 'New.']], 'it did not replace the flagged segment (id 2)'],
    'three segments' => [[['id' => 1, 'text' => 'A.'], ['id' => 2, 'text' => 'B.'], ['id' => 3, 'text' => 'C.']], 'it replaced more than the flagged segment and one adjacent segment'],
    'the same id twice' => [[['id' => 2, 'text' => 'A.'], ['id' => 2, 'text' => 'B.']], 'segments id 2 appears twice'],
    'a database id' => [[['id' => 999, 'text' => 'A.']], 'it did not replace the flagged segment (id 2)'],
    'nothing' => [[], 'The [regeneration] stage returned no segments.'],
]);

it('rejects a neighbour id past the end of the attunement', function () {
    $flag = flagSegment($this->generation, $this->segments[3], 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['segments' => [['id' => 4, 'text' => 'New.'], ['id' => 5, 'text' => 'Past the end.']]]);

    expect(fn () => runRegenerateJob($flag))->toThrow(UnusableResponse::class, 'segment id 5 is neither the flagged segment (4) nor next to it');
});

it('folds each flag into the corrections before regenerating, and passes every correction on', function () {
    $first = flagSegment($this->generation, $this->flagged, 'Too generic.', 'Mention the sea.');
    runRegenerateJob($first);

    $second = flagSegment($this->generation, $first->fresh()->resultingSegment, 'Still not me.');
    runRegenerateJob($second);

    expect($this->generation->fresh()->corrections)->toBe([
        ['flag_id' => $first->id, 'line' => $this->flagged->text, 'explanation' => 'Too generic.', 'requested_change' => 'Mention the sea.'],
        ['flag_id' => $second->id, 'line' => 'Here, the tide always returns to you.', 'explanation' => 'Still not me.', 'requested_change' => null],
    ]);

    $corrections = fakeAi()->calls(ItpStage::Regeneration)[0]['user_content'];
    expect($corrections)->toContain("## Corrections\n\n[\n    {\n        \"line\": ".json_encode($this->flagged->text).",\n        \"explanation\": \"Too generic.\"")
        ->and(fakeAi()->lastInput(ItpStage::Regeneration))->toContain('"explanation": "Still not me."')
        ->and(fakeAi()->lastInput(ItpStage::Regeneration))->not->toContain('flag_id');
});

it('keeps every flag and the text it was about across repeated regenerations', function () {
    $first = flagSegment($this->generation, $this->flagged, 'Too generic.');
    runRegenerateJob($first);

    $second = flagSegment($this->generation, $first->fresh()->resultingSegment, 'Still not me.');
    runRegenerateJob($second);

    expect(ItpSegmentFlag::count())->toBe(2)
        ->and($first->fresh()->segment->text)->toBe($this->flagged->text)
        ->and($first->fresh()->segment->isSuperseded())->toBeTrue()
        ->and($second->fresh()->segment->isSuperseded())->toBeTrue()
        ->and($second->fresh()->resultingSegment->isSuperseded())->toBeFalse()
        ->and($this->attunement->fresh()->version)->toBe(3)
        ->and($this->attunement->segments()->count())->toBe(6);
});

it('records a correction only once when the job is retried', function () {
    $flag = flagSegment($this->generation, $this->flagged, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, new RuntimeException('Overloaded'));

    expect(fn () => runRegenerateJob($flag))->toThrow(RuntimeException::class);
    runRegenerateJob($flag);

    expect($this->generation->fresh()->corrections)->toHaveCount(1);
});

it('does nothing for a flag that was already regenerated', function () {
    $flag = flagSegment($this->generation, $this->flagged, 'Too generic.');
    runRegenerateJob($flag);
    $this->generation->fresh()->update(['status' => ItpStatus::Regenerating]);

    runRegenerateJob($flag);

    fakeAi()->assertCalled(ItpStage::Regeneration, 1);
    expect($this->attunement->fresh()->version)->toBe(2);
});

it('marks the generation failed with the error once it gives up', function () {
    $flag = flagSegment($this->generation, $this->flagged, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, new RuntimeException('Overloaded'));

    expect(fn () => dispatch(new RegenerateItpSegmentJob($flag->generation_id, $flag->id)))->toThrow(RuntimeException::class);

    expect($this->generation->fresh()->status)->toBe(ItpStatus::Failed)
        ->and($this->generation->fresh()->last_error)->toBe('RuntimeException: Overloaded')
        ->and($this->flagged->fresh()->isSuperseded())->toBeFalse()
        ->and($flag->fresh()->status)->toBe(ItpSegmentFlagStatus::Open);
});

it('keeps the raw output of an unusable response in the error Studio shows', function () {
    $flag = flagSegment($this->generation, $this->flagged, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['segments' => [['id' => 4, 'text' => 'Far away.']]]);

    expect(fn () => dispatch(new RegenerateItpSegmentJob($flag->generation_id, $flag->id)))->toThrow(UnusableResponse::class);

    expect($this->generation->fresh()->status)->toBe(ItpStatus::Failed)
        ->and($this->generation->fresh()->last_error)
        ->toStartWith('App\Ai\Exceptions\UnusableResponse: The [regeneration] stage returned an unusable response: it did not replace the flagged segment (id 2).')
        ->toContain('Raw output:')
        ->toContain('{"segments":[{"id":4,"text":"Far away."}]}');
});
