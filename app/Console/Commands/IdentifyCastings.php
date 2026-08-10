<?php

namespace App\Console\Commands;

use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Models\Casting;
use App\Models\VehicleModel;
use App\Services\AI\MetadataAgent;
use App\Services\Catalog\CastingIdentifier;
use App\Services\Import\ImportSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out which real car each unidentified casting is a model of.
 *
 * The importer leaves every casting it creates unidentified on purpose — the
 * wiki names a tool but never states the Make/Model split in a structured
 * column — and Casting::approvalBlocker() holds an unidentified casting back
 * from going live. This is what clears that queue.
 *
 * It stops at the attribution. Publishing the releases and variations the
 * blocked castings were holding up is `catalog:publish-imported`, deliberately
 * separate: identifying a car and publishing a catalogue are different
 * decisions, and one run of this that goes wrong should not have already made
 * six thousand rows public.
 */
class IdentifyCastings extends Command
{
    protected $signature = 'catalog:identify-castings
        {--limit= : Stop after this many castings, for a trial run}
        {--batch= : Castings per API call. Defaults to catalog.metadata.batch_size}
        {--threshold= : Override the auto-approve threshold for this run}
        {--regenerate : Re-file already-attributed castings under the right generation, changing nothing else}
        {--dry-run : Identify inside a transaction that is rolled back, reporting what a real run would do}';

    protected $description = 'Infer the real make and model behind each unidentified casting';

    public function handle(): int
    {
        $threshold = $this->option('threshold') !== null
            ? (float) $this->option('threshold')
            : (float) config('catalog.import.auto_approve_threshold');

        if ($threshold < 0 || $threshold > 1) {
            $this->error('--threshold must be between 0 and 1.');

            return self::FAILURE;
        }

        $regenerate = (bool) $this->option('regenerate');

        $query = ($regenerate ? CastingIdentifier::attributed() : CastingIdentifier::outstanding())
            ->orderBy('id');

        $outstanding = (clone $query)->count();

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        /** @var Collection<int, Casting> $castings */
        $castings = $query->get();

        if ($castings->isEmpty()) {
            $this->info($regenerate
                ? 'Nothing to re-file — no casting is attributed to a real vehicle yet.'
                : 'Nothing to identify — every casting is either attributed or already ruled on.');

            return self::SUCCESS;
        }

        $batchSize = max(1, (int) ($this->option('batch') ?: config('catalog.metadata.batch_size')));

        $this->line(sprintf(
            ' %d casting%s %s; %s %d in batches of %d.',
            $outstanding,
            $outstanding === 1 ? '' : 's',
            $regenerate ? 'attributed' : 'outstanding',
            $regenerate ? 're-filing' : 'identifying',
            $castings->count(),
            $batchSize,
        ));

        if ($regenerate) {
            $this->warn(' Re-file mode: only castings.model_id changes. No proposal is created or ruled on,');
            $this->warn(' and a casting whose make or model no longer agrees with the catalogue is left alone.');
        }

        $this->line(sprintf(' Model: %s · effort: %s · auto-approve at or above %.2f · generation at or above %.2f.',
            config('catalog.metadata.model'),
            config('catalog.metadata.effort'),
            $threshold,
            (float) config('catalog.import.generation_threshold'),
        ));
        $this->newLine();

        $identifier = new CastingIdentifier($this->laravel->make(MetadataAgent::class), $threshold);
        $summary = new ImportSummary;

        try {
            $this->option('dry-run')
                ? $this->rehearse($identifier, $castings, $batchSize, $summary, $regenerate)
                : $this->identifyAll($identifier, $castings, $batchSize, $summary, $regenerate);

            if ($regenerate && ! $this->option('dry-run')) {
                $this->pruneEmptyModels($summary);
            }
        } catch (AuthenticationException|PermissionDeniedException $e) {
            // The summary is still reported: a run that got through six batches
            // before the credential was rejected did six batches of real work,
            // and saying so is the difference between "resume from here" and
            // "start again".
            $this->newLine(2);
            $this->report($summary);
            $this->newLine();
            $this->error('The Claude API rejected the credential: '.$e->getMessage());
            // Names the variable this app actually reads. Saying
            // ANTHROPIC_API_KEY here would walk the reader straight into the
            // precedence trap config/catalog.php exists to avoid.
            $this->warn('Set COLLECTAI_ANTHROPIC_KEY in .env. Nothing further was attempted — every remaining batch would fail the same way.');

            return self::FAILURE;
        }

        $this->report($summary);

        return self::SUCCESS;
    }

    /**
     * A run that writes everything a real one would, then rolls it back.
     *
     * Same reasoning as the importer's: the writes worth rehearsing are the ones
     * that collide. It still spends the tokens, because the answers are what
     * decides which writes happen — this rehearses the database half, not the
     * API half, and there is no way to rehearse the second without paying for it.
     *
     * @param  Collection<int, Casting>  $castings
     */
    private function rehearse(
        CastingIdentifier $identifier,
        Collection $castings,
        int $batchSize,
        ImportSummary $summary,
        bool $regenerate,
    ): void {
        $this->warn(' Dry run — everything below is rolled back. The API calls are still made and still billed.');
        $this->newLine();

        DB::beginTransaction();

        try {
            $this->identifyAll($identifier, $castings, $batchSize, $summary, $regenerate);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  Collection<int, Casting>  $castings
     */
    private function identifyAll(
        CastingIdentifier $identifier,
        Collection $castings,
        int $batchSize,
        ImportSummary $summary,
        bool $regenerate,
    ): void {
        $progress = $this->output->createProgressBar($castings->count());
        $progress->start();

        try {
            foreach ($castings->chunk($batchSize) as $batch) {
                $regenerate
                    ? $identifier->resplitBatch($batch, $summary)
                    : $identifier->identifyBatch($batch, $summary);
                $progress->advance($batch->count());
            }
        } finally {
            // In a finally so an aborted run still leaves the cursor somewhere
            // sensible rather than printing its error over the progress bar.
            $progress->finish();
            $this->newLine(2);
        }
    }

    /**
     * Deletes model rows a re-split emptied.
     *
     * Splitting "Chevrolet / Corvette" into its generations moves every casting
     * off the original row, and what's left is a nameplate with no cars under
     * it — which would show up in the public catalogue as a model you can click
     * into and find nothing. `castings.model_id` is the only foreign key into
     * `models`, so a row with no castings is genuinely unreferenced and safe to
     * remove; anything an admin approved is still represented by the generation
     * rows its castings moved to.
     *
     * Scoped to rows with no castings, never to rows that merely look
     * redundant — merging two rows that both have castings is the Comparison
     * Agent's judgement, not a cleanup step's.
     */
    private function pruneEmptyModels(ImportSummary $summary): void
    {
        $empty = VehicleModel::query()->whereDoesntHave('castings')->get();

        foreach ($empty as $model) {
            $summary->record('models.pruned_empty');
            $model->delete();
        }

        if ($empty->isNotEmpty()) {
            $this->line(sprintf(' Removed %d model row(s) left with no castings.', $empty->count()));
        }
    }

    private function report(ImportSummary $summary): void
    {
        $rows = [];

        foreach ($summary->counts() as $what => $count) {
            $rows[] = [$what, $count];
        }

        $this->table(['', 'count'], $rows);

        if ($summary->count('proposals.queued_for_review') > 0) {
            $this->newLine();
            $this->line(sprintf(
                ' %d attribution%s below the threshold and waiting at %s',
                $summary->count('proposals.queued_for_review'),
                $summary->count('proposals.queued_for_review') === 1 ? ' is' : 's are',
                route('admin.proposals.index'),
            ));
        }

        if ($summary->problems() === []) {
            return;
        }

        // Printed rather than counted, for the same reason the importer prints
        // its skips: a bulk run's failure mode is quiet, and a total hides which
        // castings it happened to.
        $this->newLine();
        $this->warn(sprintf('%d skipped:', count($summary->problems())));

        foreach (array_slice($summary->problems(), 0, 40) as $problem) {
            $this->line("  {$problem}");
        }

        if (count($summary->problems()) > 40) {
            $this->line(sprintf('  … and %d more.', count($summary->problems()) - 40));
        }
    }
}
