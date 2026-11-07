<?php

namespace App\Models;

use App\Enums\ItpAttunementPart;
use Database\Factories\ItpAttunementSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One breath-shaped line of an attunement, in one SHAPE part (`part`; null before prompt
 * library v2, counted as part 1). Lines are never deleted: a replaced line gets `superseded_at`,
 * so flags keep pointing at the text the user flagged.
 *
 * A line is one of:
 * - current: not superseded, not proposed; `order` is its position, 1 to N across the attunement
 * - proposed: written by a section regeneration (`proposed_by_flag_id`) and waiting for the
 *   user's choice; `order` is its position within the proposal, from 1
 * - superseded: replaced by a chosen proposal
 * - rejected: a proposal the user didn't keep (superseded, with its proposing flag)
 */
#[Fillable(['attunement_id', 'order', 'part', 'text', 'version', 'proposed_by_flag_id', 'superseded_at'])]
class ItpAttunementSegment extends Model
{
    /** @use HasFactory<ItpAttunementSegmentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ItpAttunement, $this>
     */
    public function attunement(): BelongsTo
    {
        return $this->belongsTo(ItpAttunement::class, 'attunement_id');
    }

    /**
     * @return HasMany<ItpSegmentFlag, $this>
     */
    public function flags(): HasMany
    {
        return $this->hasMany(ItpSegmentFlag::class, 'segment_id');
    }

    /**
     * The flag whose regeneration proposed this line, while it waits for a choice or once rejected.
     *
     * @return BelongsTo<ItpSegmentFlag, $this>
     */
    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(ItpSegmentFlag::class, 'proposed_by_flag_id');
    }

    /**
     * The section this line belongs to: its part, or part 1 for a line written before v2.
     */
    public function sectionPart(): int
    {
        return ItpAttunementPart::numberOf($this->part);
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function isCurrent(): bool
    {
        return ! $this->isSuperseded() && $this->proposed_by_flag_id === null;
    }

    public function isProposed(): bool
    {
        return ! $this->isSuperseded() && $this->proposed_by_flag_id !== null;
    }

    public function isRejected(): bool
    {
        return $this->isSuperseded() && $this->proposed_by_flag_id !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'part' => 'integer',
            'proposed_by_flag_id' => 'integer',
            'version' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }
}
