<?php

namespace App\Livewire\Studio;

use App\Enums\ItpStage;
use App\Models\PromptVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Every pipeline stage, what it is given and must return, and its live prompt version.
 * Owner-only (`managePrompts`).
 */
#[Layout('layouts.studio')]
#[Title('Prompts')]
class Prompts extends Component
{
    public function render(): View
    {
        return view('livewire.studio.prompts', [
            'stages' => $this->stages(),
        ]);
    }

    /**
     * @return Collection<int, array{stage: ItpStage, active: ?PromptVersion, latest: ?PromptVersion, versionCount: int}>
     */
    protected function stages(): Collection
    {
        $versions = PromptVersion::query()
            ->with('createdBy')
            ->orderByDesc('version')
            ->get(['id', 'stage', 'version', 'notes', 'created_by_staff_id', 'activated_at', 'created_at'])
            ->groupBy(fn (PromptVersion $version) => $version->stage->value);

        return collect(ItpStage::cases())->map(function (ItpStage $stage) use ($versions) {
            $stageVersions = $versions->get($stage->value, collect());

            return [
                'stage' => $stage,
                'active' => $stageVersions->first(fn (PromptVersion $version) => $version->isActive()),
                'latest' => $stageVersions->first(),
                'versionCount' => $stageVersions->count(),
            ];
        });
    }
}
