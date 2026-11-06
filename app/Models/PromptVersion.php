<?php

namespace App\Models;

use App\Enums\ItpStage;
use Database\Factories\PromptVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A version of one stage's system prompt, or of the method core, edited in Studio. Exactly one version per stage is
 * active (`activated_at` set); `activate()` is the only way to change which.
 */
#[Fillable(['stage', 'version', 'body', 'notes', 'created_by_staff_id'])]
class PromptVersion extends Model
{
    /** @use HasFactory<PromptVersionFactory> */
    use HasFactory;

    /**
     * A new version without a number gets the stage's next one.
     */
    protected static function booted(): void
    {
        static::creating(function (PromptVersion $promptVersion): void {
            $promptVersion->version ??= static::nextVersionFor($promptVersion->stage);
        });
    }

    public static function activeFor(ItpStage $stage): ?self
    {
        return static::query()->where('stage', $stage)->whereNotNull('activated_at')->first();
    }

    public static function nextVersionFor(ItpStage $stage): int
    {
        return (int) static::query()->where('stage', $stage)->max('version') + 1;
    }

    /**
     * Make this the stage's live prompt, retiring whichever version was live.
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            static::query()->where('stage', $this->stage)->lockForUpdate()->get();

            static::query()
                ->where('stage', $this->stage)
                ->whereKeyNot($this->getKey())
                ->whereNotNull('activated_at')
                ->update(['activated_at' => null]);

            $this->forceFill(['activated_at' => now()])->save();
        });
    }

    public function isActive(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    /**
     * The AI calls that ran with this version as their stage prompt.
     *
     * @return HasMany<AiUsage, $this>
     */
    public function aiUsages(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    /**
     * The AI calls that ran with this version as their method core.
     *
     * @return HasMany<AiUsage, $this>
     */
    public function methodCoreUsages(): HasMany
    {
        return $this->hasMany(AiUsage::class, 'method_core_version_id');
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
            'version' => 'integer',
            'activated_at' => 'datetime',
        ];
    }
}
