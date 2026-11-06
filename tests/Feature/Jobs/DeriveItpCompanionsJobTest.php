<?php

use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpCompanionType;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Jobs\DeriveItpCompanionsJob;
use App\Models\ItpGeneration;
use App\Models\PromptVersion;
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

    foreach ([ItpStage::Breathe11, ItpStage::QuantumWhys, ItpStage::Title] as $stage) {
        expect(fakeAi()->lastInput($stage))
            ->toStartWith("## Settled attunement\n\n{$first->text}\n\n{$second->text}\n\n## Derived slots\n\n{\n    \"old_spell\": \"I have to earn rest.\"")
            ->not->toContain('## Corrections');
    }
});

it('maps the library\'s id-numbered lines and questions to the stored shapes, in id order', function () {
    fakeAi()
        ->respondWith(ItpStage::Breathe11, ['lines' => [['id' => 2, 'text' => ' Second. '], ['id' => 1, 'text' => 'First.'], ['id' => 3, 'text' => '']]])
        ->respondWith(ItpStage::QuantumWhys, ['questions' => [['id' => 11, 'text' => 'Why is it last?'], ['id' => 1, 'text' => 'Why is it first?']]]);

    runDeriveJob($this->generation);

    expect(Itp::sole())
        ->breath11_lines->toBe([['order' => 1, 'text' => 'First.'], ['order' => 2, 'text' => 'Second.']])
        ->quantum_whys->toBe(['Why is it first?', 'Why is it last?']);
});

it('passes every correction from the flags to Breathe 11, Quantum Whys and the title', function () {
    $this->generation->update(['corrections' => [
        ['flag_id' => 1, 'line' => 'You are a storm.', 'explanation' => 'Storms scare me.', 'requested_change' => 'Something calmer.'],
    ]]);

    runDeriveJob($this->generation);

    foreach ([ItpStage::Breathe11, ItpStage::QuantumWhys, ItpStage::Title] as $stage) {
        expect(fakeAi()->lastInput($stage))->toContain(
            "## Corrections\n\n[\n    {\n        \"line\": \"You are a storm.\",\n        \"explanation\": \"Storms scare me.\",\n        \"requested_change\": \"Something calmer.\"\n    }\n]",
        );
    }
});

it('prepends the method core to Breathe 11 and Quantum Whys, but not to the title', function () {
    $methodCore = PromptVersion::activeFor(ItpStage::MethodCore);

    runDeriveJob($this->generation);

    expect(fakeAi()->lastSystem(ItpStage::Breathe11))->toBe([$methodCore->body, PromptVersion::activeFor(ItpStage::Breathe11)->body])
        ->and(fakeAi()->lastSystem(ItpStage::QuantumWhys))->toBe([$methodCore->body, PromptVersion::activeFor(ItpStage::QuantumWhys)->body])
        ->and(fakeAi()->lastSystem(ItpStage::Title))->toBe([PromptVersion::activeFor(ItpStage::Title)->body]);
});

it('fails outright on a title response in the wrong shape', function () {
    fakeAi()->respondWith(ItpStage::Title, ['title' => 'Built for altitude']);

    expect(fn () => runDeriveJob($this->generation))->toThrow(UnusableResponse::class, '$.description is missing');
    expect(Itp::count())->toBe(0);
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
