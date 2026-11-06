<?php

use App\Enums\ItpCompanionType;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Jobs\DeriveItpCompanionsJob;
use App\Models\ItpGeneration;
use Database\Seeders\PromptSeeder;
use Itp\Core\Models\Itp;

beforeEach(function () {
    $this->seed(PromptSeeder::class);

    $this->generation = ItpGeneration::factory()->withStatus(ItpStatus::DerivingCompanions)->withAttunement(2)->create();
    $this->generation->attunement->update(['version' => 3]);
});

function runDeriveJob(ItpGeneration $generation): void
{
    app()->call([new DeriveItpCompanionsJob($generation->id), 'handle']);
}

it('derives the companions and title, then writes the finished ITP through the model', function () {
    [$first, $second] = $this->generation->attunement->currentSegments;

    runDeriveJob($this->generation);

    $generation = $this->generation->fresh();
    $itp = Itp::sole();
    expect($itp->user_id)->toBe($generation->user_id)
        ->and($itp->title)->toBe('Coming Home to Rest')
        ->and($itp->description)->toBe('An attunement for letting rest come first.')
        ->and($itp->attunement_text)->toBe("{$first->text}\n\n{$second->text}")
        ->and($itp->breath11_lines)->toBe([['order' => 1, 'text' => 'I breathe in ease.'], ['order' => 2, 'text' => 'I breathe out effort.']])
        ->and($itp->quantum_whys)->toBe(['Why is it so easy for me to rest?', 'Why do I feel so at home?'])
        ->and($itp->sync_seq)->not->toBeNull()
        ->and($generation->status)->toBe(ItpStatus::Complete)
        ->and($generation->itp_id)->toBe($itp->id)
        ->and($generation->title)->toBe('Coming Home to Rest')
        ->and($generation->completed_at)->not->toBeNull()
        ->and($generation->companions()->where('type', ItpCompanionType::Breathe11)->sole()->from_attunement_version)->toBe(3);

    expect(fakeAi()->lastInput(ItpStage::QuantumWhys))->toContain("## Settled attunement\n\n{$first->text}\n\n{$second->text}");
});

it('only pays for what is still missing when retried', function () {
    fakeAi()->respondWith(ItpStage::Title, new RuntimeException('Overloaded'));

    expect(fn () => runDeriveJob($this->generation))->toThrow(RuntimeException::class);
    expect($this->generation->companions()->count())->toBe(2)
        ->and(Itp::count())->toBe(0);

    runDeriveJob($this->generation);

    fakeAi()->assertCalled(ItpStage::Breathe11, 1);
    fakeAi()->assertCalled(ItpStage::QuantumWhys, 1);
    fakeAi()->assertCalled(ItpStage::Title, 2);
    expect(Itp::count())->toBe(1)
        ->and($this->generation->fresh()->status)->toBe(ItpStatus::Complete);
});

it('never writes a second ITP for the same generation', function () {
    runDeriveJob($this->generation);
    $this->generation->fresh()->update(['status' => ItpStatus::DerivingCompanions]);

    runDeriveJob($this->generation);

    expect(Itp::count())->toBe(1);
    fakeAi()->assertCalled(ItpStage::Title, 1);
});

it('marks the generation failed with the error once it gives up', function () {
    fakeAi()->respondWith(ItpStage::Breathe11, new RuntimeException('Overloaded'));

    expect(fn () => dispatch(new DeriveItpCompanionsJob($this->generation->id)))->toThrow(RuntimeException::class);

    expect($this->generation->fresh()->status)->toBe(ItpStatus::Failed)
        ->and($this->generation->fresh()->last_error)->toBe('RuntimeException: Overloaded')
        ->and(Itp::count())->toBe(0);
});
