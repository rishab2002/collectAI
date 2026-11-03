<?php

namespace App\Services\Catalog;

use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Exceptions\ApprovalBlockedException;
use App\Exceptions\MetadataFailedException;
use App\Models\Casting;
use App\Models\Make;
use App\Models\SubmissionProposal;
use App\Models\VehicleModel;
use App\Services\AI\CastingIdentity;
use App\Services\AI\Contracts\IdentifiesCastings;
use App\Services\Import\ImportSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes the Metadata Agent's attributions into the catalogue.
 *
 * The split with MetadataAgent is the same one CLAUDE.md draws across the whole
 * pipeline: the agent decides what a casting is, this decides what the database
 * does about it. Everything here is deterministic, so the interesting behaviour
 * — which answers are written, which are published, which wait for a human — is
 * testable without a model in the loop.
 *
 * **Every usable answer is attached, whatever it scored.** The score decides
 * publication, not attachment. That is not a shortcut: a proposal for a casting
 * with no model_id is one an admin cannot act on, because
 * Casting::approvalBlocker() refuses to publish an unidentified casting, and
 * ProposalPresenter would render it as "Not identified yet" — a review item
 * asking for a ruling on nothing. Attaching first and gating on approval means a
 * low-confidence answer is a concrete claim a human can say yes or no to, and it
 * stays invisible in the meantime because the casting is still pending.
 */
final class CastingIdentifier
{
    public function __construct(
        private readonly IdentifiesCastings $agent,
        private readonly ?float $threshold = null,
        private readonly ?float $generationThreshold = null,
    ) {}

    /**
     * The castings this is for: a real vehicle nobody has named yet, with no
     * ruling already recorded against it.
     *
     * Decided proposals are excluded in both directions. An approved one is
     * already identified and would not match the null model_id anyway; a
     * rejected one is an attribution an admin has already turned down, and
     * re-inferring it on the next run would put the same rejected claim back in
     * front of them.
     */
    public static function outstanding(): Builder
    {
        return Casting::query()
            ->whereNull('model_id')
            ->where('is_fantasy', false)
            ->whereDoesntHave('proposal', fn (Builder $query) => $query
                ->where('decision', '!=', SubmissionProposal::DECISION_PENDING));
    }

    /**
     * Castings already attributed to a real vehicle — the input to a re-split.
     *
     * Deliberately includes rows an admin has already approved. Their make and
     * model were ruled on and are left exactly as they are; what a re-split
     * changes is only *which generation row* the casting hangs off, which is a
     * distinction the catalogue could not express when those rulings were made.
     */
    public static function attributed(): Builder
    {
        return Casting::query()
            ->where('is_fantasy', false)
            ->whereNotNull('model_id');
    }

    /**
     * Re-files a batch of already-attributed castings under the right generation.
     *
     * This is a data migration, not a re-review: no proposal is created or
     * touched, no status changes, and nothing is published or unpublished. The
     * only write is `castings.model_id`, moving a casting from the row that
     * collapsed its whole nameplate onto the row for its actual generation.
     *
     * **The agent's make and model must still agree with what is stored.** If
     * they don't, the casting is reported and skipped rather than moved: a
     * disagreement means the attribution itself is in question, and silently
     * re-attributing a record an admin approved — under a command whose job is
     * to split generations — would be a change nobody asked for and nobody sees.
     *
     * @param  Collection<int, Casting>  $castings  One batch's worth.
     */
    public function resplitBatch(Collection $castings, ImportSummary $summary): void
    {
        if (($identities = $this->ask($castings, $summary)) === null) {
            return;
        }

        foreach ($castings as $casting) {
            $identity = $identities[$casting->getKey()] ?? null;

            if ($identity === null || ! $identity->isUsable() || $identity->isFantasy()) {
                $summary->problem("{$casting->name}: no usable re-reading, left where it is");

                continue;
            }

            $current = $casting->model;

            if (! $this->agrees($current, $identity)) {
                $summary->problem(sprintf(
                    '%s: agent now says %s / %s but the catalogue has %s / %s — left alone',
                    $casting->name,
                    $identity->make,
                    $identity->model,
                    $current?->make?->name ?? '?',
                    $current?->name ?? '?',
                ));

                continue;
            }

            if (! $identity->hasGeneration() || $identity->generationScore() < $this->generationThreshold()) {
                $summary->record('castings.generation_unknown');

                continue;
            }

            $target = $this->vehicleModel($identity, $summary);

            if ($target->is($current)) {
                $summary->record('castings.already_correct');

                continue;
            }

            // A generation row inherits the visibility of the casting being
            // moved onto it. Without this a live casting could end up hanging
            // off a pending model — the exact "live record with an invisible
            // parent" the approval walk exists to prevent, arrived at sideways.
            if ($casting->isLive() && ! $target->isLive()) {
                $target->promoteWithDependencies();
            }

            $casting->forceFill(['model_id' => $target->getKey()])->save();
            $summary->record('castings.resplit');
        }
    }

    /**
     * Whether a re-reading is talking about the same vehicle the catalogue
     * already has, ignoring generation — which is the thing being changed.
     */
    private function agrees(?VehicleModel $current, CastingIdentity $identity): bool
    {
        return $current !== null
            && mb_strtolower($current->name) === mb_strtolower((string) $identity->model)
            && mb_strtolower($current->make?->name ?? '') === mb_strtolower((string) $identity->make);
    }

    /**
     * One batch's answers, or **null** if the call itself failed survivably.
     *
     * Null and an empty array mean different things and the callers must not
     * conflate them: null is "the request never produced answers", which is one
     * problem already reported for the whole batch, while an empty array is a
     * call that succeeded and simply said nothing usable about these castings,
     * which is a problem per casting. Returning [] for both double-reports a
     * failed batch as one batch error plus N casting errors.
     *
     * @param  Collection<int, Casting>  $castings
     * @return array<int, CastingIdentity>|null
     */
    private function ask(Collection $castings, ImportSummary $summary): ?array
    {
        try {
            return $this->agent->identify($castings, $this->knownMakes());
        } catch (AuthenticationException|PermissionDeniedException $e) {
            // Deliberately not caught below. Nothing about the next batch will
            // be different, so swallowing this would grind through every
            // remaining batch producing the same error and finish reporting a
            // run that identified nothing — which reads like the model was
            // unsure rather than like the key is wrong.
            throw $e;
        } catch (MetadataFailedException|AnthropicException $e) {
            // Everything else is worth surviving: a rate limit, a 529, a dropped
            // connection, a refusal, a batch that didn't fit its token budget.
            // One failed batch is not a failed run, and the castings in it stay
            // as they were — which is where the next run will find them.
            $summary->problem('batch of '.$castings->count().' failed: '.$e->getMessage());

            return null;
        }
    }

    public function identifyBatch(Collection $castings, ImportSummary $summary): void
    {
        if (($identities = $this->ask($castings, $summary)) === null) {
            return;
        }

        foreach ($castings as $casting) {
            $identity = $identities[$casting->getKey()] ?? null;

            if ($identity === null) {
                $summary->problem("{$casting->name}: the model returned no usable answer for this casting");

                continue;
            }

            $this->apply($casting, $identity, $summary);
        }
    }

    /**
     * Records one attribution, and publishes it if it scored well enough.
     */
    private function apply(Casting $casting, CastingIdentity $identity, ImportSummary $summary): void
    {
        if (! $identity->isUsable()) {
            // Not a problem() — an honest "I can't tell" is the answer this was
            // designed to accept, and counting it as a skip alongside genuine
            // failures would bury the ones worth reading.
            $summary->record('castings.unknown');

            return;
        }

        DB::transaction(function () use ($casting, $identity, $summary): void {
            if ($identity->isFantasy()) {
                // The positive assertion, and the only thing that distinguishes
                // "no real car behind this" from "not identified yet".
                $casting->fill(['is_fantasy' => true, 'model_id' => null])->save();
                $summary->record('castings.fantasy');
            } else {
                $casting->fill([
                    'is_fantasy' => false,
                    'model_id' => $this->vehicleModel($identity, $summary)->getKey(),
                ])->save();
                $summary->record('castings.identified');
            }

            $proposal = SubmissionProposal::updateOrCreate(
                [
                    'proposable_type' => $casting->getMorphClass(),
                    'proposable_id' => $casting->getKey(),
                ],
                [
                    // No capture behind it: the inference is the importer's, and
                    // this is the case the nullable column was added for.
                    'submission_id' => null,
                    'confidence' => $identity->score(),
                ],
            );

            // The morph relation isn't loaded by updateOrCreate, and approve()
            // walks it. Setting it here also guarantees the walk sees the row
            // this transaction just saved rather than re-reading a stale one.
            $proposal->setRelation('proposable', $casting);

            $this->rule($proposal, $identity, $summary);
        });
    }

    /**
     * Publishes the attribution or leaves it for a human.
     *
     * The threshold read here is `catalog.import.auto_approve_threshold`, which
     * is a separate dial from `catalog.review.auto_approve_threshold` and must
     * stay that way: the review one is 1.0 because nothing a person photographed
     * publishes itself, and this path is not that path.
     */
    private function rule(SubmissionProposal $proposal, CastingIdentity $identity, ImportSummary $summary): void
    {
        if ($identity->score() < $this->threshold()) {
            $summary->record('proposals.queued_for_review');

            return;
        }

        try {
            // No admin: nobody made this decision, the threshold did. The
            // decision-metadata CHECK on the table allows exactly that — a null
            // decided_by with a decided_at — because it was written for this.
            $proposal->approve();
            $summary->record('proposals.auto_approved');
        } catch (ApprovalBlockedException $e) {
            // Shouldn't happen: the casting was just given a model or marked
            // fantasy, which is the only blocker on this chain. Recorded rather
            // than thrown so one surprising row can't end a 241-casting run, and
            // reported loudly because it means an assumption here is wrong.
            $summary->problem("{$identity->castingName}: attribution written but not published — ".$e->getMessage());
        }
    }

    /**
     * The real-world vehicle, reusing the catalogue's row for it where one
     * already exists.
     *
     * **A row is one generation of one nameplate, not the nameplate.** That is
     * the identity the lookup uses, and it is the fix for the failure the first
     * live import produced: seven Corvette castings spanning C2 to C6 all
     * resolved to a single "Chevrolet / Corvette" row, which then took whichever
     * chassis code arrived first and mislabelled the other six. A nameplate is
     * not one vehicle — a Corvette is eight quite different cars — so the
     * catalogue stores it as eight rows.
     *
     * The start year is the discriminator rather than the chassis code, because
     * not every generation has a code but every generation has years. A casting
     * whose generation couldn't be established files against the nameplate with
     * a null start year, which is its own bucket and reads as "not placed yet".
     *
     * Names are matched case-insensitively and exactly, never by trigram
     * similarity. Fuzzy matching belongs to the Comparison Agent, and using it
     * here would silently merge a Ford GT into a Ford GT40 — two real, distinct
     * vehicles whose names are one character apart. An exact match that misses
     * costs a near-duplicate row an admin can see and merge; a fuzzy match that
     * hits wrongly costs a car filed as a different car.
     */
    private function vehicleModel(CastingIdentity $identity, ImportSummary $summary): VehicleModel
    {
        $make = Make::query()->whereRaw('LOWER(name) = LOWER(?)', [$identity->make])->first();

        if ($make === null) {
            $make = Make::create(['name' => $identity->make]);
            $summary->record('makes.created');
        }

        $generation = $identity->generation($this->generationThreshold());

        $model = VehicleModel::query()
            ->where('make_id', $make->getKey())
            ->whereRaw('LOWER(name) = LOWER(?)', [$identity->model])
            // Null start year is its own generation bucket, so a plain `where`
            // would never match it — in Postgres NULL = NULL is not true.
            ->when(
                $generation['generation_start_year'] === null,
                fn ($query) => $query->whereNull('generation_start_year'),
                fn ($query) => $query->where('generation_start_year', $generation['generation_start_year']),
            )
            ->first();

        if ($model !== null) {
            return $model;
        }

        $summary->record($generation['generation_start_year'] === null
            ? 'models.created_without_generation'
            : 'models.created');

        return VehicleModel::create([
            'make_id' => $make->getKey(),
            'name' => $identity->model,
            ...$generation,
        ]);
    }

    /**
     * Manufacturers already in the catalogue, so the model reuses a spelling.
     *
     * Re-read per batch rather than once per run, on purpose: the first batch
     * creates most of the makes a run will ever need, and later batches spelling
     * them the same way is the whole point. makes.name is hard-unique, so
     * "Chevy" arriving beside "Chevrolet" is not a collision the database
     * catches — it is two rows for one company.
     *
     * @return list<string>
     */
    private function knownMakes(): array
    {
        return Make::query()->orderBy('name')->pluck('name')->all();
    }

    private function threshold(): float
    {
        return $this->threshold ?? (float) config('catalog.import.auto_approve_threshold');
    }

    /**
     * Separate from threshold() on purpose — see the note in config/catalog.php.
     * One bar governs whether a car is published as the right car; this one
     * governs which generation row it sits in, a narrower claim that scores
     * lower and fails more cheaply.
     */
    private function generationThreshold(): float
    {
        return $this->generationThreshold ?? (float) config('catalog.import.generation_threshold');
    }
}
