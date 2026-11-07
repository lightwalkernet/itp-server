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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Itp\Core\Models\Itp;
use Itp\Core\Models\User;

/**
 * One run of the pipeline for one intake, from queued to complete. Server-only: the app sees it
 * only through the API, and gets the finished ITP (`itp_id`) through sync.
 *
 * `prompt_versions` maps each stage that ran to the prompt version it used, and `method_core` to
 * the method-core version the voice stages ran with. `extraction` and `derived_slots` hold the
 * extraction and reversal stages' results whole, in the prompt library's shapes. `corrections`
 * is every flag's feedback, oldest first, with the version of the section the user kept once they
 * choose (`recordCorrection()`, `recordChoice()`).
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
     * The open flag on the section being rewritten, if any.
     */
    public function openFlag(): ?ItpSegmentFlag
    {
        return $this->flags()->where('status', ItpSegmentFlagStatus::Open)->latest('id')->first();
    }

    /**
     * The flags whose rewritten section is waiting for the user's choice, by part (at most one
     * per section), with their proposed lines.
     *
     * @return Collection<int, ItpSegmentFlag>
     */
    public function flagsAwaitingChoice(): Collection
    {
        return $this->flags()
            ->whereNotNull('part')
            ->where('status', ItpSegmentFlagStatus::Regenerated)
            ->whereNull('choice')
            ->with('proposedSegments')
            ->orderBy('id')
            ->get()
            ->keyBy('part');
    }

    /**
     * The flag whose rewrite of this section is waiting for the user's choice, if any.
     */
    public function flagAwaitingChoice(int $part): ?ItpSegmentFlag
    {
        return $this->flagsAwaitingChoice()->get($part);
    }

    /**
     * Whether any section (or this one) has a rewrite waiting for the user's choice.
     */
    public function hasProposalWaiting(?int $part = null): bool
    {
        return $part === null ? $this->flagsAwaitingChoice()->isNotEmpty() : $this->flagAwaitingChoice($part) !== null;
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
     * stage (Breathe 11, Quantum Whys, title) keep honouring it: the section's part and its lines
     * as flagged, the line the user pointed to, and what they said. Safe to call again for the
     * same flag, as a retried job does. `recordChoice()` adds what they kept.
     */
    public function recordCorrection(ItpSegmentFlag $flag): void
    {
        $section = $this->attunement?->sectionSegments($flag->part)->pluck('text')->implode("\n");

        $this->changeCorrections(fn (SupportCollection $corrections) => $corrections->contains('flag_id', $flag->id)
            ? $corrections
            : $corrections->push([
                'flag_id' => $flag->id,
                'part' => $flag->part,
                'section' => $section,
                'line' => $flag->segment?->text,
                'explanation' => $flag->user_explanation,
                'requested_change' => $flag->requested_change,
            ]));
    }

    /**
     * Note in the flag's correction which version of the section the user kept.
     */
    public function recordChoice(ItpSegmentFlag $flag): void
    {
        $this->changeCorrections(fn (SupportCollection $corrections) => $corrections->map(
            fn (array $correction) => ($correction['flag_id'] ?? null) === $flag->id
                ? [...$correction, 'choice' => $flag->choice?->value]
                : $correction,
        ));
    }

    /**
     * Change the stored corrections from the row as it is now, locked, so a choice and a
     * regeneration finishing at the same moment never lose each other's change.
     *
     * @param  callable(SupportCollection<int, array<string, mixed>>): SupportCollection<int, array<string, mixed>>  $change
     */
    protected function changeCorrections(callable $change): void
    {
        DB::transaction(function () use ($change): void {
            $stored = static::query()->lockForUpdate()->findOrFail($this->id)->corrections ?? [];

            $this->update(['corrections' => $change(collect($stored))->values()->all()]);
        });
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
