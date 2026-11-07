<?php

namespace App\Livewire\Studio;

use App\Enums\ItpAttunementPart;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Everything one generation went through, for prompt iteration: the intake answers (decrypted
 * through the model casts), the follow-up rounds, what each stage derived, the attunement section
 * by section (current lines, every flag with the line it pinpointed, its proposed lines and what
 * the user kept, and the superseded and rejected lines), the corrections folded in from the
 * flags, the companions, the title, every AI call with its cost and prompt versions (the stage's
 * and the method core's), and the last error, which keeps the raw output of an unusable response.
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
            'flags' => fn ($query) => $query->orderBy('id'),
            'flags.segment',
            'companions',
            'aiUsages' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'aiUsages.promptVersion:id,stage,version',
            'aiUsages.methodCoreVersion:id,stage,version',
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
            'sections' => $this->sections($generation),
            'totalCost' => (float) $generation->aiUsages->sum('cost_usd'),
            'canOpenPrompts' => auth('studio')->user()?->can('managePrompts') ?? false,
        ])->title('Generation '.$generation->created_at->format('j M Y, H:i'));
    }

    /**
     * Every line ever written and every flag, by section, in part order. A flag from before v2
     * goes with its line's section.
     *
     * @return list<array{part: int, label: ?string, current: Collection<int, ItpAttunementSegment>, flags: Collection<int, ItpSegmentFlag>, proposals: Collection<int, Collection<int, ItpAttunementSegment>>, earlier: Collection<int, ItpAttunementSegment>}>
     */
    protected function sections(ItpGeneration $generation): array
    {
        $segments = $generation->attunement?->segments ?? new Collection;
        $flags = $generation->flags->groupBy(fn (ItpSegmentFlag $flag) => $flag->part ?? $flag->segment?->sectionPart() ?? ItpAttunementPart::fallback()->value);
        $bySection = $segments->groupBy(fn (ItpAttunementSegment $segment) => $segment->sectionPart());

        return $bySection->keys()->merge($flags->keys())->unique()->sort()->values()
            ->map(function (int $part) use ($bySection, $flags, $segments): array {
                $lines = $bySection->get($part, new Collection);
                $sectionFlags = $flags->get($part, new Collection);

                return [
                    'part' => $part,
                    'label' => ItpAttunementPart::tryFrom($part)?->label(),
                    'current' => $lines->filter(fn (ItpAttunementSegment $segment) => $segment->isCurrent())->values(),
                    'flags' => $sectionFlags,
                    'proposals' => $sectionFlags->mapWithKeys(fn (ItpSegmentFlag $flag) => [
                        $flag->id => $segments->whereIn('id', $flag->resulting_segment_ids ?? array_filter([$flag->resulting_segment_id]))->sortBy('order')->values(),
                    ]),
                    'earlier' => $lines->filter(fn (ItpAttunementSegment $segment) => $segment->isSuperseded() && ! $segment->isRejected())->values(),
                ];
            })
            ->all();
    }
}
