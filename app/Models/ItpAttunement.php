<?php

namespace App\Models;

use Database\Factories\ItpAttunementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;

/**
 * A generation's attunement: its current lines, grouped into sections by SHAPE part. Its version
 * goes up by one every time the user keeps a rewritten section.
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
     * The segments that make up the attunement now, in order: neither superseded nor a proposal
     * waiting for the user's choice.
     *
     * @return HasMany<ItpAttunementSegment, $this>
     */
    public function currentSegments(): HasMany
    {
        return $this->segments()->whereNull('superseded_at')->whereNull('proposed_by_flag_id')->orderBy('order');
    }

    /**
     * The current segments grouped into sections, keyed by part, in part order. An attunement
     * written before v2 is one section, part 1.
     *
     * @return SupportCollection<int, Collection<int, ItpAttunementSegment>>
     */
    public function sections(): SupportCollection
    {
        return $this->currentSegments
            ->groupBy(fn (ItpAttunementSegment $segment) => $segment->sectionPart())
            ->toBase()
            ->map(fn (Collection $segments) => $segments->values())
            ->sortKeys();
    }

    /**
     * The current segments of one section, in order (empty when there is no such section).
     *
     * @return Collection<int, ItpAttunementSegment>
     */
    public function sectionSegments(int $part): Collection
    {
        return $this->sections()->get($part, new Collection);
    }

    public function hasSection(int $part): bool
    {
        return $this->sectionSegments($part)->isNotEmpty();
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
