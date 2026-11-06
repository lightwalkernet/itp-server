<?php

namespace App\Models;

use App\Enums\ItpAddressStyle;
use Database\Factories\ItpIntakeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Itp\Core\Models\User;

/**
 * A user's intake answers. Server-only, and encrypted at rest (platform.md §9).
 */
#[Fillable([
    'user_id',
    'display_name',
    'address_style',
    'q1_dedication',
    'q2_challenge',
    'q3_old_story',
    'q3_phrase',
    'q3_protective',
    'q4_vision',
    'q4_scene',
    'q5_soulful',
    'avoid',
])]
class ItpIntake extends Model
{
    /** @use HasFactory<ItpIntakeFactory> */
    use HasFactory, HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<ItpGeneration, $this>
     */
    public function generation(): HasOne
    {
        return $this->hasOne(ItpGeneration::class, 'intake_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address_style' => ItpAddressStyle::class,
            'display_name' => 'encrypted',
            'q1_dedication' => 'encrypted',
            'q2_challenge' => 'encrypted',
            'q3_old_story' => 'encrypted',
            'q3_phrase' => 'encrypted',
            'q3_protective' => 'encrypted',
            'q4_vision' => 'encrypted',
            'q4_scene' => 'encrypted',
            'q5_soulful' => 'encrypted',
            'avoid' => 'encrypted',
        ];
    }
}
