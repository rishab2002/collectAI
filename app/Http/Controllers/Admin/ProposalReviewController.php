<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApprovalBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Release;
use App\Models\Series;
use App\Models\SubmissionProposal;
use App\Models\Variation;
use App\Models\VehicleModel;
use App\Services\Catalog\CastingIdentifier;
use App\Support\ProposalPresenter;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rules on proposals that had no capture behind them.
 *
 * The submission review screen reaches its proposals through a submission,
 * which is the right shape for a photographed car: the photos are the evidence
 * and the proposals are what the pipeline made of them. The importer's
 * inferences have neither — `submission_proposals.submission_id` is nullable
 * exactly so they don't need a synthetic capture invented for them — so they
 * need their own queue rather than a place in that one.
 *
 * What differs is only the framing. The mechanics are the same model methods the
 * submission screen calls, and deliberately so: a second implementation of the
 * approval walk is a second thing to keep in step with the first.
 */
class ProposalReviewController extends Controller
{
    /**
     * Eager loads for anything a presenter or an approval walk will reach.
     * Without these a page of 25 proposals issues a few hundred queries.
     *
     * @var array<class-string, list<string>>
     */
    private const MORPH_WITH = [
        VehicleModel::class => ['make'],
        Casting::class => ['model.make', 'source'],
        CastingVersion::class => ['casting.model.make'],
        Series::class => ['brand'],
        Release::class => ['series.brand', 'castingVersion.casting.model.make'],
        Variation::class => ['release.series.brand', 'release.castingVersion.casting.model.make'],
    ];

    public function index(): View
    {
        $proposals = SubmissionProposal::query()
            ->whereNull('submission_id')
            ->undecided()
            ->recordStillExists()
            // Oldest first: the queue is worked through, not browsed, and a
            // stable order means paging through it doesn't reshuffle under you
            // as rows are decided and drop out.
            ->oldest('id')
            ->with(['proposable' => fn (MorphTo $morphTo) => $morphTo->morphWith(self::MORPH_WITH)])
            ->paginate(25);

        return view('admin.proposals.index', [
            'proposals' => $proposals,
            // The other half of the backfill queue: castings the agent hasn't
            // reached, or told the truth about not knowing. Shown because an
            // empty review queue and a finished job are different things.
            'unidentified' => CastingIdentifier::outstanding()->count(),
        ]);
    }

    public function approve(Request $request, SubmissionProposal $proposal): RedirectResponse
    {
        if (($redirect = $this->guardOwnership($proposal)) !== null) {
            return $redirect;
        }

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

    public function reject(Request $request, SubmissionProposal $proposal): RedirectResponse
    {
        if (($redirect = $this->guardOwnership($proposal)) !== null) {
            return $redirect;
        }

        $record = $proposal->proposable;
        $summary = ProposalPresenter::for($record);

        $proposal->reject($request->user());

        // Worded from what actually happened rather than from the word
        // "rejected", because for an imported casting it isn't the casting that
        // was turned down — see Casting::rejectProposed(). Telling an admin they
        // rejected a car they meant to keep, when the row is still there, is how
        // they stop trusting the screen.
        return back()->with('status', $record instanceof Casting && $record->source()->exists()
            ? sprintf('Retracted the attribution for casting “%s”. The casting stays in the catalogue, unidentified.', $summary->title)
            : sprintf('Rejected %s “%s”.', str($summary->type)->lcfirst(), $summary->title));
    }

    /**
     * A proposal that belongs to a submission is ruled on from that submission's
     * screen, which shows the photos this one can't. Sending it back rather than
     * quietly handling it here keeps one proposal from having two review paths
     * whose messages disagree about what just happened.
     */
    private function guardOwnership(SubmissionProposal $proposal): ?RedirectResponse
    {
        if ($proposal->submission_id !== null) {
            return redirect()
                ->route('admin.submissions.show', $proposal->submission_id)
                ->with('error', 'That proposal belongs to a capture — rule on it here, with its photos.');
        }

        if ($proposal->isDecided()) {
            return back()->with('error', 'That proposal has already been ruled on.');
        }

        return null;
    }
}
