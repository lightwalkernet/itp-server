<?php

use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Jobs\DeriveItpCompanionsJob;
use App\Jobs\GenerateItpJob;
use App\Jobs\RegenerateItpSectionJob;
use App\Models\ItpGeneration;
use Database\Seeders\PromptSeeder;
use Illuminate\Pipeline\Pipeline;

/**
 * Run one attempt of the job as a worker does, through its middleware, with the queue
 * interactions faked so the test can see whether it was failed outright or left to retry.
 */
function attemptThroughMiddleware(object $job, ?Closure $handle = null): void
{
    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then($handle ?? fn (object $job) => app()->call([$job, 'handle']));
}

it('fails a generation job on the spot when the model refuses, so it is not retried', function () {
    $this->seed(PromptSeeder::class);
    $generation = ItpGeneration::factory()->create();
    fakeAi()->respondWith(ItpStage::Attunement, UnusableResponse::refused(ItpStage::Attunement));
    $job = (new GenerateItpJob($generation->id))->withFakeQueueInteractions();

    expect(fn () => attemptThroughMiddleware($job))->toThrow(UnusableResponse::class);

    $job->assertFailedWith(UnusableResponse::class);
});

it('leaves a transport or overload error to the job\'s own retries', function () {
    $this->seed(PromptSeeder::class);
    $generation = ItpGeneration::factory()->create();
    fakeAi()->respondWith(ItpStage::Attunement, new RuntimeException('Overloaded'));
    $job = (new GenerateItpJob($generation->id))->withFakeQueueInteractions();

    expect(fn () => attemptThroughMiddleware($job))->toThrow(RuntimeException::class, 'Overloaded');

    $job->assertNotFailed();
});

it('fails every generation job outright on an unusable response', function (object $job) {
    $job->withFakeQueueInteractions();

    expect(fn () => attemptThroughMiddleware($job, fn () => throw UnusableResponse::truncated(ItpStage::Title)))
        ->toThrow(UnusableResponse::class);

    $job->assertFailedWith(UnusableResponse::class);
})->with([
    'generate' => fn () => new GenerateItpJob('01jgeneration'),
    'regenerate section' => fn () => new RegenerateItpSectionJob('01jgeneration', 1),
    'derive companions' => fn () => new DeriveItpCompanionsJob('01jgeneration'),
]);
