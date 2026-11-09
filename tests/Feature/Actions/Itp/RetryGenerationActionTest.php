<?php

use App\Actions\Itp\RetryGenerationAction;
use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Exceptions\GenerationNotReady;
use App\Jobs\DeriveItpCompanionsJob;
use App\Jobs\GenerateItpJob;
use App\Jobs\RegenerateItpSectionJob;
use App\Models\ItpAttunement;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Database\Factories\ItpGenerationFactory;
use Database\Seeders\PromptSeeder;
use Illuminate\Support\Facades\Queue;

function failedGeneration(array $attributes = []): ItpGeneration
{
    return ItpGeneration::factory()->withStatus(ItpStatus::Failed)->create(['last_error' => 'MissingPromptVersion', ...$attributes]);
}

it('restarts an early failure at the assessment and clears the error', function () {
    Queue::fake();
    $generation = failedGeneration();

    app(RetryGenerationAction::class)->execute($generation);

    expect($generation->fresh()->status)->toBe(ItpStatus::Assessing)
        ->and($generation->fresh()->last_error)->toBeNull();
    Queue::assertPushed(GenerateItpJob::class, fn (GenerateItpJob $job) => $job->generationId === $generation->id);
});

it('carries on to the attunement when the slots are already enough', function () {
    Queue::fake();
    $generation = failedGeneration(['extraction' => ItpGenerationFactory::extraction()]);

    app(RetryGenerationAction::class)->execute($generation);

    expect($generation->fresh()->status)->toBe(ItpStatus::Generating);
    Queue::assertPushed(GenerateItpJob::class);
});

it('asks the follow-ups again when the last extraction was not enough', function () {
    Queue::fake();
    $generation = failedGeneration(['extraction' => ItpGenerationFactory::insufficientExtraction('scene')]);

    app(RetryGenerationAction::class)->execute($generation);

    expect($generation->fresh()->status)->toBe(ItpStatus::Assessing);
});

it('carries on to the companions once the attunement is accepted', function () {
    Queue::fake();
    $generation = failedGeneration();
    ItpAttunement::factory()->for($generation, 'generation')->create(['settled_at' => now()]);

    app(RetryGenerationAction::class)->execute($generation);

    expect($generation->fresh()->status)->toBe(ItpStatus::DerivingCompanions);
    Queue::assertPushed(DeriveItpCompanionsJob::class, fn (DeriveItpCompanionsJob $job) => $job->generationId === $generation->id);
});

it('carries on rewriting the section whose flag is still open', function () {
    Queue::fake();
    $generation = failedGeneration();
    ItpAttunement::factory()->for($generation, 'generation')->create();
    $flag = ItpSegmentFlag::factory()->create(['generation_id' => $generation->id, 'status' => 'open']);

    app(RetryGenerationAction::class)->execute($generation);

    expect($generation->fresh()->status)->toBe(ItpStatus::Regenerating);
    Queue::assertPushed(RegenerateItpSectionJob::class, fn (RegenerateItpSectionJob $job) => $job->flagId === $flag->id);
});

it('refuses a generation that has not failed, and queues nothing', function (ItpStatus $status) {
    Queue::fake();
    $generation = ItpGeneration::factory()->withStatus($status)->create();

    expect(fn () => app(RetryGenerationAction::class)->execute($generation))->toThrow(GenerationNotReady::class);

    expect($generation->fresh()->status)->toBe($status);
    Queue::assertNothingPushed();
})->with([ItpStatus::Generating, ItpStatus::Complete, ItpStatus::Deferred]);

it('really finishes the stage that failed, without repeating what was saved', function () {
    $this->seed(PromptSeeder::class);
    $model = app(StructuredModel::class);
    $generation = failedGeneration([
        'extraction' => ItpGenerationFactory::extraction(),
        'derived_slots' => ItpGenerationFactory::derivedSlots(),
    ]);
    $model->respondWith(ItpStage::Attunement, ['segments' => [['id' => 1, 'part' => 1, 'text' => 'Beloved one.']]]);

    app(RetryGenerationAction::class)->execute($generation);

    expect($generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance);
    $model->assertCalled(ItpStage::Attunement, 1);
    $model->assertNotCalled(ItpStage::Extraction);
    $model->assertNotCalled(ItpStage::Reversal);
});
