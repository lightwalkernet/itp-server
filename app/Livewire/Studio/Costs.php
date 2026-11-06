<?php

namespace App\Livewire\Studio;

use App\Enums\ItpStatus;
use App\Models\AiUsage;
use App\Models\ItpGeneration;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Itp\Core\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What the AI calls cost (`ai_usages`), so the owner can set subscription limits: totals,
 * breakdowns by stage, provider and model, the cost of a finished personal ITP, the top users
 * and a daily table. Costs are computed when each call is logged, from `itp.ai.prices`.
 * Days are UTC. Owner-only (`viewCosts`).
 */
#[Layout('layouts.studio')]
#[Title('Costs')]
class Costs extends Component
{
    /**
     * @var array<string, string>
     */
    public const array RANGES = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'all' => 'All time',
    ];

    #[Url]
    public string $range = '7d';

    /**
     * Cost, calls and tokens for each range.
     *
     * @return array<string, array{label: string, cost: float, calls: int, input: int, output: int, cache_write: int, cache_read: int}>
     */
    #[Computed]
    public function totals(): array
    {
        return collect(self::RANGES)->map(function (string $label, string $range) {
            $row = $this->usagesIn($range)->selectRaw($this->sums())->toBase()->first();

            return ['label' => $label, ...$this->numbers($row)];
        })->all();
    }

    /**
     * @return Collection<int, array{stage: string, calls: int, cost: float, average: float, input: int, output: int, cache_write: int, cache_read: int}>
     */
    #[Computed]
    public function byStage(): Collection
    {
        return $this->usagesIn($this->selectedRange())
            ->selectRaw('stage, '.$this->sums())
            ->groupBy('stage')
            ->orderByDesc('cost')
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['stage' => $row->stage, ...$this->numbers($row)]);
    }

    /**
     * @return Collection<int, array{provider: string, model: string, calls: int, cost: float, average: float, input: int, output: int, cache_write: int, cache_read: int}>
     */
    #[Computed]
    public function byModel(): Collection
    {
        return $this->usagesIn($this->selectedRange())
            ->selectRaw('provider, model, '.$this->sums())
            ->groupBy('provider', 'model')
            ->orderByDesc('cost')
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['provider' => $row->provider, 'model' => $row->model, ...$this->numbers($row)]);
    }

    /**
     * The full AI cost of each personal ITP completed in the range (every call its generation
     * made, flags and retries included): how many, the average and the median.
     *
     * @return array{count: int, average: ?float, median: ?float}
     */
    #[Computed]
    public function perCompletedItp(): array
    {
        $since = $this->since($this->selectedRange());

        $costs = ItpGeneration::query()
            ->where('status', ItpStatus::Complete)
            ->when($since, fn (Builder $query) => $query->where('completed_at', '>=', $since))
            ->withSum('aiUsages as total_cost', 'cost_usd')
            ->get(['id'])
            ->map(fn (ItpGeneration $generation) => (float) $generation->total_cost);

        return [
            'count' => $costs->count(),
            'average' => $costs->isEmpty() ? null : $costs->sum() / $costs->count(),
            'median' => $costs->isEmpty() ? null : (float) $costs->median(),
        ];
    }

    /**
     * The ten users whose generations cost the most in the range.
     *
     * @return Collection<int, array{email: string, generations: int, cost: float}>
     */
    #[Computed]
    public function topUsers(): Collection
    {
        $usages = (new AiUsage)->getTable();
        $generations = (new ItpGeneration)->getTable();
        $users = (new User)->getTable();
        $since = $this->since($this->selectedRange());

        return DB::table($usages)
            ->join($generations, "{$generations}.id", '=', "{$usages}.generation_id")
            ->join($users, "{$users}.id", '=', "{$generations}.user_id")
            ->when($since, fn ($query) => $query->where("{$usages}.created_at", '>=', $since))
            ->groupBy("{$users}.id", "{$users}.email")
            ->selectRaw("{$users}.email as email, count(distinct {$usages}.generation_id) as generations, sum({$usages}.cost_usd) as cost")
            ->orderByDesc('cost')
            ->limit(10)
            ->get()
            ->map(fn (object $row) => ['email' => $row->email, 'generations' => (int) $row->generations, 'cost' => (float) $row->cost]);
    }

    /**
     * @return Collection<int, array{day: string, calls: int, cost: float, average: float, input: int, output: int, cache_write: int, cache_read: int}>
     */
    #[Computed]
    public function daily(): Collection
    {
        return $this->usagesIn($this->selectedRange())
            ->selectRaw('date(created_at) as day, '.$this->sums())
            ->groupBy('day')
            ->orderByDesc('day')
            ->toBase()
            ->get()
            ->map(fn (object $row) => ['day' => $row->day, ...$this->numbers($row)]);
    }

    public function selectedRange(): string
    {
        return array_key_exists($this->range, self::RANGES) ? $this->range : '7d';
    }

    public function render(): View
    {
        return view('livewire.studio.costs', [
            'ranges' => self::RANGES,
            'prices' => config('itp.ai.prices'),
        ]);
    }

    /**
     * @return Builder<AiUsage>
     */
    protected function usagesIn(string $range): Builder
    {
        $since = $this->since($range);

        return AiUsage::query()->when($since, fn (Builder $query) => $query->where('created_at', '>=', $since));
    }

    /**
     * The start of a range: today and the days before it, from midnight UTC. All time has none.
     */
    protected function since(string $range): ?CarbonImmutable
    {
        $today = CarbonImmutable::now()->startOfDay();

        return match ($range) {
            'today' => $today,
            '7d' => $today->subDays(6),
            '30d' => $today->subDays(29),
            default => null,
        };
    }

    protected function sums(): string
    {
        return 'count(*) as calls, coalesce(sum(cost_usd), 0) as cost, coalesce(sum(input_tokens), 0) as input, '
            .'coalesce(sum(output_tokens), 0) as output, coalesce(sum(cache_write_tokens), 0) as cache_write, '
            .'coalesce(sum(cache_read_tokens), 0) as cache_read';
    }

    /**
     * @return array{calls: int, cost: float, average: float, input: int, output: int, cache_write: int, cache_read: int}
     */
    protected function numbers(?object $row): array
    {
        $calls = (int) ($row->calls ?? 0);
        $cost = (float) ($row->cost ?? 0);

        return [
            'calls' => $calls,
            'cost' => $cost,
            'average' => $calls > 0 ? $cost / $calls : 0.0,
            'input' => (int) ($row->input ?? 0),
            'output' => (int) ($row->output ?? 0),
            'cache_write' => (int) ($row->cache_write ?? 0),
            'cache_read' => (int) ($row->cache_read ?? 0),
        ];
    }
}
