<?php

use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use App\Livewire\Studio\Costs;
use App\Models\AiUsage;
use App\Models\ItpGeneration;
use App\Models\Staff;
use Itp\Core\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 11, 7)->setTime(15, 0));

    $this->owner = Staff::factory()->owner()->create();

    $ann = User::factory()->create(['email' => 'ann@example.com']);
    $bob = User::factory()->create(['email' => 'bob@example.com']);
    $cat = User::factory()->create(['email' => 'cat@example.com']);

    $completedToday = ItpGeneration::factory()->forUser($ann)->withStatus(ItpStatus::Complete)->create(['completed_at' => now()]);
    $completedTwoDaysAgo = ItpGeneration::factory()->forUser($ann)->withStatus(ItpStatus::Complete)->create(['completed_at' => now()->subDays(2)]);
    $completedLastMonth = ItpGeneration::factory()->forUser($bob)->withStatus(ItpStatus::Complete)->create(['completed_at' => now()->subDays(20)]);
    $failed = ItpGeneration::factory()->forUser($cat)->withStatus(ItpStatus::Failed)->create();

    $usage = fn (ItpGeneration $generation, ItpStage $stage, string $model, float $cost, int $daysAgo) => AiUsage::factory()
        ->for($generation, 'generation')
        ->create(['stage' => $stage, 'model' => $model, 'cost_usd' => $cost, 'created_at' => now()->subDays($daysAgo)]);

    $usage($completedToday, ItpStage::Attunement, 'claude-opus-5-5', 0.10, 0);
    $usage($completedToday, ItpStage::Title, 'claude-sonnet-5-5', 0.20, 0);
    $usage($completedToday, ItpStage::Extraction, 'claude-haiku-5-5', 0.05, 3);
    $usage($completedTwoDaysAgo, ItpStage::Attunement, 'claude-opus-5-5', 0.65, 2);
    $usage($completedLastMonth, ItpStage::Attunement, 'claude-opus-5-5', 1.00, 20);
    $usage($failed, ItpStage::Attunement, 'claude-opus-5-5', 0.40, 0);
});

it('totals cost, calls and tokens for today, 7 days, 30 days and all time', function () {
    $totals = Livewire::actingAs($this->owner, 'studio')->test(Costs::class)->instance()->totals;

    expect(collect($totals)->map(fn (array $total) => [round($total['cost'], 6), $total['calls'], $total['input'], $total['output']])->all())->toBe([
        'today' => [0.7, 3, 3000, 1500],
        '7d' => [1.4, 5, 5000, 2500],
        '30d' => [2.4, 6, 6000, 3000],
        'all' => [2.4, 6, 6000, 3000],
    ]);
});

it('averages the full cost of each completed personal ITP, and gives the median', function (string $range, int $count, float $average, float $median) {
    $perItp = Livewire::actingAs($this->owner, 'studio')->test(Costs::class)->set('range', $range)->instance()->perCompletedItp;

    expect($perItp['count'])->toBe($count)
        ->and(round($perItp['average'], 6))->toBe($average)
        ->and(round($perItp['median'], 6))->toBe($median);
})->with([
    // 0.35 (including a call made three days before it completed) and 0.65.
    'last 7 days' => ['7d', 2, 0.5, 0.5],
    // 0.35, 0.65 and 1.00.
    'last 30 days' => ['30d', 3, 0.666667, 0.65],
]);

it('has no cost per ITP when none completed in the range', function () {
    $this->travel(40)->days();

    $perItp = Livewire::actingAs($this->owner, 'studio')->test(Costs::class)->set('range', '7d')->instance()->perCompletedItp;

    expect($perItp)->toBe(['count' => 0, 'average' => null, 'median' => null]);
});

it('breaks the range down by stage, by provider and model, by user and by day', function () {
    $costs = Livewire::actingAs($this->owner, 'studio')->test(Costs::class)->set('range', '7d')->instance();

    expect($costs->byStage->map(fn (array $row) => [$row['stage'], $row['calls'], round($row['cost'], 6)])->all())->toBe([
        ['attunement', 3, 1.15],
        ['title', 1, 0.2],
        ['extraction', 1, 0.05],
    ])->and($costs->byModel->map(fn (array $row) => [$row['provider'], $row['model'], round($row['cost'], 6)])->all())->toBe([
        ['anthropic', 'claude-opus-5-5', 1.15],
        ['anthropic', 'claude-sonnet-5-5', 0.2],
        ['anthropic', 'claude-haiku-5-5', 0.05],
    ])->and($costs->topUsers->map(fn (array $row) => [$row['email'], $row['generations'], round($row['cost'], 6)])->all())->toBe([
        ['ann@example.com', 2, 1.0],
        ['cat@example.com', 1, 0.4],
    ])->and($costs->daily->map(fn (array $row) => [$row['day'], round($row['cost'], 6)])->all())->toBe([
        ['2026-11-07', 0.7],
        ['2026-11-05', 0.65],
        ['2026-11-04', 0.05],
    ]);
});

it('shows the money as dollars and the price table it is computed from', function () {
    $this->actingAs($this->owner, 'studio')
        ->get(route('studio.costs', ['range' => '30d']))
        ->assertOk()
        ->assertSeeInOrder(['Today', '$0.70', 'Last 7 days', '$1.40', 'Last 30 days', '$2.40', 'All time', '$2.40'])
        ->assertSeeInOrder(['Average per ITP', '$0.6667', 'Median per ITP', '$0.6500'])
        ->assertSeeInOrder(['anthropic', 'claude-opus-5-5', '$2.15', '$0.5375'])
        ->assertSee('config/itp.php')
        ->assertSeeInOrder(['claude-haiku-5-5', '$0.10', '$0.50', '$0.125', '$0.01']);
});

it('falls back to the last 7 days for an unknown range', function () {
    $costs = Livewire::actingAs($this->owner, 'studio')->test(Costs::class)->set('range', 'forever')->instance();

    expect($costs->selectedRange())->toBe('7d')
        ->and($costs->byStage->sum('calls'))->toBe(5);
});

it('is owner-only', function () {
    $this->actingAs(Staff::factory()->editor()->create(), 'studio')->get(route('studio.costs'))->assertForbidden();
});

it('sends guests to the sign-in page', function () {
    $this->get(route('studio.costs'))->assertRedirect(route('studio.login'));
});
