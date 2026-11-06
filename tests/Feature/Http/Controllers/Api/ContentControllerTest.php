<?php

use Itp\Core\Models\UniversalItp;
use Itp\Core\Models\User;
use Itp\Core\Models\Wildcard;

beforeEach(function () {
    $this->withHeader('X-App-Version', '1.0.0');
});

it('lets a guest pull every universal ITP, tombstones included, without client-only columns', function () {
    $preview = UniversalItp::factory()->preview()->create(['short_name' => 'Awaken Wonder']);
    $locked = UniversalItp::factory()->create();
    $deleted = UniversalItp::factory()->create();
    $deleted->delete();

    $response = $this->getJson(route('api.content.pull', ['type' => 'universal_itps']))
        ->assertOk()
        ->assertJsonPath('data.type', 'universal_itps')
        ->assertJsonPath('data.has_more', false)
        ->assertJsonPath('data.next_cursor', $deleted->fresh()->sync_seq);

    $records = collect($response->json('data.records'))->keyBy('id');
    expect($records->keys()->all())->toBe([$preview->id, $locked->id, $deleted->id])
        ->and($records[$preview->id])->toMatchArray(['short_name' => 'Awaken Wonder', 'is_preview' => 1])
        ->and($records[$preview->id])->not->toHaveKeys(['sync_dirty', 'sync_rev'])
        ->and($records[$deleted->id]['deleted_at'])->not->toBeNull();
});

it('pulls wildcards incrementally from the cursor', function () {
    [$first, $second, $third] = Wildcard::factory()->count(3)->create();

    $this->getJson(route('api.content.pull', ['type' => 'wildcards', 'after' => $first->sync_seq, 'limit' => 1]))
        ->assertOk()
        ->assertJsonPath('data.records.*.id', [$second->id])
        ->assertJsonPath('data.next_cursor', $second->sync_seq)
        ->assertJsonPath('data.has_more', true);

    $this->getJson(route('api.content.pull', ['type' => 'wildcards', 'after' => $second->sync_seq]))
        ->assertOk()
        ->assertJsonPath('data.records.*.id', [$third->id])
        ->assertJsonPath('data.has_more', false);
});

it('answers exactly as the signed-in sync pull does', function (string $type) {
    UniversalItp::factory()->count(2)->create();
    Wildcard::factory()->count(2)->create();
    $query = ['type' => $type, 'after' => 0, 'limit' => 50];

    $guest = $this->getJson(route('api.content.pull', $query))->assertOk()->json();
    $signedIn = $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson(route('api.sync.pull', $query))
        ->assertOk()
        ->json();

    expect($guest)->toBe($signedIn);
})->with(['universal_itps', 'wildcards']);

it('returns 404 for any type that is not global', function (string $type) {
    User::factory()->create();

    $this->getJson(route('api.content.pull', ['type' => $type]))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.', 'data' => [], 'errors' => []]);
})->with(['users', 'itps', 'unknown']);

it('returns 426 below the minimum app version', function () {
    config(['itp.min_app_version' => '1.2.0']);

    $this->withHeader('X-App-Version', '1.1.9')
        ->getJson(route('api.content.pull', ['type' => 'universal_itps']))
        ->assertStatus(426);
});

it('limits requests per IP', function () {
    config(['itp.content.requests_per_minute' => 1]);

    $this->getJson(route('api.content.pull', ['type' => 'wildcards']))->assertOk();
    $this->getJson(route('api.content.pull', ['type' => 'wildcards']))->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->getJson(route('api.content.pull', ['type' => 'wildcards']))
        ->assertOk();
});
