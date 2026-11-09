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
            'id' => '01m6skvqm3ddj4z6bqn2dydy0m',
            'source_key' => 'awaken-wonder',
            'kind' => 'universal',
            'sort_order' => 1,
            'title' => 'Light Up Your Life',
            'short_name' => 'Awaken Wonder',
            'is_preview' => true,
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
            'legacy' => ['id' => 1, 'short_name' => 'Awaken Wonder', 'preview' => true],
        ],
        [
            'id' => '01m6skvqn48n7m2xshb14y82v5',
            'source_key' => 'introduction',
            'kind' => 'base',
            'sort_order' => null,
            'title' => 'Introduction',
            'short_name' => null,
            'is_preview' => false,
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

it('imports every entry in the data file under its fixed id', function () {
    writeUniversalItpEntries($this->dataFile, universalItpEntries());

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('Universal ITPs: 2 created, 0 updated, 0 re-keyed, 0 unchanged.')
        ->assertSuccessful();

    expect(UniversalItp::where('slug', 'awaken-wonder')->sole())
        ->id->toBe('01m6skvqm3ddj4z6bqn2dydy0m')
        ->kind->toBe(UniversalItpKind::Universal)
        ->sort_order->toBe(1)
        ->title->toBe('Light Up Your Life')
        ->short_name->toBe('Awaken Wonder')
        ->is_preview->toBeTrue()
        ->description->toBe("Feeling uninspired?\nUse this ITP to light up your life.")
        ->image_path->toBe('media/images/universals/original/1-awaken-wonder.webp')
        ->attunement_text->toBe("Beloved Dreamer\nHave you stopped believing in magic?")
        ->attunement_timings->toBe(universalItpEntries()[0]['attunement_timings'])
        ->attunement_audio_path->toBe('media/audio/universals/activations/1-awaken-wonder.mp3')
        ->breath11_lines->toBe([['order' => 1, 'text' => 'Wonder rises within me as I breathe in light']])
        ->breath11_audio_path->toBe('media/audio/universals/breath11/1-awaken-wonder.mp3')
        ->quantum_whys->toBe(['Why does life feel so blessed and magical right now?']);

    expect(UniversalItp::where('slug', 'introduction')->sole())
        ->id->toBe('01m6skvqn48n7m2xshb14y82v5')
        ->kind->toBe(UniversalItpKind::Base)
        ->sort_order->toBeNull()
        ->short_name->toBeNull()
        ->is_preview->toBeFalse()
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
        ->expectsOutputToContain('Universal ITPs: 0 created, 0 updated, 0 re-keyed, 2 unchanged.')
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
        ->expectsOutputToContain('Universal ITPs: 0 created, 1 updated, 0 re-keyed, 1 unchanged.')
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
        ->expectsOutputToContain('Universal ITPs: 0 created, 0 updated, 0 re-keyed, 1 unchanged.')
        ->assertSuccessful();
});

it('re-keys a row an earlier import created under another id, then leaves it unchanged', function () {
    $earlier = UniversalItp::factory()->create(['slug' => 'awaken-wonder', 'title' => 'Old title']);
    writeUniversalItpEntries($this->dataFile, universalItpEntries());

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('Universal ITPs: 1 created, 0 updated, 1 re-keyed, 0 unchanged.')
        ->assertSuccessful();

    expect(UniversalItp::withTrashed()->find($earlier->id))->toBeNull()
        ->and(UniversalItp::withTrashed()->where('slug', 'awaken-wonder')->sole())
        ->id->toBe('01m6skvqm3ddj4z6bqn2dydy0m')
        ->title->toBe('Light Up Your Life')
        ->short_name->toBe('Awaken Wonder')
        ->is_preview->toBeTrue();

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('Universal ITPs: 0 created, 0 updated, 0 re-keyed, 2 unchanged.')
        ->assertSuccessful();
});

it('leaves a soft-deleted row deleted', function () {
    $deleted = UniversalItp::factory()->create(['slug' => 'awaken-wonder', 'title' => 'Old title']);
    $deleted->delete();
    writeUniversalItpEntries($this->dataFile, universalItpEntries());

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('Universal ITPs: 1 created, 0 updated, 0 re-keyed, 0 unchanged.')
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
    'missing id' => [['id' => null], 'The 1.id field is required.'],
    'id not a ULID' => [['id' => 'introduction'], 'The 1.id field must be a valid ULID.'],
    'uppercase id' => [['id' => '01M6SKVQN48N7M2XSHB14Y82V5'], 'The 1.id field must be lowercase.'],
    'duplicate id' => [['id' => '01m6skvqm3ddj4z6bqn2dydy0m'], 'The 1.id field has a duplicate value.'],
    'missing preview flag' => [['is_preview' => null], 'The 1.is_preview field is required.'],
]);

it('fails when the data file is missing', function () {
    unlink($this->dataFile);

    $this->artisan('itp:import-universal-itps', ['--path' => $this->dataFile])
        ->expectsOutputToContain('No data file at')
        ->assertFailed();
});

it('commits a fixed, unique ULID for each of the fourteen entries', function () {
    $entries = json_decode(file_get_contents(database_path('data/universal-itps.json')), true);
    $ids = array_column($entries, 'id');

    expect($entries)->toHaveCount(14)
        ->and(array_unique($ids))->toHaveCount(14)
        ->and($ids)->each->toMatch('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/');
});

it('imports the committed data file: thirteen universal ITPs and the Introduction', function () {
    $this->artisan('itp:import-universal-itps')
        ->expectsOutputToContain('Universal ITPs: 14 created, 0 updated, 0 re-keyed, 0 unchanged.')
        ->assertSuccessful();

    expect(UniversalItp::preview()->pluck('slug')->all())
        ->toEqualCanonicalizing(['awaken-wonder', 'abundance', 'healing', 'nervous-system-reset', 'introduction'])
        ->and(UniversalItp::whereNull('short_name')->count())->toBe(0)
        ->and(UniversalItp::where('slug', 'embody-courage-and-strength')->sole())
        ->title->toBe('Embody Courage and Strength')
        ->short_name->toBe('Inner Warrior')
        ->and(UniversalItp::where('slug', 'introduction')->sole())
        ->short_name->toBe('Introduction');

    expect(UniversalItp::where('kind', UniversalItpKind::Universal)->count())->toBe(13)
        ->and(UniversalItp::where('slug', 'introduction')->sole())
        ->kind->toBe(UniversalItpKind::Base)
        ->attunement_audio_path->toBe('media/audio/base/introduction.mp3')
        ->and(UniversalItp::where('slug', 'nervous-system-reset')->sole())
        ->title->toBe('Return to Presence and Peace')
        ->sort_order->toBe(2);
});
