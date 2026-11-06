<?php

namespace App\Livewire\Studio;

use App\Models\ItpGeneration;
use App\Models\PromptVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every generation, newest first: who, where it is, what it cost and which prompt versions ran.
 */
#[Layout('layouts.studio')]
#[Title('Generations')]
class Generations extends Component
{
    use WithPagination;

    public function render(): View
    {
        $generations = $this->generations();

        return view('livewire.studio.generations', [
            'generations' => $generations,
            'promptVersions' => $this->promptVersionsFor($generations->getCollection()),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, ItpGeneration>
     */
    protected function generations(): LengthAwarePaginator
    {
        return ItpGeneration::query()
            ->with('user:id,email')
            ->withSum('aiUsages as total_cost', 'cost_usd')
            ->latest()
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * The prompt versions the listed generations ran with, keyed by id.
     *
     * @param  Collection<int, ItpGeneration>  $generations
     * @return Collection<int, PromptVersion>
     */
    protected function promptVersionsFor(Collection $generations): Collection
    {
        $ids = $generations->flatMap(fn (ItpGeneration $generation) => array_values($generation->prompt_versions ?? []))->unique();

        return PromptVersion::query()->whereKey($ids)->get(['id', 'stage', 'version'])->keyBy('id');
    }
}
