<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApprovalBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Release;
use App\Models\Series;
use App\Models\Submission;
use App\Models\SubmissionProposal;
use App\Models\Variation;
use App\Models\VehicleModel;
use App\Services\AI\OcrAgent;
use App\Support\ProposalPresenter;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The desk half of the app: rule on what a capture proposed.
 *
 * All the mechanics already live on the models — this controller loads a
 * submission, hands each decision to SubmissionProposal::approve()/reject(),
 * and reports what happened. It deliberately doesn't walk the hierarchy itself:
 * approving a leaf promotes its ancestors, and a second implementation of that
 * walk here is a second thing to keep in step with the first.
 */
class SubmissionReviewController extends Controller
{
    public function show(Submission $submission): View
    {
        $submission->load([
            'photos',
            // Oldest first, which is the order the pipeline creates them — make
            // before model before casting. Reading top-down through the
            // hierarchy is how you check whether it identified the right car.
            // Oldest first, and only the ones whose record is still there: a
            // proposal for a deleted record renders as null and 500s the page.
            'proposals' => fn ($query) => $query->recordStillExists()->oldest('id'),
            'proposals.decidedBy',
            'proposals.proposable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                // Both the presenter and pendingDependencies() walk upward from
                // each proposed record, so without this a five-proposal
                // submission issues dozens of queries to render one page.
                VehicleModel::class => ['make'],
                Casting::class => ['model.make'],
                CastingVersion::class => ['casting.model.make'],
                Series::class => ['brand'],
                Release::class => ['series.brand', 'castingVersion.casting.model.make'],
                Variation::class => ['release.series.brand', 'release.castingVersion.casting.model.make'],
            ]),
        ]);

        return view('admin.submissions.show', [
            'submission' => $submission,
            'reading' => $this->reading($submission),
        ]);
    }

    /**
     * The OCR output as rows a reviewer can scan: what was read, and how sure
     * the model was about that specific field.
     *
     * Built here rather than iterated in the view because ocr_output is no
     * longer flat — schema_version and the confidence map are structure, not
     * findings, and rendering them as two more rows would bury the six that
     * matter.
     *
     * @return list<array{label: string, value: string, confidence: float|null}>
     */
    private function reading(Submission $submission): array
    {
        $output = $submission->ocr_output ?? [];

        if ($output === []) {
            return [];
        }

        return collect(OcrAgent::FIELDS)
            ->filter(fn (string $field): bool => array_key_exists($field, $output))
            ->map(fn (string $field): array => [
                'label' => str($field)->headline()->toString(),
                // An unreadable field is a finding — the reviewer needs to know
                // the model looked and couldn't, not that the row is missing.
                'value' => $output[$field] === null
                    ? 'Not readable'
                    : (string) $output[$field],
                'confidence' => isset($output['confidence'][$field])
                    ? (float) $output['confidence'][$field]
                    : null,
            ])
            ->values()
            ->all();
    }

    public function approve(Request $request, Submission $submission, SubmissionProposal $proposal): RedirectResponse
    {
        if ($proposal->isDecided()) {
            return back()->with('error', 'That proposal has already been ruled on.');
        }

        // Counted before approving, because the walk is what makes them live and
        // afterwards there is nothing left to count.
        $alsoPublished = count($proposal->proposable->pendingDependencies());

        try {
            $proposal->approve($request->user());
        } catch (ApprovalBlockedException $e) {
            return back()->with('error', $e->getMessage());
        }

        $summary = ProposalPresenter::for($proposal->proposable);

        return back()->with('status', trim(sprintf(
            'Approved %s “%s”. %s',
            str($summary->type)->lcfirst(),
            $summary->title,
            $alsoPublished === 0
                ? ''
                : 'Published '.$alsoPublished.' parent '.str('record')->plural($alsoPublished).' alongside it.',
        )));
    }

    public function reject(Request $request, Submission $submission, SubmissionProposal $proposal): RedirectResponse
    {
        if ($proposal->isDecided()) {
            return back()->with('error', 'That proposal has already been ruled on.');
        }

        $summary = ProposalPresenter::for($proposal->proposable);

        $proposal->reject($request->user());

        // No mention of parents here on purpose: rejection doesn't cascade, and
        // implying it did would invite an admin to reject a variation expecting
        // the wrong casting above it to go away too.
        return back()->with('status', sprintf(
            'Rejected %s “%s”.',
            str($summary->type)->lcfirst(),
            $summary->title,
        ));
    }
}
