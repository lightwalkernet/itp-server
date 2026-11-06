<?php

use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Models\AiUsage;
use App\Models\ItpAttunement;
use App\Models\ItpAttunementSegment;
use App\Models\ItpCompanion;
use App\Models\ItpFollowup;
use App\Models\ItpGeneration;
use App\Models\ItpIntake;
use App\Models\ItpSegmentFlag;
use App\Models\PromptVersion;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Itp\Core\Models\User;

beforeEach(function () {
    $this->withoutVite();

    $user = User::factory()->create(['email' => 'ann@example.com']);
    $this->generation = ItpGeneration::factory()->forUser($user)->withStatus(ItpStatus::Failed)->create([
        'intake_id' => ItpIntake::factory()->create(['user_id' => $user->id, 'q2_challenge' => 'I never rest.']),
        'extraction' => ['sufficient' => true, 'gaps' => [], 'slots' => [['name' => 'core_wound', 'value' => 'never enough']]],
        'derived_slots' => ['central_metaphor' => 'a tide that always returns'],
        'title' => 'The Returning Tide',
        'description' => 'Rest is where you begin.',
        'last_error' => 'RuntimeException: Overloaded',
    ]);

    ItpFollowup::factory()->for($this->generation, 'generation')->answered('My grandmother.')->create(['round' => 1, 'question_text' => 'Who taught you to rest?']);
    ItpFollowup::factory()->for($this->generation, 'generation')->answered('')->create(['round' => 2, 'question_text' => 'Where do you feel it?', 'answer_text' => null]);

    $attunement = ItpAttunement::factory()->for($this->generation, 'generation')->create(['version' => 2]);
    $flagged = ItpAttunementSegment::factory()->for($attunement, 'attunement')->superseded()->create(['order' => 1, 'text' => 'You are a storm.']);
    $replacement = ItpAttunementSegment::factory()->for($attunement, 'attunement')->create(['order' => 1, 'text' => 'You are the tide.', 'version' => 2]);
    ItpAttunementSegment::factory()->for($attunement, 'attunement')->create(['order' => 2, 'text' => 'And you return.']);
    ItpSegmentFlag::factory()->create([
        'segment_id' => $flagged->id,
        'user_explanation' => 'Storms scare me.',
        'requested_change' => 'Something calmer.',
        'status' => ItpSegmentFlagStatus::Regenerated,
        'resulting_segment_id' => $replacement->id,
    ]);

    ItpCompanion::factory()->for($this->generation, 'generation')->create(['content' => [['order' => 1, 'text' => 'Breathe in the tide.']]]);
    ItpCompanion::factory()->for($this->generation, 'generation')->quantumWhys()->create();

    $this->prompt = PromptVersion::factory()->forStage(ItpStage::Regeneration)->create(['version' => 3]);
    AiUsage::factory()->for($this->generation, 'generation')->create([
        'stage' => ItpStage::Regeneration,
        'model' => 'claude-opus-5-5',
        'input_tokens' => 1234,
        'output_tokens' => 567,
        'cache_write_tokens' => 4000,
        'cache_read_tokens' => 8900,
        'cost_usd' => 0.03456,
        'duration_ms' => 2500,
        'prompt_version_id' => $this->prompt->id,
    ]);
});

it('shows everything the generation went through', function () {
    $this->actingAs(Staff::factory()->editor()->create(), 'studio')
        ->get(route('studio.generations.show', $this->generation))
        ->assertOk()
        ->assertSee('ann@example.com')
        ->assertSee('RuntimeException: Overloaded')
        ->assertSee('I never rest.')
        ->assertSeeInOrder(['Round 1', 'Who taught you to rest?', 'My grandmother.', 'Round 2', 'Where do you feel it?', '(skipped)'])
        ->assertSee('never enough')
        ->assertSee('a tide that always returns')
        ->assertSeeInOrder(['You are a storm.', 'Storms scare me.', 'Something calmer.', 'You are the tide.', 'And you return.'])
        ->assertSee('Superseded')
        ->assertSee('Breathe in the tide.')
        ->assertSee('Why is it so easy for me to rest?')
        ->assertSee('The Returning Tide')
        ->assertSee('Rest is where you begin.')
        ->assertSeeInOrder(['regeneration', 'anthropic · claude-opus-5-5', '1,234', '567', '4,000', '8,900', '$0.0346', '2.5s', 'v3']);
});

it('decrypts the intake answers stored encrypted at rest', function () {
    expect(DB::table('itp_intakes')->value('q2_challenge'))->not->toContain('I never rest.');

    $this->actingAs(Staff::factory()->editor()->create(), 'studio')
        ->get(route('studio.generations.show', $this->generation))
        ->assertSee('I never rest.');
});

it('links each call to its prompt version for the owner only', function (string $role, bool $seesLink) {
    $link = route('studio.prompts.show', ['stage' => ItpStage::Regeneration->value, 'version' => $this->prompt->id]);

    $response = $this->actingAs(Staff::factory()->{$role}()->create(), 'studio')
        ->get(route('studio.generations.show', $this->generation));

    $seesLink ? $response->assertSee($link) : $response->assertDontSee($link);
})->with([
    'owner' => ['owner', true],
    'editor' => ['editor', false],
]);

it('sends guests to the sign-in page', function () {
    $this->get(route('studio.generations.show', $this->generation))->assertRedirect(route('studio.login'));
});
