<?php

use App\Enums\ItpStage;
use App\Models\PromptVersion;
use App\Models\Staff;

beforeEach(function () {
    $this->withoutVite();
});

it('lists every stage with what it is given, what it returns and its active version', function () {
    $owner = Staff::factory()->owner()->create(['name' => 'Aaron']);
    PromptVersion::factory()->forStage(ItpStage::Attunement)->create();
    PromptVersion::factory()->forStage(ItpStage::Attunement)->active()->create(['created_by_staff_id' => $owner->id]);

    $response = $this->actingAs($owner, 'studio')->get(route('studio.prompts.index'))->assertOk();

    foreach (ItpStage::cases() as $stage) {
        $response->assertSee($stage->label())
            ->assertSee($stage->reads())
            ->assertSee($stage->purpose());
    }

    $response->assertSeeInOrder(['Attunement', 'Active: v2', '2 versions', 'by Aaron'])
        ->assertSee('No active version')
        ->assertSee(route('studio.prompts.show', ItpStage::Attunement->value));
});

it('is owner-only, and links to it only for the owner', function () {
    $editor = Staff::factory()->editor()->create();

    $this->actingAs($editor, 'studio')->get(route('studio.prompts.index'))->assertForbidden();
    $this->actingAs($editor, 'studio')->get(route('studio.prompts.show', ItpStage::Title->value))->assertForbidden();
    $this->actingAs($editor, 'studio')->get(route('studio.dashboard'))->assertDontSee(route('studio.prompts.index'));
});

it('sends guests to the sign-in page', function () {
    $this->get(route('studio.prompts.index'))->assertRedirect(route('studio.login'));
});
