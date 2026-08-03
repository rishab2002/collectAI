<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use App\Models\SubmissionProposal;
use Illuminate\View\View;

/**
 * The admin landing page. Deliberately thin for now — the capture and review
 * screens land in the next milestone; this exists so login has somewhere to go
 * and so the review queue is visible end to end.
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
        ]);
    }
}
