<?php

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

    $this->generation = ItpGeneration::factory()->withStatus(ItpStatus::Regenerating)->withAttunement(3)->create();
    $this->attunement = $this->generation->attunement;
    $this->flagged = $this->attunement->currentSegments[1];
});

/**
 * Flag a segment, as FlagSegmentAction does.
 */
function flagSegment(ItpGeneration $generation, ItpAttunementSegment $segment, string $explanation): ItpSegmentFlag
{
    $generation->fresh()->update(['status' => ItpStatus::Regenerating]);

    return ItpSegmentFlag::factory()->create([
        'segment_id' => $segment->id,
        'user_explanation' => $explanation,
        'requested_change' => null,
    ]);
}

function runRegenerateJob(ItpSegmentFlag $flag): void
{
    app()->call([new RegenerateItpSegmentJob($flag->generation_id, $flag->id), 'handle']);
}

it('replaces the flagged segment in place and supersedes the old one', function () {
    $flag = flagSegment($this->generation, $this->flagged, 'Too generic.');
    fakeAi()->respondWith(ItpStage::Regeneration, ['text' => 'Here, the tide returns.']);

    runRegenerateJob($flag);

    $replacement = ItpAttunementSegment::latest('id')->first();
    expect($replacement->order)->toBe(2)
        ->and($replacement->text)->toBe('Here, the tide returns.')
        ->and($replacement->version)->toBe(2)
        ->and($this->flagged->fresh()->superseded_at)->not->toBeNull()
        ->and($this->attunement->fresh()->version)->toBe(2)
        ->and($this->attunement->fresh()->currentSegments->pluck('id')->all())->toBe([
            $this->attunement->currentSegments[0]->id,
            $replacement->id,
            $this->attunement->currentSegments[2]->id,
        ])
        ->and($flag->fresh()->status)->toBe(ItpSegmentFlagStatus::Regenerated)
        ->and($flag->fresh()->resulting_segment_id)->toBe($replacement->id)
        ->and($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance);

    expect(fakeAi()->lastInput(ItpStage::Regeneration))
        ->toContain("## Flagged segment\n\n[2] {$this->flagged->text}")
        ->toContain('Why it does not resonate: Too generic.');
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
        ->and($this->attunement->segments()->count())->toBe(5)
        ->and(fakeAi()->lastInput(ItpStage::Regeneration))->toContain("## Earlier feedback\n\nFlagged: {$this->flagged->text}\n\nWhy: Too generic.");
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
