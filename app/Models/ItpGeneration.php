<?php

namespace App\Models;

use App\Ai\StagePrompt;
use App\Enums\ItpCompanionType;
use App\Enums\ItpSafetyRoute;
use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStage;
use App\Enums\ItpStatus;
use Database\Factories\ItpGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Itp\Core\Models\Itp;
use Itp\Core\Models\User;

/**
 * One run of the pipeline for one intake, from queued to complete. Server-only: the app sees it
 * only through the API, and gets the finished ITP (`itp_id`) through sync.
 *
 * `prompt_versions` maps each stage that ran to the prompt version it used, and `method_core` to
 * the method-core version the voice stages ran with. `extraction` and `derived_slots` hold the
 * extraction and reversal stages' results whole, in the prompt library's shapes. `corrections`
 * is every flag's feedback, oldest first (`recordCorrection()`).
 */
#[Fillable([
    'user_id',
    'intake_id',
    'status',
    'safety_route',
    'safety_categories',
    'safety_confidence',
    'safety_reason',
    'extraction',
    'derived_slots',
    'corrections',
    'followup_rounds',
    'prompt_versions',
    'itp_id',
    'title',
    'description',
    'completed_at',
    'last_error',
])]
class ItpGeneration extends Model
{
    /** @use HasFactory<ItpGenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ItpIntake, $this>
     */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(ItpIntake::class, 'intake_id');
    }

    /**
     * @return HasOne<ItpAttunement, $this>
     */
    public function attunement(): HasOne
    {
        return $this->hasOne(ItpAttunement::class, 'generation_id');
    }

    /**
     * The attunement's current segments. `{segment}` route bindings are scoped through this, so
     * a superseded segment or one from another generation is a 404.
     *
     * @return HasManyThrough<ItpAttunementSegment, ItpAttunement, $this>
     */
    public function segments(): HasManyThrough
    {
        return $this->hasManyThrough(ItpAttunementSegment::class, ItpAttunement::class, 'generation_id', 'attunement_id')
            ->whereNull('itp_attunement_segments.superseded_at');
    }

    /**
     * @return HasMany<ItpFollowup, $this>
     */
    public function followups(): HasMany
    {
        return $this->hasMany(ItpFollowup::class, 'generation_id');
    }

    /**
     * @return HasMany<ItpSegmentFlag, $this>
     */
    public function flags(): HasMany
    {
        return $this->hasMany(ItpSegmentFlag::class, 'generation_id');
    }

    /**
     * @return HasMany<ItpCompanion, $this>
     */
    public function companions(): HasMany
    {
        return $this->hasMany(ItpCompanion::class, 'generation_id');
    }

    /**
     * @return HasMany<AiUsage, $this>
     */
    public function aiUsages(): HasMany
    {
        return $this->hasMany(AiUsage::class, 'generation_id');
    }

    /**
     * The finished itp-core ITP, once complete.
     *
     * @return BelongsTo<Itp, $this>
     */
    public function itp(): BelongsTo
    {
        return $this->belongsTo(Itp::class);
    }

    /**
     * The questions of the latest round that the user hasn't answered yet.
     *
     * @return Collection<int, ItpFollowup>
     */
    public function openFollowups(): Collection
    {
        return $this->followups()
            ->where('round', $this->followup_rounds)
            ->whereNull('answered_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every answered (or skipped) question, oldest round first.
     *
     * @return Collection<int, ItpFollowup>
     */
    public function answeredFollowups(): Collection
    {
        return $this->followups()
            ->whereNotNull('answered_at')
            ->orderBy('round')
            ->orderBy('id')
            ->get();
    }

    /**
     * The open flag on the segment being regenerated, if any.
     */
    public function openFlag(): ?ItpSegmentFlag
    {
        return $this->flags()->where('status', ItpSegmentFlagStatus::Open)->latest('id')->first();
    }

    public function companion(ItpCompanionType $type): ?ItpCompanion
    {
        return $this->companions->firstWhere('type', $type);
    }

    /**
     * Note which prompt versions a call ran with: the stage's own, and the method core's if it
     * used one.
     */
    public function recordPromptVersions(StagePrompt $prompt): void
    {
        $this->forceFill([
            'prompt_versions' => [
                ...($this->prompt_versions ?? []),
                $prompt->stage->value => $prompt->stagePrompt->id,
                ...($prompt->methodCore === null ? [] : [ItpStage::MethodCore->value => $prompt->methodCore->id]),
            ],
        ])->save();
    }

    /**
     * Fold a flag's feedback into the stored corrections, so the regeneration and every later
     * stage (Breathe 11, Quantum Whys, title) keep honouring it. Safe to call again for the same
     * flag, as a retried job does.
     */
    public function recordCorrection(ItpSegmentFlag $flag): void
    {
        $corrections = collect($this->corrections ?? []);

        if ($corrections->contains('flag_id', $flag->id)) {
            return;
        }

        $this->update([
            'corrections' => $corrections->push([
                'flag_id' => $flag->id,
                'line' => $flag->segment?->text,
                'explanation' => $flag->user_explanation,
                'requested_change' => $flag->requested_change,
            ])->values()->all(),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ItpStatus::class,
            'safety_route' => ItpSafetyRoute::class,
            'safety_categories' => 'array',
            'safety_confidence' => 'float',
            'extraction' => 'array',
            'derived_slots' => 'array',
            'corrections' => 'array',
            'followup_rounds' => 'integer',
            'prompt_versions' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
