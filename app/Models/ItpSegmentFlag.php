<?php

namespace App\Models;

use App\Enums\ItpSegmentFlagStatus;
use Database\Factories\ItpSegmentFlagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's feedback on one segment, kept as training signal after the segment is replaced.
 * `resulting_segment_id` is the flagged segment's replacement; `resulting_segment_ids` lists every
 * segment the regeneration wrote, in order (the replacement and at most one adjacent segment).
 */
#[Fillable(['generation_id', 'segment_id', 'user_explanation', 'requested_change', 'status', 'resulting_segment_id', 'resulting_segment_ids'])]
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ItpSegmentFlagStatus::class,
            'resulting_segment_ids' => 'array',
        ];
    }
}
