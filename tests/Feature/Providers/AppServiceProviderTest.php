<?php

use Anthropic\Client;
use App\Jobs\DeriveItpCompanionsJob;
use App\Jobs\GenerateItpJob;
use App\Jobs\RegenerateItpSectionJob;
use App\Models\Staff;
use Illuminate\Support\Facades\Gate;

it('builds the Anthropic client without SDK retries, so only the jobs retry', function () {
    config(['services.anthropic.key' => 'test-key']);

    expect(app(Client::class)->mergedOptions(null)->maxRetries)->toBe(0);
});

it('sends every generation job to the generation queue on the generation connection', function (object $job) {
    expect(app('queue.routes')->getConnection($job))->toBe(config('itp.generation.queue_connection'));
})->with([
    'generate' => fn () => new GenerateItpJob('01jgeneration'),
    'regenerate section' => fn () => new RegenerateItpSectionJob('01jgeneration', 1),
    'derive companions' => fn () => new DeriveItpCompanionsJob('01jgeneration'),
]);

it('gives the generation connection a retry_after above the jobs\' timeout, and keeps default at 90s', function () {
    expect(config('queue.connections.redis.retry_after'))->toBe(90)
        ->and(config('queue.connections.redis-generation.retry_after'))->toBeGreaterThan(600)
        ->and(config('horizon.defaults.supervisor-generation.connection'))->toBe('redis-generation')
        ->and(config('horizon.defaults.supervisor-generation.queue'))->toBe(['generation']);
});

it('lets only the owner edit prompts and see costs', function (string $ability) {
    expect(Gate::forUser(Staff::factory()->owner()->make())->allows($ability))->toBeTrue()
        ->and(Gate::forUser(Staff::factory()->editor()->make())->allows($ability))->toBeFalse();
})->with(['managePrompts', 'viewCosts']);
