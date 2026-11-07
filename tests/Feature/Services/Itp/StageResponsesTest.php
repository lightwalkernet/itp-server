<?php

use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpSafetyRoute;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\Stages\AttunementGenerationStage;
use App\Services\Itp\Stages\Breathe11Stage;
use App\Services\Itp\Stages\FollowUpGenerationStage;
use App\Services\Itp\Stages\QuantumWhysStage;
use App\Services\Itp\Stages\ResonanceRegenerationStage;
use App\Services\Itp\Stages\ReversalDerivationStage;
use App\Services\Itp\Stages\SafetyClassificationStage;
use App\Services\Itp\Stages\SlotExtractionStage;
use App\Services\Itp\Stages\TitleStage;
use Database\Factories\ItpGenerationFactory;
use Database\Seeders\PromptSeeder;
use Tests\Fakes\FakeStructuredModel;

/*
 * Each stage's schema against the prompt library's "Return ONLY JSON" shape, and what each stage
 * makes of a library-shaped answer.
 */

/**
 * A schema reduced to its shape: objects to their keys in order, lists to their item, strings
 * with an enum to "enum:a|b", everything else to its type.
 *
 * @param  array<string, mixed>  $schema
 */
function schemaShape(array $schema): mixed
{
    return match ($schema['type']) {
        'object' => array_map(schemaShape(...), $schema['properties']),
        'array' => [schemaShape($schema['items'])],
        default => isset($schema['enum']) ? 'enum:'.implode('|', $schema['enum']) : $schema['type'],
    };
}

/**
 * Every object in the schema, at any depth.
 *
 * @param  array<string, mixed>  $schema
 * @return list<array<string, mixed>>
 */
function schemaObjects(array $schema): array
{
    return match ($schema['type']) {
        'object' => [$schema, ...array_merge(...array_values(array_map(schemaObjects(...), $schema['properties'])))],
        'array' => schemaObjects($schema['items']),
        default => [],
    };
}

dataset('library shapes', [
    'safety' => [ItpStage::Safety, fn () => SafetyClassificationStage::schema(), [
        'route' => 'enum:clear|sensitive|block', 'categories' => ['string'], 'confidence' => 'number', 'reason' => 'string',
    ]],
    'extraction' => [ItpStage::Extraction, fn () => SlotExtractionStage::schema(), [
        'display_name' => 'string', 'address_style' => 'enum:beloved_name|beloved_one',
        'dedication' => 'string', 'concrete_outcome' => 'string', 'proper_nouns' => ['string'],
        'challenge' => 'string', 'old_story_raw' => 'string', 'golden_phrase' => 'string',
        'protective_note' => 'string', 'vision' => 'string', 'scene' => 'string', 'soulful_detail' => 'string',
        'avoid' => 'string', 'register_signals' => ['string'], 'tone_signals' => ['string'],
        'sufficient' => 'boolean',
        'gaps' => [['slot' => 'string', 'why_thin' => 'string']],
    ]],
    'followups' => [ItpStage::Followups, fn () => FollowUpGenerationStage::schema(), [
        'questions' => [['gap_slot' => 'string', 'question_text' => 'string']],
    ]],
    'reversal' => [ItpStage::Reversal, fn () => ReversalDerivationStage::schema(), [
        'old_spell' => 'string', 'protective_function' => 'string', 'itp_shift' => 'string',
        'reframe_family' => 'string', 'central_metaphor' => 'string', 'essence_recognition' => 'string',
        'evidence_domains' => ['string'],
        'register' => ['mode' => 'enum:responsive_life|spiritual', 'words_to_echo' => ['string'], 'notes' => 'string'],
        'temperature' => 'enum:tender|propulsive|ceremonial|playful|steady',
    ]],
    'attunement' => [ItpStage::Attunement, fn () => AttunementGenerationStage::schema(), ['segments' => [['id' => 'integer', 'part' => 'integer', 'text' => 'string']]]],
    'regeneration' => [ItpStage::Regeneration, fn () => ResonanceRegenerationStage::schema(), ['segments' => [['id' => 'integer', 'text' => 'string']]]],
    'breathe 11' => [ItpStage::Breathe11, fn () => Breathe11Stage::schema(), ['lines' => [['id' => 'integer', 'text' => 'string']]]],
    'quantum whys' => [ItpStage::QuantumWhys, fn () => QuantumWhysStage::schema(), ['questions' => [['id' => 'integer', 'text' => 'string']]]],
    'title' => [ItpStage::Title, fn () => TitleStage::schema(), ['title' => 'string', 'description' => 'string']],
]);

it('asks for exactly the library\'s keys and nesting', function (ItpStage $stage, Closure $schema, array $shape) {
    expect(schemaShape($schema()))->toBe($shape);
})->with('library shapes');

it('is strict: every object closed, with every key required', function (ItpStage $stage, Closure $schema) {
    foreach (schemaObjects($schema()) as $object) {
        expect($object['additionalProperties'])->toBeFalse()
            ->and($object['required'])->toBe(array_keys($object['properties']));
    }
})->with('library shapes');

it('accepts the fake\'s library-shaped default answer', function (ItpStage $stage, Closure $schema) {
    expect(JsonSchema::violation($schema(), FakeStructuredModel::defaultResponse($stage)))->toBeNull();
})->with('library shapes');

it('names the first way an answer breaks the schema', function (array $answer, string $violation) {
    expect(JsonSchema::violation(ReversalDerivationStage::schema(), $answer))->toBe($violation);
})->with([
    'a missing key' => [collect(ItpGenerationFactory::derivedSlots())->except('itp_shift')->all(), '$.itp_shift is missing'],
    'an extra key' => [[...ItpGenerationFactory::derivedSlots(), 'mirrored_vocab' => 'home'], '$ has unexpected key mirrored_vocab'],
    'a nested enum' => [array_replace_recursive(ItpGenerationFactory::derivedSlots(), ['register' => ['mode' => 'religious']]), '$.register.mode must be one of responsive_life, spiritual'],
    'a list item' => [[...ItpGenerationFactory::derivedSlots(), 'evidence_domains' => ['home', 3]], '$.evidence_domains[1] must be a JSON string'],
]);

describe('parsing', function () {
    beforeEach(function () {
        $this->seed(PromptSeeder::class);
        $this->generation = ItpGeneration::factory()->withStatus(ItpStatus::Generating)->withAttunement(3)->create();
    });

    it('reads the safety verdict and fails closed below the confidence threshold', function () {
        fakeAi()->respondWith(ItpStage::Safety,
            ['route' => 'clear', 'categories' => [' career ', ''], 'confidence' => 0.92, 'reason' => ' Ordinary goals. '],
            ['route' => 'clear', 'categories' => [], 'confidence' => 0.69, 'reason' => 'Unsure.'],
        );

        expect(app(SafetyClassificationStage::class)->run($this->generation))
            ->toBe(['route' => ItpSafetyRoute::Clear, 'categories' => ['career'], 'confidence' => 0.92, 'reason' => 'Ordinary goals.'])
            ->and(app(SafetyClassificationStage::class)->run($this->generation)['route'])->toBe(ItpSafetyRoute::Sensitive);
    });

    it('keeps the whole extraction, dropping empty gaps', function () {
        $extraction = ItpGenerationFactory::extraction(['sufficient' => false, 'gaps' => [['slot' => 'scene', 'why_thin' => 'No moment named.'], ['slot' => '', 'why_thin' => '']]]);
        fakeAi()->respondWith(ItpStage::Extraction, $extraction);

        expect(app(SlotExtractionStage::class)->run($this->generation))
            ->toBe([...$extraction, 'gaps' => [['slot' => 'scene', 'why_thin' => 'No moment named.']]]);
    });

    it('keeps at most two non-blank follow-up questions with their gap slots', function () {
        fakeAi()->respondWith(ItpStage::Followups, ['questions' => [
            ['gap_slot' => '', 'question_text' => '  '],
            ['gap_slot' => 'scene', 'question_text' => ' Is there a Tuesday morning that would tell you? '],
            ['gap_slot' => '', 'question_text' => 'Who is with you?'],
            ['gap_slot' => 'vision', 'question_text' => 'A third?'],
        ]]);

        expect(app(FollowUpGenerationStage::class)->run($this->generation))->toBe([
            ['gap_slot' => 'scene', 'question_text' => 'Is there a Tuesday morning that would tell you?'],
            ['gap_slot' => null, 'question_text' => 'Who is with you?'],
        ]);
    });

    it('stores the derived slots exactly as returned', function () {
        expect(app(ReversalDerivationStage::class)->run($this->generation))->toBe(ItpGenerationFactory::derivedSlots());
    });

    it('orders attunement segments by the model\'s ids with their parts, and drops blank ones', function () {
        fakeAi()->respondWith(ItpStage::Attunement, ['segments' => [
            ['id' => 3, 'part' => 8, 'text' => 'Third.'],
            ['id' => 1, 'part' => 1, 'text' => ' First. '],
            ['id' => 2, 'part' => 1, 'text' => ' '],
            ['id' => 4, 'part' => 8, 'text' => 'Fourth.'],
        ]]);

        expect(app(AttunementGenerationStage::class)->run($this->generation))->toBe([
            ['part' => 1, 'text' => 'First.'],
            ['part' => 8, 'text' => 'Third.'],
            ['part' => 8, 'text' => 'Fourth.'],
        ]);
    });

    it('rejects an attunement with repeated or no segments, or parts out of range or out of order', function (array $segments, string $message) {
        fakeAi()->respondWith(ItpStage::Attunement, ['segments' => $segments]);

        expect(fn () => app(AttunementGenerationStage::class)->run($this->generation))->toThrow(UnusableResponse::class, $message);
    })->with([
        'a repeated id' => [[['id' => 1, 'part' => 1, 'text' => 'A.'], ['id' => 1, 'part' => 1, 'text' => 'B.']], 'segments id 1 appears twice'],
        'nothing' => [[['id' => 1, 'part' => 1, 'text' => ' ']], 'The [attunement] stage returned no segments.'],
        'no part' => [[['id' => 1, 'text' => 'A.']], '$.segments[0].part is missing'],
        'part 0' => [[['id' => 1, 'part' => 0, 'text' => 'A.']], 'segment id 1 has part 0, not 1 to 8'],
        'part 9' => [[['id' => 1, 'part' => 1, 'text' => 'A.'], ['id' => 2, 'part' => 9, 'text' => 'B.']], 'segment id 2 has part 9, not 1 to 8'],
        'interleaved parts' => [[['id' => 1, 'part' => 1, 'text' => 'A.'], ['id' => 2, 'part' => 3, 'text' => 'B.'], ['id' => 3, 'part' => 2, 'text' => 'C.']], 'segment id 3 is in part 2 after part 3: parts must run in order'],
    ]);

    it('rejects an answer in the old, pre-library shape', function () {
        fakeAi()->respondWith(ItpStage::QuantumWhys, ['whys' => ['Why is it so easy?']]);

        expect(fn () => app(QuantumWhysStage::class)->run($this->generation))
            ->toThrow(UnusableResponse::class, 'The [quantum_whys] stage returned an unusable response: $.questions is missing.');
    });

    it('trims the title and description, and caps the title', function () {
        fakeAi()->respondWith(ItpStage::Title, ['title' => ' '.str_repeat('a', 300).' ', 'description' => ' For the morning. ']);

        expect(app(TitleStage::class)->run($this->generation))
            ->toBe(['title' => str_repeat('a', TitleStage::MAX_TITLE_LENGTH), 'description' => 'For the morning.']);
    });
});
