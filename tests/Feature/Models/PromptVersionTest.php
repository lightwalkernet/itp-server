<?php

use App\Enums\ItpStage;
use App\Models\PromptVersion;

it('leaves exactly one active version per stage when a version is activated', function () {
    $first = PromptVersion::factory()->forStage(ItpStage::Attunement)->active()->create();
    $second = PromptVersion::factory()->forStage(ItpStage::Attunement)->create();
    $otherStage = PromptVersion::factory()->forStage(ItpStage::Title)->active()->create();

    $second->activate();

    expect($first->fresh()->isActive())->toBeFalse()
        ->and($second->fresh()->isActive())->toBeTrue()
        ->and($otherStage->fresh()->isActive())->toBeTrue()
        ->and(PromptVersion::activeFor(ItpStage::Attunement)->is($second))->toBeTrue()
        ->and(PromptVersion::where('stage', ItpStage::Attunement)->whereNotNull('activated_at')->count())->toBe(1);
});

it('numbers versions per stage', function () {
    PromptVersion::factory()->forStage(ItpStage::Attunement)->count(2)->create();

    expect(PromptVersion::nextVersionFor(ItpStage::Attunement))->toBe(3)
        ->and(PromptVersion::nextVersionFor(ItpStage::Title))->toBe(1);
});

it('has no active version for a stage until one is activated', function () {
    PromptVersion::factory()->forStage(ItpStage::Attunement)->create();

    expect(PromptVersion::activeFor(ItpStage::Attunement))->toBeNull();
});
