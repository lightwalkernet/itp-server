<?php

use App\Ai\TokenUsage;
use App\Ai\UsageCost;

beforeEach(function () {
    $this->cost = new UsageCost([
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
    ]);
});

it('prices each kind of token at its own rate per million', function (TokenUsage $usage, float $expected) {
    expect($this->cost->calculate('claude-opus-5-5', $usage))->toBe($expected);
})->with([
    'input' => [new TokenUsage(input: 1_000_000), 4.0],
    'output' => [new TokenUsage(output: 1_000_000), 20.0],
    'cache write' => [new TokenUsage(cacheWrite: 1_000_000), 5.0],
    'cache read' => [new TokenUsage(cacheRead: 1_000_000), 0.2],
    'all together' => [new TokenUsage(input: 1200, output: 300, cacheWrite: 4000, cacheRead: 10_000), 0.0328],
]);

it('rounds to six decimal places', function () {
    expect($this->cost->calculate('claude-haiku-5-5', new TokenUsage(input: 7, output: 3)))->toBe(0.000002);
});

it('prices a dated model id as its alias', function () {
    expect($this->cost->calculate('claude-opus-5-5-20261101', new TokenUsage(input: 1_000_000)))->toBe(4.0);
});

it('returns null for a model without a price', function () {
    expect($this->cost->calculate('claude-unknown-1', new TokenUsage(input: 1000)))->toBeNull()
        ->and($this->cost->calculate('claude-opus-5-50', new TokenUsage(input: 1000)))->toBeNull();
});
