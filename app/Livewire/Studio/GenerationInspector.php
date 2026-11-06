<?php

namespace App\Livewire\Studio;

use App\Models\ItpGeneration;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Everything one generation went through, for prompt iteration: the intake answers (decrypted
 * through the model casts), the follow-up rounds, what each stage derived, every attunement
 * segment ever written (superseded ones included) with its flags, the companions, the title,
 * every AI call with its cost and prompt version, and the last error.
 */
#[Layout('layouts.studio')]
class GenerationInspector extends Component
{
    public ItpGeneration $generation;

    public function mount(ItpGeneration $generation): void
    {
        $this->generation = $generation;
    }

    public function render(): View
    {
        $generation = $this->generation->load([
            'user:id,email',
            'intake',
            'followups' => fn ($query) => $query->orderBy('round')->orderBy('id'),
            'attunement.segments' => fn ($query) => $query->orderBy('order')->orderBy('version')->orderBy('id'),
            'attunement.segments.flags' => fn ($query) => $query->orderBy('id'),
            'companions',
            'aiUsages' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'aiUsages.promptVersion:id,stage,version',
        ]);

        return view('livewire.studio.generation-inspector', [
            'generation' => $generation,
            'intakeAnswers' => $generation->intake === null ? [] : [
                'Display name' => $generation->intake->display_name,
                'Address style' => $generation->intake->address_style->value,
                'Q1 Dedication' => $generation->intake->q1_dedication,
                'Q2 Challenge' => $generation->intake->q2_challenge,
                'Q3 Old story' => $generation->intake->q3_old_story,
                'Q3 Phrase' => $generation->intake->q3_phrase,
                'Q3 Protective' => $generation->intake->q3_protective,
                'Q4 Vision' => $generation->intake->q4_vision,
                'Q4 Scene' => $generation->intake->q4_scene,
                'Q5 Soulful' => $generation->intake->q5_soulful,
                'Avoid' => $generation->intake->avoid,
            ],
            'followupRounds' => $generation->followups->groupBy('round'),
            'totalCost' => (float) $generation->aiUsages->sum('cost_usd'),
            'canOpenPrompts' => auth('studio')->user()?->can('managePrompts') ?? false,
        ])->title('Generation '.$generation->created_at->format('j M Y, H:i'));
    }
}
