<?php

namespace App\Models;

use App\Enums\ItpStage;
use Database\Factories\AiUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI call: what it cost, which model and prompt version ran, and how long it took.
 */
#[Fillable([
    'generation_id',
    'stage',
    'provider',
    'model',
    'input_tokens',
    'output_tokens',
    'cache_write_tokens',
    'cache_read_tokens',
    'cost_usd',
    'prompt_version_id',
    'duration_ms',
])]
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<ItpGeneration, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(ItpGeneration::class, 'generation_id');
    }

    /**
     * @return BelongsTo<PromptVersion, $this>
     */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => ItpStage::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'duration_ms' => 'integer',
        ];
    }
}
