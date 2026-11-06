<?php

namespace App\Models;

use App\Enums\ItpCompanionType;
use Database\Factories\ItpCompanionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A companion derived from the settled attunement.
 *
 * `content` is `[{order, text}]` for Breathe 11 and a list of "Why …?" strings for Quantum Whys,
 * the same shapes as the itp-core columns they end up in.
 */
#[Fillable(['generation_id', 'type', 'content', 'from_attunement_version'])]
class ItpCompanion extends Model
{
    /** @use HasFactory<ItpCompanionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ItpGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(ItpGeneration::class, 'generation_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ItpCompanionType::class,
            'content' => 'array',
            'from_attunement_version' => 'integer',
        ];
    }
}
