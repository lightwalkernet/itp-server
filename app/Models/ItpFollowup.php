<?php

namespace App\Models;

use Database\Factories\ItpFollowupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A follow-up question in one round. `answered_at` closes it, even when the user skipped it.
 */
#[Fillable(['generation_id', 'round', 'question_text', 'answer_text', 'answered_at'])]
class ItpFollowup extends Model
{
    /** @use HasFactory<ItpFollowupFactory> */
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
            'round' => 'integer',
            'answer_text' => 'encrypted',
            'answered_at' => 'datetime',
        ];
    }
}
