<?php

namespace App\Jobs\Concerns;

use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStatus;
use App\Models\ItpGeneration;
use Illuminate\Queue\Middleware\FailOnException;
use Throwable;

/**
 * After the last try, a generation job marks its generation failed and keeps the error for
 * Studio. The app shows `itp.messages.failed`, never the error itself.
 *
 * An unusable response (a refusal, a truncation, invalid or missing output) fails the job on
 * the spot: the same prompt would only be refused or truncated again, and each try is billed.
 * Transport errors, 429s and 5xx still retry with the job's tries and backoff.
 *
 * @property-read string $generationId
 */
trait FailsGeneration
{
    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new FailOnException([UnusableResponse::class])];
    }

    public function failed(?Throwable $exception): void
    {
        $generation = ItpGeneration::find($this->generationId);

        if ($generation === null || $generation->status === ItpStatus::Complete) {
            return;
        }

        $generation->update([
            'status' => ItpStatus::Failed,
            'last_error' => mb_substr($exception === null ? 'Failed' : $exception::class.': '.$exception->getMessage(), 0, 1000),
        ]);
    }
}
