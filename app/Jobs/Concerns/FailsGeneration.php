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
 * An unusable response (a refusal, a truncation, output that doesn't parse or doesn't match the
 * stage's shape) fails the job on the spot: the same prompt would only fail again, and each try
 * is billed. The error keeps the model's raw output (truncated), so Studio shows what came back.
 * Transport errors, 429s and 5xx still retry with the job's tries and backoff.
 *
 * @property-read string $generationId
 */
trait FailsGeneration
{
    /**
     * How much of the error `last_error` keeps: room for the raw output an unusable response carries.
     */
    public const int LAST_ERROR_LIMIT = 4000;

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
            'last_error' => mb_substr($exception === null ? 'Failed' : $exception::class.': '.$exception->getMessage(), 0, self::LAST_ERROR_LIMIT),
        ]);
    }
}
