<?php

use App\Enums\ItpStage;
use App\Models\PromptVersion;
use Database\Seeders\PromptSeeder;

it('gives every stage an active v1 placeholder prompt', function () {
    $this->seed(PromptSeeder::class);

    foreach (ItpStage::cases() as $stage) {
        $prompt = PromptVersion::activeFor($stage);

        expect($prompt->version)->toBe(1)
            ->and($prompt->body)->toStartWith('Placeholder — replace in Studio.')
            ->and($prompt->body)->toContain($stage->purpose());
    }
});

it('leaves a stage that already has a prompt alone', function () {
    $written = PromptVersion::factory()->forStage(ItpStage::Attunement)->create(['body' => 'The real prompt.']);

    $this->seed(PromptSeeder::class);
    $this->seed(PromptSeeder::class);

    expect(PromptVersion::where('stage', ItpStage::Attunement)->sole()->is($written))->toBeTrue()
        ->and(PromptVersion::count())->toBe(count(ItpStage::cases()));
});
