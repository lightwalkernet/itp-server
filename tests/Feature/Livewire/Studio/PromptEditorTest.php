<?php

use App\Enums\ItpStage;
use App\Livewire\Studio\PromptEditor;
use App\Models\AiUsage;
use App\Models\ItpGeneration;
use App\Models\PromptVersion;
use App\Models\Staff;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();

    $this->owner = Staff::factory()->owner()->create(['name' => 'Aaron']);
    $this->first = PromptVersion::factory()->forStage(ItpStage::Title)->create(['body' => 'Name it plainly.']);
    $this->second = PromptVersion::factory()->forStage(ItpStage::Title)->active()->create(['body' => 'Name it warmly.']);
});

it('opens with the active version in the editor and the history, newest first', function () {
    $this->actingAs($this->owner, 'studio')
        ->get(route('studio.prompts.show', ItpStage::Title->value))
        ->assertOk()
        ->assertSeeLivewire(PromptEditor::class)
        ->assertSee('Name it warmly.')
        ->assertSeeInOrder(['v2', 'Active', 'v1', 'Make active']);
});

it('saves the editor as the next version, by the signed-in staff member, and makes it active', function () {
    Livewire::actingAs($this->owner, 'studio')
        ->test(PromptEditor::class, ['stage' => ItpStage::Title])
        ->set('body', 'Name it like a lighthouse.')
        ->set('notes', 'Warmer, shorter.')
        ->call('saveVersion')
        ->assertHasNoErrors()
        ->assertSet('notes', '');

    $saved = PromptVersion::activeFor(ItpStage::Title);
    expect($saved->version)->toBe(3)
        ->and($saved->body)->toBe('Name it like a lighthouse.')
        ->and($saved->notes)->toBe('Warmer, shorter.')
        ->and($saved->created_by_staff_id)->toBe($this->owner->id)
        ->and($this->second->fresh()->isActive())->toBeFalse()
        ->and($this->second->fresh()->body)->toBe('Name it warmly.');
});

it('refuses to save an empty prompt or one identical to the active version', function (string $body, string $message) {
    Livewire::actingAs($this->owner, 'studio')
        ->test(PromptEditor::class, ['stage' => ItpStage::Title])
        ->set('body', $body)
        ->call('saveVersion')
        ->assertHasErrors(['body' => $message]);

    expect(PromptVersion::query()->where('stage', ItpStage::Title)->count())->toBe(2)
        ->and(PromptVersion::activeFor(ItpStage::Title)->is($this->second))->toBeTrue();
})->with([
    'empty' => ['', 'The body field is required.'],
    'unchanged' => ['Name it warmly.', 'This is the same as the active version. Change the prompt before saving.'],
]);

it('rolls back by making an older version active and loading it into the editor', function () {
    Livewire::actingAs($this->owner, 'studio')
        ->test(PromptEditor::class, ['stage' => ItpStage::Title])
        ->call('activate', $this->first->id)
        ->assertSet('body', 'Name it plainly.');

    expect(PromptVersion::activeFor(ItpStage::Title)->is($this->first))->toBeTrue()
        ->and(PromptVersion::query()->where('stage', ItpStage::Title)->count())->toBe(2);
});

it('only opens or activates versions of its own stage', function (string $action) {
    $otherStage = PromptVersion::factory()->forStage(ItpStage::Attunement)->create();

    Livewire::actingAs($this->owner, 'studio')
        ->test(PromptEditor::class, ['stage' => ItpStage::Title])
        ->call($action, $otherStage->id)
        ->assertNotFound();

    expect($otherStage->fresh()->isActive())->toBeFalse();
})->with(['view', 'activate']);

it('shows an older version read-only', function () {
    Livewire::actingAs($this->owner, 'studio')
        ->test(PromptEditor::class, ['stage' => ItpStage::Title])
        ->call('view', $this->first->id)
        ->assertSee('Name it plainly.')
        ->assertSet('body', 'Name it warmly.');
});

it('shows how many generations used each version and the average cost of its calls', function () {
    [$one, $two] = ItpGeneration::factory()->count(2)->create();
    AiUsage::factory()->for($one, 'generation')->create(['stage' => ItpStage::Title, 'prompt_version_id' => $this->second->id, 'cost_usd' => 0.0100]);
    AiUsage::factory()->for($one, 'generation')->create(['stage' => ItpStage::Title, 'prompt_version_id' => $this->second->id, 'cost_usd' => 0.0200]);
    AiUsage::factory()->for($two, 'generation')->create(['stage' => ItpStage::Title, 'prompt_version_id' => $this->second->id, 'cost_usd' => 0.0600]);

    $versions = Livewire::actingAs($this->owner, 'studio')
        ->test(PromptEditor::class, ['stage' => ItpStage::Title])
        ->assertSeeInOrder(['v2', '2', '3', '$0.0300', 'v1', '0', '0', '—'])
        ->viewData('versions');

    expect($versions->firstWhere('id', $this->second->id))
        ->generations_count->toBe(2)
        ->calls_count->toBe(3);
});

it('forbids an editor from saving or activating', function (string $action, array $arguments) {
    Livewire::actingAs(Staff::factory()->editor()->create(), 'studio')
        ->test(PromptEditor::class, ['stage' => ItpStage::Title])
        ->set('body', 'Something new.')
        ->call($action, ...$arguments)
        ->assertForbidden();

    expect(PromptVersion::activeFor(ItpStage::Title)->is($this->second))->toBeTrue()
        ->and(PromptVersion::query()->where('stage', ItpStage::Title)->count())->toBe(2);
})->with([
    'save' => ['saveVersion', []],
    'activate' => ['activate', [1]],
]);
