<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use App\Models\SubmissionProposal;
use Illuminate\View\View;

/**
 * The admin landing page: what is waiting to be ruled on.
 *
 * Two queues, kept apart on purpose. Submissions are cars someone photographed,
 * and the evidence is the photos. The inferred queue is judgements the pipeline
 * made about imported records, where there is nothing to look at but the source
 * page. Merging them would put rows in the capture app's list that nobody
 * captured — the same reason submission_proposals.submission_id was made
 * nullable instead of a synthetic submission being invented per import run.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'submissions' => Submission::query()
                ->awaitingReview()
                ->withCount([
                    'proposals as undecided_count' => fn ($query) => $query
                        ->where('decision', SubmissionProposal::DECISION_PENDING),
                ])
                ->latest()
                ->get(),

            'inferredCount' => SubmissionProposal::query()
                ->whereNull('submission_id')
                ->undecided()
                ->count(),
        ]);
    }
}
