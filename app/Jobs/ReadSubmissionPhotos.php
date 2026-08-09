<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\AI\OcrAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the OCR Agent over a queued capture and records what it read.
 *
 * This is the whole pipeline today: the Metadata, Search, Comparison, Insertion
 * and Review agents don't exist yet, so a completed submission has a reading
 * and no proposals. That's a legible intermediate state rather than a broken
 * one — the review screen renders the reading, and the admin can see what the
 * model made of a photo before anything is built on top of it.
 */
class ReadSubmissionPhotos implements ShouldQueue
{
    use Queueable;

    /**
     * A vision call can fail on a network blip or a 529; both are worth
     * retrying. A refusal or an unreadable photo is not, but those raise
     * OcrFailedException, which is listed below as non-retryable.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly Submission $submission) {}

    public function handle(OcrAgent $agent): void
    {
        $this->submission->update(['state' => Submission::STATE_PROCESSING]);

        $output = $agent->read($this->submission);

        $this->submission->update([
            'state' => Submission::STATE_COMPLETED,
            'ocr_output' => $output,
            'error' => null,
            'processed_at' => now(),
        ]);
    }

    /**
     * Called once the last attempt has failed. Records the reason on the
     * submission rather than only in the log, because the person who needs it
     * is looking at the review screen, not at Horizon.
     */
    public function failed(?Throwable $e): void
    {
        $this->submission->update([
            'state' => Submission::STATE_FAILED,
            // Bounded because the column is plain text and an SDK exception can
            // carry an entire response body.
            'error' => Str::limit($e?->getMessage() ?: 'The pipeline failed with no reason recorded.', 500),
            'processed_at' => now(),
        ]);
    }
}
