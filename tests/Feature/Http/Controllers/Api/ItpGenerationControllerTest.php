<?php

use App\Enums\ItpSectionChoice;
use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStatus;
use App\Jobs\DeriveItpCompanionsJob;
use App\Jobs\GenerateItpJob;
use App\Jobs\RegenerateItpSectionJob;
use App\Models\ItpAttunementSegment;
use App\Models\ItpCompanion;
use App\Models\ItpFollowup;
use App\Models\ItpGeneration;
use App\Models\ItpIntake;
use App\Models\ItpSegmentFlag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Itp\Core\Models\User;

beforeEach(function () {
    Queue::fake();

    $this->withHeader('X-App-Version', '1.0.0');
    $this->user = User::factory()->create();
});

/**
 * Give a section a rewrite waiting for the user's choice, as RegenerateItpSectionJob leaves it.
 *
 * @param  list<string>  $lines
 */
function sectionAwaitingChoice(ItpGeneration $generation, int $part, array $lines, ?ItpAttunementSegment $pinpointed = null): ItpSegmentFlag
{
    $flag = ItpSegmentFlag::factory()->forSection($generation, $part)->create([
        'segment_id' => $pinpointed?->id,
        'status' => ItpSegmentFlagStatus::Regenerated,
    ]);

    $proposed = collect($lines)->map(fn (string $text, int $index) => ItpAttunementSegment::factory()->for($generation->attunement, 'attunement')->create([
        'order' => $index + 1,
        'part' => $part,
        'text' => $text,
        'version' => 2,
        'proposed_by_flag_id' => $flag->id,
    ]));

    $flag->update(['resulting_segment_ids' => $proposed->pluck('id')->all()]);
    $generation->recordCorrection($flag);

    return $flag;
}

/**
 * A valid intake, as the app sends it.
 *
 * @return array<string, string>
 */
function intakePayload(): array
{
    return [
        'display_name' => 'Ann',
        'address_style' => 'beloved_name',
        'q1_dedication' => 'To my daughter.',
        'q2_challenge' => 'I never rest.',
        'q3_old_story' => 'I have to earn my place.',
        'q4_vision' => 'Standing on stage, at ease.',
        'q5_soulful' => 'The sea at dawn.',
    ];
}

describe('index', function () {
    it('returns only the signed-in user\'s generations, newest first, as summaries', function () {
        $older = ItpGeneration::factory()->forUser($this->user)->create(['created_at' => now()->subDay()]);
        $newer = ItpGeneration::factory()->forUser($this->user)->awaitingResonance()->create();
        ItpGeneration::factory()->create();

        $response = $this->actingAs($this->user, 'sanctum')->getJson(route('api.generations.index'))->assertOk();

        expect($response->json('data.generations.*.id'))->toBe([$newer->id, $older->id])
            ->and(array_keys($response->json('data.generations.0')))->toBe(['id', 'status', 'created_at', 'updated_at', 'itp_id'])
            ->and($response->json('data.generations.0.status'))->toBe('awaiting_resonance');
    });

    it('returns 401 without a token', function () {
        $this->getJson(route('api.generations.index'))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.', 'data' => [], 'errors' => []]);
    });
});

describe('store', function () {
    it('stores the intake and returns 201 with the queued generation', function () {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.store'), intakePayload())
            ->assertCreated()
            ->assertJsonPath('message', 'Generation queued.')
            ->assertJsonPath('errors', []);

        $generation = ItpGeneration::sole();
        expect($response->json('data.generation'))->toBe([
            'id' => $generation->id,
            'status' => 'queued',
            'created_at' => $generation->created_at->toJSON(),
            'updated_at' => $generation->updated_at->toJSON(),
            'itp_id' => null,
        ])
            ->and($generation->user_id)->toBe($this->user->id)
            ->and($generation->intake->user_id)->toBe($this->user->id)
            ->and($generation->intake->q2_challenge)->toBe('I never rest.');

        Queue::assertPushedOn('generation', GenerateItpJob::class, fn (GenerateItpJob $job) => $job->generationId === $generation->id);
    });

    it('encrypts the intake answers at rest', function () {
        $this->actingAs($this->user, 'sanctum')->postJson(route('api.generations.store'), intakePayload())->assertCreated();

        $stored = DB::table('itp_intakes')->sole();

        expect($stored->q2_challenge)->not->toContain('I never rest')
            ->and($stored->display_name)->not->toBe('Ann')
            ->and(ItpIntake::sole()->display_name)->toBe('Ann');
    });

    it('returns 422 with every missing required field', function () {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(route('api.generations.store'), [])->assertUnprocessable();

        expect(array_keys($response->json('errors')))->toEqualCanonicalizing([
            'display_name', 'address_style', 'q1_dedication', 'q2_challenge', 'q3_old_story', 'q4_vision', 'q5_soulful',
        ])
            ->and($response->json('errors.q2_challenge'))->toBe(['The q2 challenge field is required.']);

        expect(ItpGeneration::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('returns 422 for an unknown address style or an overlong display name', function (array $override, string $field) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.store'), [...intakePayload(), ...$override])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$field]);
    })->with([
        'address style' => [['address_style' => 'dear_friend'], 'address_style'],
        'display name' => [['display_name' => str_repeat('a', 256)], 'display_name'],
    ]);

    it('returns 429 once a user has started the hourly limit of generations', function () {
        config(['itp.generation.max_per_hour' => 2]);

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->user, 'sanctum')->postJson(route('api.generations.store'), intakePayload())->assertCreated();
        }

        $this->actingAs($this->user, 'sanctum')->postJson(route('api.generations.store'), intakePayload())
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Too Many Attempts.', 'data' => [], 'errors' => []]);

        expect(ItpGeneration::count())->toBe(2);

        $this->actingAs(User::factory()->create(), 'sanctum')->postJson(route('api.generations.store'), intakePayload())->assertCreated();

        $this->travel(61)->minutes();

        $this->actingAs($this->user, 'sanctum')->postJson(route('api.generations.store'), intakePayload())->assertCreated();
    });
});

describe('show', function () {
    it('returns only the keys that apply to the status', function (ItpStatus $status, array $extraKeys) {
        $generation = ItpGeneration::factory()->forUser($this->user)->withStatus($status)->withAttunement()->create();

        $response = $this->actingAs($this->user, 'sanctum')->getJson(route('api.generations.show', $generation))->assertOk();

        expect(array_keys($response->json('data.generation')))
            ->toBe(['id', 'status', 'created_at', 'updated_at', 'itp_id', ...$extraKeys]);
    })->with([
        'queued' => [ItpStatus::Queued, []],
        'assessing' => [ItpStatus::Assessing, []],
        'awaiting follow-up' => [ItpStatus::AwaitingFollowup, ['followups']],
        'awaiting resonance' => [ItpStatus::AwaitingResonance, ['attunement']],
        'regenerating' => [ItpStatus::Regenerating, ['attunement']],
        'deriving companions' => [ItpStatus::DerivingCompanions, ['attunement']],
        'complete' => [ItpStatus::Complete, ['attunement', 'companions', 'title', 'description']],
        'deferred' => [ItpStatus::Deferred, ['message']],
        'blocked' => [ItpStatus::Blocked, ['message']],
        'failed' => [ItpStatus::Failed, ['message']],
    ]);

    it('lists only the open round of follow-up questions', function () {
        $generation = ItpGeneration::factory()->forUser($this->user)->withStatus(ItpStatus::AwaitingFollowup)->create(['followup_rounds' => 2]);
        ItpFollowup::factory()->for($generation, 'generation')->answered()->create(['round' => 1]);
        $open = ItpFollowup::factory()->for($generation, 'generation')->create(['round' => 2, 'question_text' => 'What does ease feel like?']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.generations.show', $generation))
            ->assertOk()
            ->assertJsonPath('data.generation.followups', [
                ['id' => $open->id, 'round' => 2, 'question_text' => 'What does ease feel like?'],
            ]);
    });

    it('shows the current lines in sections, in part order, and marks the one being rewritten with its pinpointed line', function () {
        $generation = ItpGeneration::factory()->forUser($this->user)->withStatus(ItpStatus::Regenerating)->withAttunement(3, [1, 3, 3])->create();
        $attunement = $generation->attunement;
        [$first, $second, $third] = $attunement->currentSegments;
        ItpAttunementSegment::factory()->for($attunement, 'attunement')->superseded()->create(['order' => 1, 'part' => 1, 'text' => 'An older first line.']);
        ItpSegmentFlag::factory()->create(['segment_id' => $third->id]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.generations.show', $generation))
            ->assertOk()
            ->assertJsonMissingPath('data.generation.attunement.segments')
            ->assertJsonPath('data.generation.attunement', [
                'id' => $attunement->id,
                'version' => 1,
                'sections' => [
                    [
                        'part' => 1,
                        'segments' => [['id' => $first->id, 'order' => 1, 'text' => $first->text]],
                        'regenerating' => false,
                        'proposal' => null,
                        'pinpointed_segment_id' => null,
                    ],
                    [
                        'part' => 3,
                        'segments' => [
                            ['id' => $second->id, 'order' => 2, 'text' => $second->text],
                            ['id' => $third->id, 'order' => 3, 'text' => $third->text],
                        ],
                        'regenerating' => true,
                        'proposal' => null,
                        'pinpointed_segment_id' => $third->id,
                    ],
                ],
            ]);
    });

    it('shows a rewrite waiting for a choice as the section\'s proposal, beside its current lines', function () {
        $generation = ItpGeneration::factory()->forUser($this->user)->awaitingResonance(2)->create();
        $second = $generation->attunement->currentSegments[1];
        $flag = sectionAwaitingChoice($generation, 2, ['The new line.'], $second);
        $proposed = $flag->proposedSegments->sole();

        $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.generations.show', $generation))
            ->assertOk()
            ->assertJsonPath('data.generation.attunement.sections.0.proposal', null)
            ->assertJsonPath('data.generation.attunement.sections.1', [
                'part' => 2,
                'segments' => [['id' => $second->id, 'order' => 2, 'text' => $second->text]],
                'regenerating' => false,
                'proposal' => ['flag_id' => $flag->id, 'segments' => [['id' => $proposed->id, 'order' => 1, 'text' => 'The new line.']]],
                'pinpointed_segment_id' => $second->id,
            ]);
    });

    it('shows an attunement from before v2 as one section, part 1', function () {
        $generation = ItpGeneration::factory()->forUser($this->user)->withStatus(ItpStatus::AwaitingResonance)->withLegacyAttunement(3)->create();

        $response = $this->actingAs($this->user, 'sanctum')->getJson(route('api.generations.show', $generation))->assertOk();

        expect($response->json('data.generation.attunement.sections'))->toHaveCount(1)
            ->and($response->json('data.generation.attunement.sections.0.part'))->toBe(1)
            ->and($response->json('data.generation.attunement.sections.0.segments.*.order'))->toBe([1, 2, 3]);
    });

    it('returns the companions, title and description once complete', function () {
        $generation = ItpGeneration::factory()->forUser($this->user)->withStatus(ItpStatus::Complete)->withAttunement()->create([
            'title' => 'Coming Home',
            'description' => 'An attunement for rest.',
        ]);
        ItpCompanion::factory()->for($generation, 'generation')->create(['content' => [['order' => 1, 'text' => 'I breathe in ease.']]]);
        ItpCompanion::factory()->for($generation, 'generation')->quantumWhys()->create();

        $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.generations.show', $generation))
            ->assertOk()
            ->assertJsonPath('data.generation.companions', [
                'breathe_11' => [['order' => 1, 'text' => 'I breathe in ease.']],
                'quantum_whys' => ['Why is it so easy for me to rest?'],
            ])
            ->assertJsonPath('data.generation.title', 'Coming Home')
            ->assertJsonPath('data.generation.description', 'An attunement for rest.');
    });

    it('returns the configured message for a failed generation', function () {
        $generation = ItpGeneration::factory()->forUser($this->user)->withStatus(ItpStatus::Failed)->create(['last_error' => 'RuntimeException: overloaded']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.generations.show', $generation))
            ->assertOk()
            ->assertJsonPath('data.generation.message', config('itp.messages.failed'));
    });

    it('returns the same 404 for another user\'s generation as for a missing one', function () {
        $theirs = ItpGeneration::factory()->create();

        $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.generations.show', $theirs))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.', 'data' => [], 'errors' => []]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.generations.show', '01JAAAAAAAAAAAAAAAAAAAAAAA'))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.', 'data' => [], 'errors' => []]);
    });

    it('returns 401 without a token', function () {
        $this->getJson(route('api.generations.show', ItpGeneration::factory()->create()))->assertUnauthorized();
    });
});

describe('followups', function () {
    beforeEach(function () {
        $this->generation = ItpGeneration::factory()->forUser($this->user)->withStatus(ItpStatus::AwaitingFollowup)->create(['followup_rounds' => 1]);
        $this->followups = ItpFollowup::factory()->for($this->generation, 'generation')->count(2)->create(['round' => 1]);
    });

    it('stores the answers, returns 202 while assessing and queues the generation', function () {
        [$answered, $skipped] = $this->followups;

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.followups.store', $this->generation), [
                'answers' => [
                    ['followup_id' => $answered->id, 'answer_text' => 'Light, like the sea.'],
                    ['followup_id' => $skipped->id, 'answer_text' => null],
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('data.generation.status', 'assessing')
            ->assertJsonMissingPath('data.generation.followups');

        expect($answered->fresh()->answer_text)->toBe('Light, like the sea.')
            ->and($answered->fresh()->answered_at)->not->toBeNull()
            ->and($skipped->fresh()->answer_text)->toBeNull()
            ->and($skipped->fresh()->answered_at)->not->toBeNull()
            ->and($this->generation->fresh()->status)->toBe(ItpStatus::Assessing);

        Queue::assertPushedOn('generation', GenerateItpJob::class, fn (GenerateItpJob $job) => $job->generationId === $this->generation->id);
    });

    it('returns 422 unless the generation is awaiting follow-up answers', function () {
        $this->generation->update(['status' => ItpStatus::Generating]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.followups.store', $this->generation), [
                'answers' => [['followup_id' => $this->followups[0]->id, 'answer_text' => 'Light.']],
            ])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'This generation is not awaiting follow-up answers.', 'data' => [], 'errors' => []]);

        expect($this->followups[0]->fresh()->answer_text)->toBeNull();
        Queue::assertNothingPushed();
    });

    it('returns 422 for a question from another generation', function () {
        $otherQuestion = ItpFollowup::factory()->create();

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.followups.store', $this->generation), [
                'answers' => [['followup_id' => $otherQuestion->id, 'answer_text' => 'Light.']],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['answers.0.followup_id' => ['The selected answers.0.followup_id is invalid.']]);
    });

    it('returns 422 without answers', function () {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.followups.store', $this->generation), ['answers' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.answers', ['The answers field is required.']);
    });

    it('returns 404 for another user\'s generation', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson(route('api.generations.followups.store', $this->generation), [
                'answers' => [['followup_id' => $this->followups[0]->id, 'answer_text' => 'Light.']],
            ])
            ->assertNotFound();

        expect($this->followups[0]->fresh()->answer_text)->toBeNull();
    });
});

describe('section flags', function () {
    beforeEach(function () {
        $this->generation = ItpGeneration::factory()->forUser($this->user)->awaitingResonance(3, [1, 3, 3])->create();
        $this->segments = $this->generation->attunement->currentSegments;
    });

    it('stores the flag with its pinpointed line, returns 202 while regenerating and queues the section', function () {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, 3]), [
                'user_explanation' => 'Too generic.',
                'requested_change' => 'Mention the sea.',
                'segment_id' => $this->segments[2]->id,
            ])
            ->assertAccepted()
            ->assertJsonPath('data.generation.status', 'regenerating')
            ->assertJsonPath('data.generation.attunement.sections.0.regenerating', false)
            ->assertJsonPath('data.generation.attunement.sections.1.regenerating', true)
            ->assertJsonPath('data.generation.attunement.sections.1.pinpointed_segment_id', $this->segments[2]->id);

        $flag = ItpSegmentFlag::sole();
        expect($flag->part)->toBe(3)
            ->and($flag->segment_id)->toBe($this->segments[2]->id)
            ->and($flag->generation_id)->toBe($this->generation->id)
            ->and($flag->user_explanation)->toBe('Too generic.')
            ->and($flag->requested_change)->toBe('Mention the sea.')
            ->and($flag->status)->toBe(ItpSegmentFlagStatus::Open)
            ->and($flag->choice)->toBeNull();

        Queue::assertPushedOn('generation', RegenerateItpSectionJob::class, fn (RegenerateItpSectionJob $job) => $job->flagId === $flag->id);
    });

    it('takes a flag without a pinpointed line', function () {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, 1]), ['user_explanation' => 'Too generic.'])
            ->assertAccepted()
            ->assertJsonPath('data.generation.attunement.sections.0.pinpointed_segment_id', null);

        expect(ItpSegmentFlag::sole()->segment_id)->toBeNull();
    });

    it('returns 422 while another section is being rewritten', function () {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, 1]), ['user_explanation' => 'Too generic.'])
            ->assertAccepted();

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, 3]), ['user_explanation' => 'Also this.'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'This generation is not awaiting resonance.', 'data' => [], 'errors' => []]);

        expect(ItpSegmentFlag::count())->toBe(1);
        Queue::assertPushed(RegenerateItpSectionJob::class, 1);
    });

    it('returns 422 for a section whose rewrite is waiting for a choice, but takes another section', function () {
        sectionAwaitingChoice($this->generation, 3, ['The new line.']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, 3]), ['user_explanation' => 'Again.'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'This section has a new version waiting for a choice.', 'data' => [], 'errors' => []]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, 1]), ['user_explanation' => 'This one.'])
            ->assertAccepted()
            ->assertJsonPath('data.generation.attunement.sections.1.proposal.segments.0.text', 'The new line.');

        expect(ItpSegmentFlag::count())->toBe(2);
    });

    it('returns 422 when the pinpointed line is not a current line of that section', function (Closure $segment) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, 3]), [
                'user_explanation' => 'Too generic.',
                'segment_id' => $segment($this->generation)->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['segment_id' => ['The selected segment id is invalid.']]);

        expect(ItpSegmentFlag::count())->toBe(0);
    })->with([
        'another section' => [fn (ItpGeneration $generation) => $generation->attunement->currentSegments[0]],
        'another generation' => [fn (ItpGeneration $generation) => ItpGeneration::factory()->forUser($generation->user)->awaitingResonance(3, [3, 3, 3])->create()->attunement->currentSegments->first()],
        'superseded' => [fn (ItpGeneration $generation) => ItpAttunementSegment::factory()->for($generation->attunement, 'attunement')->superseded()->create(['part' => 3])],
    ]);

    it('returns 404 for a section the attunement does not have', function (int $part) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, $part]), ['user_explanation' => 'Too generic.'])
            ->assertNotFound();

        expect(ItpSegmentFlag::count())->toBe(0);
    })->with(['an empty part' => 2, 'past part 8' => 9]);

    it('returns 422 for a missing or overlong explanation', function (array $payload, string $field, string $message) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, 1]), $payload)
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}", [$message]);
    })->with([
        'no explanation' => [[], 'user_explanation', 'The user explanation field is required.'],
        'long explanation' => [['user_explanation' => str_repeat('a', 2001)], 'user_explanation', 'The user explanation field must not be greater than 2000 characters.'],
        'long change' => [['user_explanation' => 'Too generic.', 'requested_change' => str_repeat('a', 2001)], 'requested_change', 'The requested change field must not be greater than 2000 characters.'],
    ]);

    it('returns 404 for another user\'s generation', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson(route('api.generations.sections.flags.store', [$this->generation, 1]), ['user_explanation' => 'Too generic.'])
            ->assertNotFound();

        expect(ItpSegmentFlag::count())->toBe(0);
    });
});

describe('section choice', function () {
    beforeEach(function () {
        $this->generation = ItpGeneration::factory()->forUser($this->user)->awaitingResonance(4, [1, 3, 3, 8])->create();
        $this->segments = $this->generation->attunement->currentSegments;
    });

    it('keeps the new version in the old lines\' place, renumbers every line and bumps the version', function () {
        $flag = sectionAwaitingChoice($this->generation, 3, ['New one.', 'New two.', 'New three.']);
        $proposed = $flag->proposedSegments;

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.choice.store', [$this->generation, 3]), ['keep' => 'new'])
            ->assertOk()
            ->assertJsonPath('data.generation.status', 'awaiting_resonance')
            ->assertJsonPath('data.generation.attunement.version', 2)
            ->assertJsonPath('data.generation.attunement.sections.1.proposal', null)
            ->assertJsonPath('data.generation.attunement.sections.1.pinpointed_segment_id', null)
            ->assertJsonPath('data.generation.attunement.sections.1.segments', $proposed->map(fn (ItpAttunementSegment $segment, int $index) => [
                'id' => $segment->id, 'order' => $index + 2, 'text' => $segment->text,
            ])->all());

        $attunement = $this->generation->attunement->fresh();
        expect($attunement->currentSegments->map->only('order', 'part', 'text')->all())->toBe([
            ['order' => 1, 'part' => 1, 'text' => $this->segments[0]->text],
            ['order' => 2, 'part' => 3, 'text' => 'New one.'],
            ['order' => 3, 'part' => 3, 'text' => 'New two.'],
            ['order' => 4, 'part' => 3, 'text' => 'New three.'],
            ['order' => 5, 'part' => 8, 'text' => $this->segments[3]->text],
        ])
            ->and($attunement->currentSegments->slice(1, 3)->every(fn (ItpAttunementSegment $segment) => $segment->version === 2))->toBeTrue()
            ->and($this->segments[1]->fresh()->isSuperseded())->toBeTrue()
            ->and($this->segments[2]->fresh()->isSuperseded())->toBeTrue()
            ->and($attunement->segments()->count())->toBe(7)
            ->and($flag->fresh()->choice)->toBe(ItpSectionChoice::New)
            ->and($flag->fresh()->chosen_at)->not->toBeNull()
            ->and($this->generation->fresh()->corrections[0]['choice'])->toBe('new');
    });

    it('keeps the original and marks the proposed lines rejected', function () {
        $flag = sectionAwaitingChoice($this->generation, 3, ['New one.']);
        $proposed = $flag->proposedSegments->sole();

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.choice.store', [$this->generation, 3]), ['keep' => 'original'])
            ->assertOk()
            ->assertJsonPath('data.generation.attunement.version', 1)
            ->assertJsonPath('data.generation.attunement.sections.1.proposal', null);

        expect($this->generation->attunement->fresh()->currentSegments->modelKeys())->toBe($this->segments->modelKeys())
            ->and($proposed->fresh()->isRejected())->toBeTrue()
            ->and($flag->fresh()->choice)->toBe(ItpSectionChoice::Original)
            ->and($this->generation->fresh()->corrections[0]['choice'])->toBe('original');
    });

    it('works on an attunement from before v2, as one section', function () {
        $generation = ItpGeneration::factory()->forUser($this->user)->withStatus(ItpStatus::AwaitingResonance)->withLegacyAttunement(3)->create();
        sectionAwaitingChoice($generation, 1, ['All new.']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.choice.store', [$generation, 1]), ['keep' => 'new'])
            ->assertOk()
            ->assertJsonCount(1, 'data.generation.attunement.sections')
            ->assertJsonPath('data.generation.attunement.sections.0.segments.0.text', 'All new.')
            ->assertJsonCount(1, 'data.generation.attunement.sections.0.segments');
    });

    it('returns 422 for a section with no rewrite waiting', function () {
        sectionAwaitingChoice($this->generation, 3, ['New one.']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.choice.store', [$this->generation, 1]), ['keep' => 'new'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'This section has no new version waiting for a choice.', 'data' => [], 'errors' => []]);

        expect($this->generation->attunement->fresh()->version)->toBe(1);
    });

    it('returns 422 once the choice is made', function () {
        sectionAwaitingChoice($this->generation, 3, ['New one.']);
        $this->actingAs($this->user, 'sanctum')->postJson(route('api.generations.sections.choice.store', [$this->generation, 3]), ['keep' => 'new'])->assertOk();

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.choice.store', [$this->generation, 3]), ['keep' => 'original'])
            ->assertUnprocessable();

        expect($this->generation->attunement->fresh()->version)->toBe(2);
    });

    it('returns 422 without a valid choice', function (array $payload) {
        sectionAwaitingChoice($this->generation, 3, ['New one.']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.choice.store', [$this->generation, 3]), $payload)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['keep']);
    })->with(['none' => [[]], 'unknown' => [['keep' => 'both']]]);

    it('returns 404 for a section the attunement does not have, or another user\'s generation', function () {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.sections.choice.store', [$this->generation, 2]), ['keep' => 'new'])
            ->assertNotFound();

        sectionAwaitingChoice($this->generation, 3, ['New one.']);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson(route('api.generations.sections.choice.store', [$this->generation, 3]), ['keep' => 'new'])
            ->assertNotFound();

        expect(ItpSegmentFlag::sole()->choice)->toBeNull();
    });
});

describe('accept', function () {
    beforeEach(function () {
        $this->generation = ItpGeneration::factory()->forUser($this->user)->awaitingResonance()->create();
    });

    it('settles the attunement, returns 202 while deriving companions and queues them', function () {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.accept', $this->generation))
            ->assertAccepted()
            ->assertJsonPath('data.generation.status', 'deriving_companions')
            ->assertJsonPath('data.generation.attunement.id', $this->generation->attunement->id);

        expect($this->generation->attunement->fresh()->settled_at)->not->toBeNull();
        Queue::assertPushedOn('generation', DeriveItpCompanionsJob::class, fn (DeriveItpCompanionsJob $job) => $job->generationId === $this->generation->id);
    });

    it('returns 422 while a section\'s rewrite is waiting for a choice', function () {
        sectionAwaitingChoice($this->generation, 1, ['New one.']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.accept', $this->generation))
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'A section has a new version waiting for a choice.', 'data' => [], 'errors' => []]);

        expect($this->generation->attunement->fresh()->settled_at)->toBeNull();
        Queue::assertNothingPushed();
    });

    it('returns 422 unless the generation is awaiting resonance', function (ItpStatus $status) {
        $this->generation->update(['status' => $status]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.accept', $this->generation))
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'This generation is not awaiting resonance.', 'data' => [], 'errors' => []]);

        Queue::assertNothingPushed();
    })->with(['regenerating' => ItpStatus::Regenerating, 'deriving companions' => ItpStatus::DerivingCompanions]);

    it('returns 404 for another user\'s generation', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson(route('api.generations.accept', $this->generation))
            ->assertNotFound();

        expect($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance);
    });
});
