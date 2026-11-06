<?php

namespace App\Services\Itp;

use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpIntake;
use Illuminate\Support\Collection;

/**
 * Builds the user message for a stage: labelled `## Section` blocks, named in the prompt
 * library's vocabulary ("Intake", "Slots", "Derived slots", "Follow-up answers", "Settled
 * attunement", "Corrections", "Avoid", "Address style", "Display name"). Structured data is
 * pretty-printed JSON. Empty sections are left out.
 *
 * Segment ids shown to the model are the segments' `order` (1, 2, 3 …), never database ids.
 */
class StageInput
{
    /**
     * The keys of the extraction result that are its verdict, not slots.
     *
     * @var list<string>
     */
    public const array EXTRACTION_VERDICT_KEYS = ['sufficient', 'gaps'];

    /**
     * @param  array<string, string|null>  $sections  Heading => body
     */
    public static function sections(array $sections): string
    {
        return collect($sections)
            ->filter(fn (?string $body) => filled($body))
            ->map(fn (string $body, string $heading) => "## {$heading}\n\n".trim($body))
            ->implode("\n\n");
    }

    /**
     * @param  array<string, string|null>  $fields  Label => value
     */
    public static function fields(array $fields): string
    {
        return collect($fields)
            ->filter(fn (?string $value) => filled($value))
            ->map(fn (string $value, string $label) => "{$label}: {$value}")
            ->implode("\n\n");
    }

    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Every intake answer, labelled as the questionnaire asks it.
     */
    public static function intake(ItpIntake $intake): string
    {
        return static::fields([
            'Display name' => $intake->display_name,
            'Address style' => $intake->address_style->value,
            'Q1 Dedication' => $intake->q1_dedication,
            'Q2 Challenge' => $intake->q2_challenge,
            'Q3 Old story' => $intake->q3_old_story,
            'Q3 Phrase' => $intake->q3_phrase,
            'Q3 Protective' => $intake->q3_protective,
            'Q4 Vision' => $intake->q4_vision,
            'Q4 Scene' => $intake->q4_scene,
            'Q5 Soulful' => $intake->q5_soulful,
            'Avoid' => $intake->avoid,
        ]);
    }

    /**
     * Every answered follow-up question, round by round. A skipped question stays in, marked as
     * skipped, so later stages know it was asked.
     */
    public static function followupAnswers(ItpGeneration $generation): ?string
    {
        $followups = $generation->answeredFollowups();

        if ($followups->isEmpty()) {
            return null;
        }

        return $followups
            ->groupBy('round')
            ->map(fn (Collection $round, int $number) => "Round {$number}\n\n".$round
                ->map(fn ($followup) => "Q: {$followup->question_text}\nA: ".($followup->answer_text ?? '(skipped)'))
                ->implode("\n\n"))
            ->implode("\n\n");
    }

    /**
     * The extraction's literal slots (everything but `sufficient` and `gaps`), as JSON.
     *
     * @param  array<string, mixed>|null  $extraction
     */
    public static function slots(?array $extraction): ?string
    {
        $slots = collect($extraction ?? [])->except(self::EXTRACTION_VERDICT_KEYS);

        return $slots->isEmpty() ? null : static::json($slots->all());
    }

    /**
     * The extraction's gaps, `[{slot, why_thin}]`, as JSON.
     *
     * @param  array<string, mixed>|null  $extraction
     */
    public static function gaps(?array $extraction): ?string
    {
        $gaps = $extraction['gaps'] ?? [];

        return $gaps === [] ? null : static::json($gaps);
    }

    /**
     * The reversal's derived slots, as JSON.
     *
     * @param  array<string, mixed>|null  $derivedSlots
     */
    public static function derivedSlots(?array $derivedSlots): ?string
    {
        return blank($derivedSlots) ? null : static::json($derivedSlots);
    }

    /**
     * The user's corrections so far, oldest first, as JSON (`ItpGeneration::recordCorrection()`).
     */
    public static function corrections(ItpGeneration $generation): ?string
    {
        $corrections = collect($generation->corrections ?? [])
            ->map(fn (array $correction) => collect($correction)->except('flag_id')->all())
            ->values();

        return $corrections->isEmpty() ? null : static::json($corrections->all());
    }

    /**
     * Segments as the model sees them: `[{id, text}]`, where `id` is the segment's order. The
     * flagged segment, if any, also carries `"flagged": true`.
     *
     * @param  iterable<ItpAttunementSegment>  $segments
     */
    public static function segments(iterable $segments, ?int $flaggedOrder = null): string
    {
        return static::json(collect($segments)
            ->map(fn (ItpAttunementSegment $segment) => [
                'id' => $segment->order,
                'text' => $segment->text,
                ...($segment->order === $flaggedOrder ? ['flagged' => true] : []),
            ])
            ->values()
            ->all());
    }
}
