<?php

use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Models\AiUsage;
use App\Models\ItpGeneration;
use App\Models\PromptVersion;
use App\Models\Staff;
use Itp\Core\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

it('lists generations newest first with user, status, total cost and prompt versions', function () {
    $attunementPrompt = PromptVersion::factory()->forStage(ItpStage::Attunement)->create(['version' => 4]);
    $older = ItpGeneration::factory()
        ->forUser(User::factory()->create(['email' => 'older@example.com']))
        ->create(['created_at' => now()->subDay()]);
    $newer = ItpGeneration::factory()
        ->forUser(User::factory()->create(['email' => 'newer@example.com']))
        ->withStatus(ItpStatus::AwaitingResonance)
        ->create(['prompt_versions' => [ItpStage::Attunement->value => $attunementPrompt->id]]);
    AiUsage::factory()->for($newer, 'generation')->create(['cost_usd' => 0.125]);
    AiUsage::factory()->for($newer, 'generation')->create(['cost_usd' => 0.25]);

    $this->actingAs(Staff::factory()->editor()->create(), 'studio')
        ->get(route('studio.generations.index'))
        ->assertOk()
        ->assertSeeInOrder([
            'newer@example.com', 'awaiting resonance', '$0.38', 'attunement v4',
            'older@example.com', 'queued', '$0.00',
        ])
        ->assertSee(route('studio.generations.show', $newer))
        ->assertSee(route('studio.generations.show', $older));
});

it('sends guests to the sign-in page', function () {
    $this->get(route('studio.generations.index'))->assertRedirect(route('studio.login'));
});
