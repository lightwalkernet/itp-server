<?php

namespace App\Enums;

/**
 * Where a generation is in the pipeline (step 6 contract). Plain strings on the wire.
 *
 * queued → [classifying, only with the safety check on] → assessing → awaiting_followup ⇄ assessing
 * → generating → awaiting_resonance ⇄ regenerating → deriving_companions → complete
 */
enum ItpStatus: string
{
    case Queued = 'queued';
    case Classifying = 'classifying';
    case Assessing = 'assessing';
    case AwaitingFollowup = 'awaiting_followup';
    case Generating = 'generating';
    case AwaitingResonance = 'awaiting_resonance';
    case Regenerating = 'regenerating';
    case DerivingCompanions = 'deriving_companions';
    case Complete = 'complete';
    case Deferred = 'deferred';
    case Blocked = 'blocked';
    case Failed = 'failed';

    /**
     * The server is working; the app keeps polling.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [
            self::Queued,
            self::Classifying,
            self::Assessing,
            self::Generating,
            self::Regenerating,
            self::DerivingCompanions,
        ], true);
    }

    /**
     * Finished or held: nothing more happens without staff.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Complete,
            self::Deferred,
            self::Blocked,
            self::Failed,
        ], true);
    }

    /**
     * The pipeline is paused until the user answers or responds.
     */
    public function awaitsUser(): bool
    {
        return in_array($this, [
            self::AwaitingFollowup,
            self::AwaitingResonance,
        ], true);
    }

    /**
     * The user-facing text for a held or failed generation, or null for every other status.
     */
    public function userMessage(): ?string
    {
        return match ($this) {
            self::Deferred => config('itp.messages.deferred'),
            self::Blocked => config('itp.messages.blocked'),
            self::Failed => config('itp.messages.failed'),
            default => null,
        };
    }
}
