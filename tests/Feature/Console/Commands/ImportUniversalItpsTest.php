<?php

use Itp\Core\Enums\UniversalItpKind;
use Itp\Core\Models\UniversalItp;

beforeEach(function () {
    $this->dataFile = tempnam(sys_get_temp_dir(), 'universal-itps');
});

afterEach(function () {
    @unlink($this->dataFile);
});

/**
 * Two entries shaped like `database/data/universal-itps.json`: one universal, one base.
 *
 * @return list<array<string, mixed>>
 */
function universalItpEntries(): array
{
    return [
        [
            'source_key' => 'awaken-wonder',
            'kind' => 'universal',
            'sort_order' => 1,
            'title' => 'Light Up Your Life',
            'description' => "Feeling uninspired?\nUse this ITP to light up your life.",
            'image_path' => 'media/images/universals/original/1-awaken-wonder.webp',
            'attunement_text' => "Beloved Dreamer\nHave you stopped believing in magic?",
            'attunement_timings' => [
                ['end' => 1.76, 'text' => 'Beloved Dreamer', 'start' => 0.16, 'words' => [
                    ['end' => 0.79999995, 'word' => 'Beloved', 'start' => 0.16],
                    ['end' => 1.76, 'word' => 'Dreamer', 'start' => 0.79999995],
                ]],
            ],
            'attunement_audio_path' => 'media/audio/universals/activations/1-awaken-wonder.mp3',
            'breath11_lines' => [['order' => 1, 'text' => 'Wonder rises within me as I breathe in light']],
            'breath11_timings' => [['end' => 3.6, 'text' => 'Wonder rises within me as I breathe in light', 'start' => 0.48, 'words' => []]],
            'breath11_audio_path' => 'media/audio/universals/breath11/1-awaken-wonder.mp3',
            'quantum_whys' => ['Why does life feel so blessed and magical right now?'],
            'legacy' => ['id' => 1, 'short_name' => 'Awaken Wonder'],
        ],
        [
            'source_key' => 'introduction',
            'kind' => 'base',
            'sort_order' => null,
            'title' => 'Introduction',
            'description' => null,
            'image_path' => null,
            'attunement_text' => 'The world’s spell is breaking',
            'attunement_timings' => [['end' => 3.36, 'text' => 'The world’s spell is breaking', 'start' => 0.79999995, 'words' => []]],
            'attunement_audio_path' => 'media/audio/base/introduction.mp3',
            'breath11_lines' => null,
            'breath11_timings' => null,
            'breath11_audio_path' => null,
            'quantum_whys' => null,
        ],
    ];
}

/**
 * @param  list<array<string, mixed>>  $entries
 */
function writeUniversalItpEntries(string $path, array $entries): void
{
    file_put_contents($path, json_encode($entries, JSON_THROW_ON_ERROR));
}

it('imports every entry in the data file', function () {
    writeUniversalItpEntries($this->dataFile, universalItpEntries());

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('Universal ITPs: 2 created, 0 updated, 0 unchanged.')
        ->assertSuccessful();

    expect(UniversalItp::where('slug', 'awaken-wonder')->sole())
        ->kind->toBe(UniversalItpKind::Universal)
        ->sort_order->toBe(1)
        ->title->toBe('Light Up Your Life')
        ->description->toBe("Feeling uninspired?\nUse this ITP to light up your life.")
        ->image_path->toBe('media/images/universals/original/1-awaken-wonder.webp')
        ->attunement_text->toBe("Beloved Dreamer\nHave you stopped believing in magic?")
        ->attunement_timings->toBe(universalItpEntries()[0]['attunement_timings'])
        ->attunement_audio_path->toBe('media/audio/universals/activations/1-awaken-wonder.mp3')
        ->breath11_lines->toBe([['order' => 1, 'text' => 'Wonder rises within me as I breathe in light']])
        ->breath11_audio_path->toBe('media/audio/universals/breath11/1-awaken-wonder.mp3')
        ->quantum_whys->toBe(['Why does life feel so blessed and magical right now?']);

    expect(UniversalItp::where('slug', 'introduction')->sole())
        ->kind->toBe(UniversalItpKind::Base)
        ->sort_order->toBeNull()
        ->breath11_lines->toBeNull()
        ->quantum_whys->toBeNull();
});

it('stamps every imported row with its own sync change number', function () {
    writeUniversalItpEntries($this->dataFile, universalItpEntries());

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])->assertSuccessful();

    $sequences = UniversalItp::pluck('sync_seq');
    expect($sequences)->toHaveCount(2)
        ->and($sequences->every(fn (?int $sequence) => $sequence > 0))->toBeTrue()
        ->and($sequences->unique())->toHaveCount(2);
});

it('changes nothing when run again on the same file', function () {
    writeUniversalItpEntries($this->dataFile, universalItpEntries());
    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])->assertSuccessful();
    $before = UniversalItp::pluck('sync_seq', 'id');

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('Universal ITPs: 0 created, 0 updated, 2 unchanged.')
        ->assertSuccessful();

    expect(UniversalItp::pluck('sync_seq', 'id'))->toEqual($before);
});

it('updates a changed entry in place and re-stamps it', function () {
    $entries = universalItpEntries();
    writeUniversalItpEntries($this->dataFile, $entries);
    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])->assertSuccessful();
    $original = UniversalItp::where('slug', 'awaken-wonder')->sole();
    $untouched = UniversalItp::where('slug', 'introduction')->sole();
    $entries[0]['title'] = 'Light Up Your Whole Life';
    writeUniversalItpEntries($this->dataFile, $entries);

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('Universal ITPs: 0 created, 1 updated, 1 unchanged.')
        ->assertSuccessful();

    $updated = $original->fresh();
    expect(UniversalItp::count())->toBe(2)
        ->and($updated->title)->toBe('Light Up Your Whole Life')
        ->and($updated->sync_seq)->toBeGreaterThan($original->sync_seq)
        ->and($untouched->fresh()->sync_seq)->toBe($untouched->sync_seq);
});

it('treats JSON stored with reordered keys or whole-number floats as unchanged', function () {
    $entry = universalItpEntries()[1];
    UniversalItp::factory()->base()->create([
        ...collect($entry)->except('source_key')->all(),
        'slug' => 'introduction',
        'attunement_timings' => [['words' => [], 'start' => 0.79999995, 'text' => 'The world’s spell is breaking', 'end' => 3]],
    ]);
    $entry['attunement_timings'][0]['end'] = 3.0;
    writeUniversalItpEntries($this->dataFile, [$entry]);

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('Universal ITPs: 0 created, 0 updated, 1 unchanged.')
        ->assertSuccessful();
});

it('leaves a soft-deleted row deleted', function () {
    $deleted = UniversalItp::factory()->create(['slug' => 'awaken-wonder', 'title' => 'Old title']);
    $deleted->delete();
    writeUniversalItpEntries($this->dataFile, universalItpEntries());

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('Universal ITPs: 1 created, 0 updated, 0 unchanged.')
        ->expectsOutputToContain('1 skipped because the row is soft-deleted.')
        ->assertSuccessful();

    expect($deleted->fresh())
        ->trashed()->toBeTrue()
        ->title->toBe('Old title');
});

it('writes nothing when an entry is invalid', function (array $override, string $error) {
    $entries = universalItpEntries();
    $entries[1] = [...$entries[1], ...$override];
    writeUniversalItpEntries($this->dataFile, $entries);

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain($error)
        ->assertFailed();

    expect(UniversalItp::count())->toBe(0);
})->with([
    'missing title' => [['title' => null], 'The 1.title field is required.'],
    'unknown kind' => [['kind' => 'luminous'], 'The selected 1.kind is invalid.'],
    'duplicate source key' => [['source_key' => 'awaken-wonder'], 'The 1.source_key field has a duplicate value.'],
]);

it('fails when the data file is missing', function () {
    unlink($this->dataFile);

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('No data file at')
        ->assertFailed();
});

it('imports the committed data file: thirteen universal ITPs and the Introduction', function () {
    $this->artisan('itp:import-universal-itps')
        ->expectsOutputToContain('Universal ITPs: 14 created, 0 updated, 0 unchanged.')
        ->assertSuccessful();

    expect(UniversalItp::where('kind', UniversalItpKind::Universal)->count())->toBe(13)
        ->and(UniversalItp::where('slug', 'introduction')->sole())
        ->kind->toBe(UniversalItpKind::Base)
        ->attunement_audio_path->toBe('media/audio/base/introduction.mp3')
        ->and(UniversalItp::where('slug', 'nervous-system-reset')->sole())
        ->title->toBe('Return to Presence and Peace')
        ->sort_order->toBe(2);
});
