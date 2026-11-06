<?php

use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStatus;
use App\Jobs\DeriveItpCompanionsJob;
use App\Jobs\GenerateItpJob;
use App\Jobs\RegenerateItpSegmentJob;
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

    it('shows the current segments in order and marks the one being regenerated', function () {
        $generation = ItpGeneration::factory()->forUser($this->user)->withStatus(ItpStatus::Regenerating)->withAttunement(2)->create();
        $attunement = $generation->attunement;
        [$first, $second] = $attunement->currentSegments;
        ItpAttunementSegment::factory()->for($attunement, 'attunement')->superseded()->create(['order' => 1, 'text' => 'An older first line.']);
        ItpSegmentFlag::factory()->create(['segment_id' => $second->id]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson(route('api.generations.show', $generation))
            ->assertOk()
            ->assertJsonPath('data.generation.attunement', [
                'id' => $attunement->id,
                'version' => 1,
                'segments' => [
                    ['id' => $first->id, 'order' => 1, 'text' => $first->text],
                    ['id' => $second->id, 'order' => 2, 'text' => $second->text, 'regenerating' => true],
                ],
            ]);
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

describe('flags', function () {
    beforeEach(function () {
        $this->generation = ItpGeneration::factory()->forUser($this->user)->awaitingResonance()->create();
        $this->segment = $this->generation->attunement->currentSegments->first();
    });

    it('stores the flag, returns 202 while regenerating and queues the segment', function () {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.segments.flags.store', [$this->generation, $this->segment]), [
                'user_explanation' => 'Too generic.',
                'requested_change' => 'Mention the sea.',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.generation.status', 'regenerating')
            ->assertJsonPath('data.generation.attunement.segments.0.regenerating', true);

        $flag = ItpSegmentFlag::sole();
        expect($flag->segment_id)->toBe($this->segment->id)
            ->and($flag->generation_id)->toBe($this->generation->id)
            ->and($flag->user_explanation)->toBe('Too generic.')
            ->and($flag->requested_change)->toBe('Mention the sea.')
            ->and($flag->status)->toBe(ItpSegmentFlagStatus::Open);

        Queue::assertPushedOn('generation', RegenerateItpSegmentJob::class, fn (RegenerateItpSegmentJob $job) => $job->flagId === $flag->id);
    });

    it('returns 422 unless the generation is awaiting resonance', function () {
        $this->generation->update(['status' => ItpStatus::Regenerating]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.segments.flags.store', [$this->generation, $this->segment]), ['user_explanation' => 'Too generic.'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'This generation is not awaiting resonance.', 'data' => [], 'errors' => []]);

        expect(ItpSegmentFlag::count())->toBe(0);
    });

    it('returns 404 for a segment of another generation or a superseded one', function (Closure $segment) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.segments.flags.store', [$this->generation, $segment($this->generation)]), ['user_explanation' => 'Too generic.'])
            ->assertNotFound();

        expect(ItpSegmentFlag::count())->toBe(0);
    })->with([
        'another generation' => [fn (ItpGeneration $generation) => ItpGeneration::factory()->forUser($generation->user)->awaitingResonance()->create()->attunement->currentSegments->first()],
        'superseded' => [fn (ItpGeneration $generation) => ItpAttunementSegment::factory()->for($generation->attunement, 'attunement')->superseded()->create()],
    ]);

    it('returns 422 for a missing or overlong explanation', function (array $payload, string $field, string $message) {
        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.segments.flags.store', [$this->generation, $this->segment]), $payload)
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}", [$message]);
    })->with([
        'no explanation' => [[], 'user_explanation', 'The user explanation field is required.'],
        'long explanation' => [['user_explanation' => str_repeat('a', 2001)], 'user_explanation', 'The user explanation field must not be greater than 2000 characters.'],
        'long change' => [['user_explanation' => 'Too generic.', 'requested_change' => str_repeat('a', 2001)], 'requested_change', 'The requested change field must not be greater than 2000 characters.'],
    ]);

    it('returns 404 for another user\'s generation', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson(route('api.generations.segments.flags.store', [$this->generation, $this->segment]), ['user_explanation' => 'Too generic.'])
            ->assertNotFound();

        expect(ItpSegmentFlag::count())->toBe(0);
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

    it('returns 422 unless the generation is awaiting resonance', function () {
        $this->generation->update(['status' => ItpStatus::DerivingCompanions]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson(route('api.generations.accept', $this->generation))
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'This generation is not awaiting resonance.', 'data' => [], 'errors' => []]);

        Queue::assertNothingPushed();
    });

    it('returns 404 for another user\'s generation', function () {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson(route('api.generations.accept', $this->generation))
            ->assertNotFound();

        expect($this->generation->fresh()->status)->toBe(ItpStatus::AwaitingResonance);
    });
});
