<?php

namespace App\Http\Resources;

use App\Enums\ItpCompanionType;
use App\Enums\ItpStatus;
use App\Models\ItpAttunementSegment;
use App\Models\ItpFollowup;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Generation object of the step 6 contract. Keys that don't apply to the status are absent:
 *
 * - `followups` (the open round) while awaiting follow-up answers
 * - `attunement` from awaiting resonance on: its current lines in sections, by part (an
 *   attunement from before v2 is one section, part 1). The section being rewritten has
 *   `regenerating: true`; one whose rewrite waits for the user's choice has its `proposal`; either
 *   names the line the user pointed to (`pinpointed_segment_id`)
 * - `companions`, `title` and `description` once complete
 * - `message` when deferred, blocked or failed
 *
 * @mixin ItpGeneration
 */
class ItpGenerationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->summary(),
            ...$this->followupsWhenAwaited(),
            ...$this->attunementWhenWritten(),
            ...$this->resultWhenComplete(),
            ...$this->messageWhenHeld(),
        ];
    }

    /**
     * The keys every Generation has; also the shape of each item in the index.
     *
     * @return array{id: string, status: string, created_at: mixed, updated_at: mixed, itp_id: ?string}
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'itp_id' => $this->itp_id,
        ];
    }

    /**
     * @return array{followups?: list<array{id: int, round: int, question_text: string}>}
     */
    protected function followupsWhenAwaited(): array
    {
        if ($this->status !== ItpStatus::AwaitingFollowup) {
            return [];
        }

        return [
            'followups' => $this->openFollowups()->map(fn (ItpFollowup $followup) => [
                'id' => $followup->id,
                'round' => $followup->round,
                'question_text' => $followup->question_text,
            ])->all(),
        ];
    }

    /**
     * @return array{attunement?: array{id: int, version: int, sections: list<array<string, mixed>>}}
     */
    protected function attunementWhenWritten(): array
    {
        $statuses = [ItpStatus::AwaitingResonance, ItpStatus::Regenerating, ItpStatus::DerivingCompanions, ItpStatus::Complete];

        if (! in_array($this->status, $statuses, true) || $this->attunement === null) {
            return [];
        }

        $rewriting = $this->status === ItpStatus::Regenerating ? $this->openFlag() : null;
        $awaitingChoice = $this->flagsAwaitingChoice();

        return [
            'attunement' => [
                'id' => $this->attunement->id,
                'version' => $this->attunement->version,
                'sections' => $this->attunement->sections()
                    ->map(fn (Collection $segments, int $part) => $this->section($part, $segments, $rewriting?->part === $part ? $rewriting : null, $awaitingChoice->get($part)))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * One section: its current lines, whether it is being rewritten, the rewrite waiting for the
     * user's choice, and the line the user pointed to in the flag being handled.
     *
     * @param  Collection<int, ItpAttunementSegment>  $segments
     * @return array{part: int, segments: list<array{id: int, order: int, text: string}>, regenerating: bool, proposal: ?array{flag_id: int, segments: list<array{id: int, order: int, text: string}>}, pinpointed_segment_id: ?int}
     */
    protected function section(int $part, Collection $segments, ?ItpSegmentFlag $rewriting, ?ItpSegmentFlag $awaitingChoice): array
    {
        return [
            'part' => $part,
            'segments' => $this->lines($segments),
            'regenerating' => $rewriting !== null,
            'proposal' => $awaitingChoice === null ? null : [
                'flag_id' => $awaitingChoice->id,
                'segments' => $this->lines($awaitingChoice->proposedSegments),
            ],
            'pinpointed_segment_id' => ($rewriting ?? $awaitingChoice)?->segment_id,
        ];
    }

    /**
     * @param  Collection<int, ItpAttunementSegment>  $segments
     * @return list<array{id: int, order: int, text: string}>
     */
    protected function lines(Collection $segments): array
    {
        return $segments->map(fn (ItpAttunementSegment $segment) => [
            'id' => $segment->id,
            'order' => $segment->order,
            'text' => $segment->text,
        ])->values()->all();
    }

    /**
     * @return array{companions?: array{breathe_11: list<array{order: int, text: string}>, quantum_whys: list<string>}, title?: ?string, description?: ?string}
     */
    protected function resultWhenComplete(): array
    {
        if ($this->status !== ItpStatus::Complete) {
            return [];
        }

        return [
            'companions' => [
                'breathe_11' => $this->companion(ItpCompanionType::Breathe11)?->content ?? [],
                'quantum_whys' => $this->companion(ItpCompanionType::QuantumWhys)?->content ?? [],
            ],
            'title' => $this->title,
            'description' => $this->description,
        ];
    }

    /**
     * @return array{message?: string}
     */
    protected function messageWhenHeld(): array
    {
        $message = $this->status->userMessage();

        return $message === null ? [] : ['message' => $message];
    }
}
