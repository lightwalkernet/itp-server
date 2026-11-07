<?php

namespace App\Models;

use App\Enums\ItpSectionChoice;
use App\Enums\ItpSegmentFlagStatus;
use Database\Factories\ItpSegmentFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's feedback on one section of the attunement (`part`), kept as training signal whatever
 * they choose. `segment_id` is the line they pointed to, if any. The regeneration writes the
 * section's new lines as a proposal (`resulting_segment_ids`, in order) and the user then keeps
 * the new version or their original (`choice`, at `chosen_at`).
 *
 * Flags from before prompt library v2 are on one line (`segment_id`) with no part:
 * `resulting_segment_id` is that line's replacement, and there is no choice.
 */
#[Fillable([
    'generation_id',
    'part',
    'segment_id',
    'user_explanation',
    'requested_change',
    'status',
    'resulting_segment_id',
    'resulting_segment_ids',
    'choice',
    'chosen_at',
])]
class ItpSegmentFlag extends Model
{
    /** @use HasFactory<ItpSegmentFlagFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ItpGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(ItpGeneration::class, 'generation_id');
    }

    /**
     * The line the user pointed to (for a flag from before v2, the flagged line).
     *
     * @return BelongsTo<ItpAttunementSegment, $this>
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(ItpAttunementSegment::class, 'segment_id');
    }

    /**
     * @return BelongsTo<ItpAttunementSegment, $this>
     */
    public function resultingSegment(): BelongsTo
    {
        return $this->belongsTo(ItpAttunementSegment::class, 'resulting_segment_id');
    }

    /**
     * The proposed lines still waiting for the user's choice, in order.
     *
     * @return HasMany<ItpAttunementSegment, $this>
     */
    public function proposedSegments(): HasMany
    {
        return $this->hasMany(ItpAttunementSegment::class, 'proposed_by_flag_id')
            ->whereNull('superseded_at')
            ->orderBy('order');
    }

    /**
     * Every line the regeneration wrote for this flag, in order, whatever became of them.
     *
     * @return Collection<int, ItpAttunementSegment>
     */
    public function resultingSegments(): Collection
    {
        $ids = $this->resulting_segment_ids ?? [];

        return ItpAttunementSegment::query()
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (ItpAttunementSegment $segment) => array_search($segment->id, $ids, true))
            ->values();
    }

    /**
     * Whether its section's rewrite is written and waiting for the user's choice.
     */
    public function isAwaitingChoice(): bool
    {
        return $this->part !== null && $this->status === ItpSegmentFlagStatus::Regenerated && $this->choice === null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'part' => 'integer',
            'status' => ItpSegmentFlagStatus::class,
            'resulting_segment_ids' => 'array',
            'choice' => ItpSectionChoice::class,
            'chosen_at' => 'datetime',
        ];
    }
}
