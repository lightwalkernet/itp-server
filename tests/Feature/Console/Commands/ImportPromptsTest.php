<?php

use App\Enums\ItpStage;
use App\Models\PromptVersion;
use Database\Seeders\PromptSeeder;

beforeEach(function () {
    $this->library = database_path('data/prompts/ITP_Prompt_Library_v1.md');
    $this->copy = sys_get_temp_dir().'/ITP_Prompt_Library_test.md';
});

afterEach(function () {
    @unlink($this->copy);
});

it('imports the method core and all nine stage prompts from the committed library as active versions', function () {
    $this->artisan('itp:import-prompts')
        ->expectsOutputToContain('10 imported, 0 unchanged.')
        ->assertSuccessful();

    expect(PromptVersion::count())->toBe(10);

    foreach (ItpStage::cases() as $stage) {
        expect(PromptVersion::activeFor($stage))
            ->version->toBe(1)
            ->notes->toBe('Imported from ITP_Prompt_Library_v1.md')
            ->created_by_staff_id->toBeNull()
            ->body->not->toContain('```');
    }

    expect(PromptVersion::activeFor(ItpStage::MethodCore)->body)
        ->toStartWith("=== ITP METHOD CORE ===\n\nYou write ITP attunements")
        ->toEndWith('=== END METHOD CORE ===')
        ->and(PromptVersion::activeFor(ItpStage::Safety)->body)
        ->toStartWith('You screen intake answers')
        ->toEndWith("  \"reason\": \"one sentence, for staff review only — never shown to the person\"\n}")
        ->and(PromptVersion::activeFor(ItpStage::Extraction)->body)->toStartWith('You read a person\'s intake answers')
        ->and(PromptVersion::activeFor(ItpStage::Followups)->body)->toEndWith('{ "questions": [ { "gap_slot": "", "question_text": "" } ] }')
        ->and(PromptVersion::activeFor(ItpStage::Reversal)->body)->toStartWith('You find the single identity-level shift')
        ->and(PromptVersion::activeFor(ItpStage::Attunement)->body)->toStartWith('Write one personal ITP attunement')
        ->and(PromptVersion::activeFor(ItpStage::Regeneration)->body)->toEndWith('where id is the id of the segment being replaced.')
        ->and(PromptVersion::activeFor(ItpStage::Breathe11)->body)->toStartWith('Write a Breathe 11')
        ->and(PromptVersion::activeFor(ItpStage::QuantumWhys)->body)->toStartWith('Write the Quantum Whys')
        ->and(PromptVersion::activeFor(ItpStage::Title)->body)->toEndWith('{ "title": "", "description": "" }');
});

it('adds new versions on top of the placeholders, and changes nothing when run again', function () {
    $this->seed(PromptSeeder::class);

    $this->artisan('itp:import-prompts')->assertSuccessful();

    expect(PromptVersion::count())->toBe(20)
        ->and(PromptVersion::activeFor(ItpStage::Title)->version)->toBe(2);

    $this->artisan('itp:import-prompts')
        ->expectsOutputToContain('0 imported, 10 unchanged.')
        ->assertSuccessful();

    expect(PromptVersion::count())->toBe(20);
});

it('imports only the prompts whose text changed', function () {
    $this->artisan('itp:import-prompts')->assertSuccessful();
    file_put_contents($this->copy, str_replace('Two to five words. Sentence case.', 'Two to four words. Sentence case.', file_get_contents($this->library)));

    $this->artisan('itp:import-prompts', ['path' => $this->copy])
        ->expectsOutputToContain('1 imported, 9 unchanged.')
        ->assertSuccessful();

    expect(PromptVersion::activeFor(ItpStage::Title))
        ->version->toBe(2)
        ->body->toContain('Two to four words.')
        ->notes->toBe('Imported from ITP_Prompt_Library_test.md');
});

it('refuses a library with a block missing, saving nothing', function (string $search, string $replace, string $missing) {
    file_put_contents($this->copy, str_replace($search, $replace, file_get_contents($this->library)));

    $this->artisan('itp:import-prompts', ['path' => $this->copy])
        ->expectsOutputToContain("No prompt found in ITP_Prompt_Library_test.md for: {$missing}.")
        ->assertFailed();

    expect(PromptVersion::count())->toBe(0);
})->with([
    'no stage 5 heading' => ['## Stage 5 — Segment regeneration', '## Segment regeneration', 'regeneration'],
    'no method core heading' => ['### The method core (shared, cached)', '### The shared voice', 'method_core'],
    'a stage without a code block' => ["## Stage 8 — Title and description\n", "## Stage 8 — Title and description\n\n## Elsewhere\n", 'title'],
]);

it('fails when there is no file at the path', function () {
    $this->artisan('itp:import-prompts', ['path' => '/nowhere/library.md'])
        ->expectsOutputToContain('No prompt library at /nowhere/library.md.')
        ->assertFailed();
});
