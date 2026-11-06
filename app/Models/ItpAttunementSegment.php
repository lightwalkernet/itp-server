<?php

namespace App\Models;

use Database\Factories\ItpAttunementSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One breath-shaped line of an attunement. Regeneration supersedes a segment rather than
 * deleting it, so its flags keep pointing at the text the user flagged.
 */
#[Fillable(['attunement_id', 'order', 'text', 'version', 'superseded_at'])]
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

    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
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
            'version' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }
}
