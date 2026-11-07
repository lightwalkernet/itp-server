<?php

use App\Ai\UsageCost;
use App\Enums\ItpStage;
use App\Models\AiUsage;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use App\Models\PromptVersion;
use Database\Factories\ItpGenerationFactory;
use Database\Seeders\PromptSeeder;
use Itp\Core\Models\Itp;
use Itp\Core\Models\User;
use Tests\Fakes\FakeStructuredModel;

/*
 * The whole flow through the API, with the jobs running synchronously (QUEUE_CONNECTION=sync)
 * and the fake AI gateway answering.
 */
beforeEach(function () {
    $this->seed(PromptSeeder::class);

    $this->withHeader('X-App-Version', '1.0.0');
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone')->plainTextToken;
});

it('takes an intake through follow-ups, a section flag, a choice and acceptance to a finished ITP the phone can pull', function () {
    fakeAi()
        ->respondWith(ItpStage::Extraction, ItpGenerationFactory::insufficientExtraction('scene'), ItpGenerationFactory::extraction(['scene' => 'The sea at dawn.']))
        ->respondWith(ItpStage::Followups, ['questions' => [['gap_slot' => 'scene', 'question_text' => 'What do you see?']]])
        ->respondWith(ItpStage::Regeneration, ['segments' => [['id' => 1, 'text' => 'You are already the shore.'], ['id' => 2, 'text' => 'Here, the tide returns to you.']]]);

    $created = $this->withToken($this->token)->postJson(route('api.generations.store'), [
        'display_name' => 'Ann',
        'address_style' => 'beloved_name',
        'q1_dedication' => 'To my daughter.',
        'q2_challenge' => 'I never rest.',
        'q3_old_story' => 'I have to earn my place.',
        'q4_vision' => 'Standing on stage, at ease.',
        'q5_soulful' => 'The sea at dawn.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.generation.status', 'awaiting_followup')
        ->assertJsonPath('data.generation.followups.0.question_text', 'What do you see?')
        ->assertJsonMissingPath('data.generation.followups.0.gap_slot');

    $generationId = $created->json('data.generation.id');

    $answered = $this->withToken($this->token)->postJson(route('api.generations.followups.store', $generationId), [
        'answers' => [['followup_id' => $created->json('data.generation.followups.0.id'), 'answer_text' => 'The sea at dawn.']],
    ])
        ->assertAccepted()
        ->assertJsonPath('data.generation.status', 'awaiting_resonance')
        ->assertJsonPath('data.generation.attunement.version', 1)
        ->assertJsonPath('data.generation.attunement.sections.*.part', [1, 3, 8]);

    $pinpointed = $answered->json('data.generation.attunement.sections.1.segments.0');

    $flagged = $this->withToken($this->token)->postJson(route('api.generations.sections.flags.store', [$generationId, 3]), [
        'user_explanation' => 'Too generic.',
        'requested_change' => 'Mention the sea.',
        'segment_id' => $pinpointed['id'],
    ])
        ->assertAccepted()
        ->assertJsonPath('data.generation.status', 'awaiting_resonance')
        ->assertJsonPath('data.generation.attunement.version', 1)
        ->assertJsonPath('data.generation.attunement.sections.1.segments.0.text', 'You are already home.')
        ->assertJsonPath('data.generation.attunement.sections.1.pinpointed_segment_id', $pinpointed['id'])
        ->assertJsonPath('data.generation.attunement.sections.1.proposal.segments.*.text', ['You are already the shore.', 'Here, the tide returns to you.']);

    $this->withToken($this->token)->postJson(route('api.generations.accept', $generationId))->assertUnprocessable();

    $this->withToken($this->token)->postJson(route('api.generations.sections.choice.store', [$generationId, 3]), ['keep' => 'new'])
        ->assertOk()
        ->assertJsonPath('data.generation.status', 'awaiting_resonance')
        ->assertJsonPath('data.generation.attunement.version', 2)
        ->assertJsonPath('data.generation.attunement.sections.1.proposal', null)
        ->assertJsonPath('data.generation.attunement.sections.1.segments.*.order', [2, 3])
        ->assertJsonPath('data.generation.attunement.sections.1.segments.*.id', $flagged->json('data.generation.attunement.sections.1.proposal.segments.*.id'))
        ->assertJsonPath('data.generation.attunement.sections.2.segments.0.order', 4);

    $completed = $this->withToken($this->token)->postJson(route('api.generations.accept', $generationId))
        ->assertAccepted()
        ->assertJsonPath('data.generation.status', 'complete')
        ->assertJsonPath('data.generation.title', 'Coming Home to Rest')
        ->assertJsonPath('data.generation.companions.quantum_whys.0', 'Why is it so easy for me to rest?');

    $itp = Itp::sole();
    expect($completed->json('data.generation.itp_id'))->toBe($itp->id)
        ->and($itp->user_id)->toBe($this->user->id)
        ->and($itp->attunement_text)->toBe("Beloved one, breathe in.\n\nYou are already the shore.\n\nHere, the tide returns to you.\n\nRest is where you begin.")
        ->and($itp->sync_seq)->toBeGreaterThan(0);

    $generation = ItpGeneration::sole();
    $methodCore = PromptVersion::activeFor(ItpStage::MethodCore);
    $stagesRun = [ItpStage::Extraction, ItpStage::Followups, ItpStage::Reversal, ItpStage::Attunement, ItpStage::Regeneration, ItpStage::Breathe11, ItpStage::QuantumWhys, ItpStage::Title];
    expect($generation->prompt_versions)->toEqualCanonicalizing(
        collect([...$stagesRun, ItpStage::MethodCore])->mapWithKeys(fn (ItpStage $stage) => [$stage->value => PromptVersion::activeFor($stage)->id])->all(),
    )
        ->and($generation->corrections)->toBe([[
            'flag_id' => ItpSegmentFlag::sole()->id,
            'part' => 3,
            'section' => 'You are already home.',
            'line' => 'You are already home.',
            'explanation' => 'Too generic.',
            'requested_change' => 'Mention the sea.',
            'choice' => 'new',
        ]])
        ->and($generation->followups()->sole()->gap_slot)->toBe('scene');

    $usages = AiUsage::where('generation_id', $generation->id)->get();
    expect($usages)->toHaveCount(9)
        ->and($usages->pluck('stage')->unique()->values()->all())->toEqualCanonicalizing($stagesRun);

    $usages->each(fn (AiUsage $usage) => expect($usage->method_core_version_id)->toBe($usage->stage->usesMethodCore() ? $methodCore->id : null));

    foreach ($stagesRun as $stage) {
        expect(fakeAi()->lastSystem($stage))->toBe($stage->usesMethodCore()
            ? [$methodCore->body, PromptVersion::activeFor($stage)->body]
            : [PromptVersion::activeFor($stage)->body]);
    }

    foreach ([ItpStage::Breathe11, ItpStage::QuantumWhys, ItpStage::Title] as $stage) {
        expect(fakeAi()->lastInput($stage))->toContain('"requested_change": "Mention the sea."')->toContain('"choice": "new"');
    }

    $usages->each(fn (AiUsage $usage) => expect((float) $usage->cost_usd)
        ->toBe(app(UsageCost::class)->calculate($usage->model, FakeStructuredModel::tokens()))
        ->toBeGreaterThan(0.0));

    $this->withToken($this->token)->getJson(route('api.sync.pull', ['type' => 'itps']))
        ->assertOk()
        ->assertJsonPath('data.records.0.id', $itp->id)
        ->assertJsonPath('data.records.0.title', 'Coming Home to Rest')
        ->assertJsonCount(1, 'data.records');
});

it('pulls only the signed-in user\'s ITPs', function () {
    $mine = Itp::factory()->forUser($this->user)->create();
    Itp::factory()->create();

    $this->withToken($this->token)->getJson(route('api.sync.pull', ['type' => 'itps']))
        ->assertOk()
        ->assertJsonCount(1, 'data.records')
        ->assertJsonPath('data.records.0.id', $mine->id);
});

it('returns 401 for a sync pull without a token', function () {
    $this->getJson(route('api.sync.pull', ['type' => 'itps']))->assertUnauthorized();
});
