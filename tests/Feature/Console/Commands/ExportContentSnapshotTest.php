<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Itp\Core\Models\UniversalItp;
use Itp\Core\Models\Wildcard;
use Lightwalker\Sync\Server\SyncServer;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/content-snapshot-'.uniqid();
    $this->snapshotPath = $this->directory.'/content/snapshot.json';
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

/**
 * @return array{generated_at: string, types: array<string, list<array<string, mixed>>>}
 */
function readContentSnapshot(string $path): array
{
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('writes every live global row exactly as the pull sends it, without sync_seq', function () {
    $this->travelTo('2026-11-07 12:00:00');
    $itps = UniversalItp::factory()->count(2)->sequence(['is_preview' => true], ['is_preview' => false])->create();
    UniversalItp::factory()->create()->delete();
    $wildcard = Wildcard::factory()->create();

    $this->artisan('itp:export-content-snapshot', ['--path' => $this->snapshotPath])
        ->expectsOutputToContain('2 universal_itps, 1 wildcards')
        ->assertSuccessful();

    $pulled = fn (string $type): array => collect(app(SyncServer::class)->pull($type, '', 0, 100)['records'])
        ->whereNull('deleted_at')
        ->map(fn (array $record): array => Arr::except($record, ['sync_seq']))
        ->values()
        ->all();
    $snapshot = readContentSnapshot($this->snapshotPath);

    expect($snapshot['generated_at'])->toBe('2026-11-07T12:00:00Z')
        ->and(array_keys($snapshot['types']))->toBe(['universal_itps', 'wildcards'])
        ->and($snapshot['types']['universal_itps'])->toBe($pulled('universal_itps'))
        ->and(array_column($snapshot['types']['universal_itps'], 'id'))->toBe($itps->pluck('id')->all())
        ->and($snapshot['types']['universal_itps'][0])->not->toHaveKeys(['sync_seq', 'sync_dirty', 'sync_rev'])
        ->and($snapshot['types']['wildcards'])->toBe($pulled('wildcards'))
        ->and($snapshot['types']['wildcards'][0]['why'])->toBe($wildcard->why);
});

it('pages through more rows than one pull returns', function () {
    config(['sync.server.max_pull_limit' => 2]);
    $wildcards = Wildcard::factory()->count(5)->create();

    $this->artisan('itp:export-content-snapshot', ['--path' => $this->snapshotPath])->assertSuccessful();

    expect(array_column(readContentSnapshot($this->snapshotPath)['types']['wildcards'], 'id'))
        ->toBe($wildcards->pluck('id')->all());
});

it('writes empty lists when there is no content', function () {
    $this->artisan('itp:export-content-snapshot', ['--path' => $this->snapshotPath])->assertSuccessful();

    expect(readContentSnapshot($this->snapshotPath)['types'])->toBe(['universal_itps' => [], 'wildcards' => []]);
});
