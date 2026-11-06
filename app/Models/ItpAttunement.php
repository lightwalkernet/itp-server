<?php

namespace App\Models;

use Database\Factories\ItpAttunementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A generation's attunement. Its version goes up by one with every regenerated segment.
 */
#[Fillable(['generation_id', 'version', 'settled_at'])]
class ItpAttunement extends Model
{
    /** @use HasFactory<ItpAttunementFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ItpGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(ItpGeneration::class, 'generation_id');
    }

    /**
     * Every segment ever written, superseded ones included.
     *
     * @return HasMany<ItpAttunementSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(ItpAttunementSegment::class, 'attunement_id');
    }

    /**
     * The segments that make up the attunement now, in order.
     *
     * @return HasMany<ItpAttunementSegment, $this>
     */
    public function currentSegments(): HasMany
    {
        return $this->segments()->whereNull('superseded_at')->orderBy('order');
    }

    /**
     * The current segments joined into the finished attunement text.
     */
    public function text(): string
    {
        return $this->currentSegments->pluck('text')->implode("\n\n");
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'settled_at' => 'datetime',
        ];
    }
}
