<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubmissionRequest;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * The phone-side half of the app: photograph a package, hand it to the pipeline.
 *
 * This controller's job ends at storing the bytes and marking the submission
 * queued. No vision or OCR work happens here — that belongs in
 * app/Services/AI/OcrAgent.php behind a queued job, so Claude API usage stays
 * auditable and swappable rather than buried in a request cycle.
 */
class CaptureController extends Controller
{
    public function show(): View
    {
        return view('admin.capture');
    }

    public function store(StoreSubmissionRequest $request): RedirectResponse|JsonResponse
    {
        $disk = config('catalog.capture.disk');

        // One prefix per capture, minted before the row exists — the bytes have
        // to land before there's an id to name a folder after. A ULID sorts by
        // creation time, so an orphaned upload is easy to place when browsing
        // the bucket.
        $prefix = 'submissions/'.Str::ulid();

        /** @var list<array{disk: string, path: string, type: string}> $stored */
        $stored = [];

        try {
            foreach ($request->photos() as $photo) {
                $stored[] = [
                    'disk' => $disk,
                    'path' => $photo['file']->store($prefix, $disk),
                    'type' => $photo['type'],
                ];
            }

            $submission = DB::transaction(function () use ($request, $stored): Submission {
                $submission = $request->user()->submissions()->create([
                    // Queued, not draft: the photos are already in the bucket, so
                    // there's nothing left for the admin to do to this submission.
                    // Nothing consumes the state yet — the agent doesn't exist —
                    // but when it lands it picks up from here and this controller
                    // doesn't change.
                    'state' => Submission::STATE_QUEUED,
                    ...$request->hints(),
                ]);

                $submission->photos()->createMany($stored);

                return $submission;
            });
        } catch (Throwable $e) {
            // The r2 disk is configured with 'throw' => true, so a failed PUT
            // raises instead of quietly returning false — which is what stops a
            // photo row existing for bytes that never arrived. The flip side is
            // that a failure partway through leaves earlier uploads behind, and
            // nothing else will ever know they're there.
            Storage::disk($disk)->delete(array_column($stored, 'path'));

            throw $e;
        }

        session()->flash('status', "Submission #{$submission->id} queued — photos uploaded.");

        // Back to the capture screen rather than the queue: the actual workflow
        // is a pile of cars on a desk, and the next one is already in hand.
        return $request->expectsJson()
            ? response()->json(['redirect' => route('admin.capture')], 201)
            : redirect()->route('admin.capture');
    }
}
