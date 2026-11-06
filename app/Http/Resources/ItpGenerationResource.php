<?php

namespace App\Http\Resources;

use App\Enums\ItpCompanionType;
use App\Enums\ItpStatus;
use App\Models\ItpAttunementSegment;
use App\Models\ItpFollowup;
use App\Models\ItpGeneration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Generation object of the step 6 contract. Keys that don't apply to the status are absent:
 *
 * - `followups` (the open round) while awaiting follow-up answers
 * - `attunement` (current segments) from awaiting resonance on; while regenerating, the flagged
 *   segment carries `regenerating: true`
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
     * @return array{attunement?: array{id: int, version: int, segments: list<array<string, mixed>>}}
     */
    protected function attunementWhenWritten(): array
    {
        $statuses = [ItpStatus::AwaitingResonance, ItpStatus::Regenerating, ItpStatus::DerivingCompanions, ItpStatus::Complete];

        if (! in_array($this->status, $statuses, true) || $this->attunement === null) {
            return [];
        }

        $regeneratingSegmentId = $this->status === ItpStatus::Regenerating ? $this->openFlag()?->segment_id : null;

        return [
            'attunement' => [
                'id' => $this->attunement->id,
                'version' => $this->attunement->version,
                'segments' => $this->attunement->currentSegments->map(fn (ItpAttunementSegment $segment) => [
                    'id' => $segment->id,
                    'order' => $segment->order,
                    'text' => $segment->text,
                    ...($segment->id === $regeneratingSegmentId ? ['regenerating' => true] : []),
                ])->all(),
            ],
        ];
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
