<?php

namespace App\Services\Itp;

use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpIntake;
use Illuminate\Support\Collection;

/**
 * Builds the user message for a stage: labelled `## Section` blocks of `Label: value` lines.
 * Empty sections and fields are left out.
 */
class StageInput
{
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

    /**
     * Every intake answer.
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
     * Who the ITP speaks to, and what it must avoid.
     */
    public static function addressing(ItpIntake $intake): string
    {
        return static::fields([
            'Display name' => $intake->display_name,
            'Address style' => $intake->address_style->value,
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
     * @param  array<string, mixed>|null  $slots
     */
    public static function slots(?array $slots): ?string
    {
        if (blank($slots)) {
            return null;
        }

        return static::fields(collect($slots)
            ->map(fn (mixed $value) => is_scalar($value) ? (string) $value : json_encode($value))
            ->all());
    }

    /**
     * The extraction stage's findings, for the reversal stage.
     *
     * @param  array{sufficient?: bool, gaps?: list<string>, slots?: list<array{name: string, value: string}>}|null  $extraction
     */
    public static function extraction(?array $extraction): ?string
    {
        if (blank($extraction)) {
            return null;
        }

        return static::fields([
            ...collect($extraction['slots'] ?? [])->mapWithKeys(fn (array $slot) => [$slot['name'] => $slot['value']])->all(),
            'Gaps' => implode('; ', $extraction['gaps'] ?? []),
        ]);
    }

    /**
     * @param  iterable<ItpAttunementSegment>  $segments
     */
    public static function segments(iterable $segments): string
    {
        return collect($segments)
            ->map(fn (ItpAttunementSegment $segment) => "[{$segment->order}] {$segment->text}")
            ->implode("\n");
    }
}
