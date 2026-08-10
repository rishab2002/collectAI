<?php

namespace App\Console\Commands;

use App\Models\CastingVersion;
use App\Models\Release;
use App\Models\SubmissionProposal;
use App\Models\Variation;
use App\Services\Import\ImportSummary;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Publishes imported records that were only ever waiting on something else.
 *
 * The importer leaves casting versions, releases and variations pending not
 * because anything about them is in doubt — the wiki states every column it
 * reads, and provenance is the gate — but because they hang off a casting that
 * could not go live until someone said which car it was. Once that attribution
 * lands, nothing else was ever holding them back, and nothing existed to notice.
 * This is that step.
 *
 * Three rules keep it from becoming a way to publish things sideways:
 *
 * 1. **Only records carrying provenance.** A pending variation from a capture is
 *    a proposal awaiting review, and it stays one. What this publishes is facts
 *    an outside source stated, on the strength of that source.
 * 2. **Only records whose ancestors are already live.** Not "no blockers" —
 *    that would publish a release under a casting whose attribution scored 0.4
 *    and is still sitting in the review queue, which is precisely the ruling
 *    this command has no business making. A pending ancestor means a human is
 *    still deciding, so the descendant waits with it.
 * 3. **Never anything with an undecided proposal of its own.** Belt and braces:
 *    if a record is both imported and proposed, the proposal is the stricter
 *    claim and wins.
 *
 * All three make it safe to re-run. It is meant to be run after
 * `catalog:identify-castings`, and again after an admin works through the
 * review queue.
 */
class PublishImportedRecords extends Command
{
    protected $signature = 'catalog:publish-imported
        {--dry-run : Report what would be published without publishing it}';

    protected $description = 'Publish imported releases and variations whose casting is now identified and live';

    /**
     * Top-down, so that by the time a release is considered its casting version
     * is already live and the walk above it is one cheap loaded check rather
     * than a chain of queries. Bottom-up would work and would be slower for no
     * reason.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const LEVELS = [
        CastingVersion::class => ['casting.model.make'],
        Release::class => ['castingVersion.casting.model.make', 'series.brand'],
        Variation::class => [
            'release.castingVersion.casting.model.make',
            'release.series.brand',
        ],
    ];

    public function handle(): int
    {
        $summary = new ImportSummary;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            // Writes and rolls back, rather than walking the tree and skipping
            // the update — the same call the importer's --dry-run makes, and for
            // the same reason: a rehearsal that never reaches the write reports
            // a clean run and then hits the real one's failures for the first
            // time in production.
            //
            // Explicitly begun rather than wrapped in DB::transaction(), which
            // would try to commit afterwards and, at transaction level 1,
            // commit a savepoint this command didn't open.
            $this->warn(' Dry run — everything below is rolled back.');
            $this->newLine();

            DB::beginTransaction();
        }

        try {
            foreach (self::LEVELS as $model => $eagerLoad) {
                $this->publishLevel($model, $eagerLoad, $summary);
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        $this->report($summary);

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $eagerLoad
     */
    private function publishLevel(string $model, array $eagerLoad, ImportSummary $summary): void
    {
        $table = (new $model)->getTable();

        $query = $model::query()
            ->pending()
            ->whereHas('source')
            ->whereDoesntHave('proposal', fn (Builder $query) => $query
                ->where('decision', SubmissionProposal::DECISION_PENDING))
            ->with($eagerLoad);

        $total = (clone $query)->count();

        if ($total === 0) {
            return;
        }

        $this->line(" {$table}: {$total} pending imported record".($total === 1 ? '' : 's'));

        $progress = $this->output->createProgressBar($total);
        $progress->start();

        // chunkById rather than chunk: publishing changes `status`, which is the
        // column the query filters on, so an offset-paged walk would skip a page
        // of rows every time it advanced.
        $query->chunkById(500, function ($records) use ($summary, $table, $progress): void {
            foreach ($records as $record) {
                $progress->advance();

                if ($record->pendingDependencies() !== []) {
                    // Waiting on a human, not on this command.
                    $summary->record("{$table}.awaiting_ancestor");

                    continue;
                }

                if ($record->approvalBlockers() !== []) {
                    $summary->record("{$table}.blocked");

                    continue;
                }

                // markLive(), not promoteWithDependencies(): the check above has
                // already established there is nothing above this to promote,
                // and calling the walk would suggest otherwise.
                $record->markLive();

                $summary->record("{$table}.published");
            }
        });

        $progress->finish();
        $this->newLine();
    }

    private function report(ImportSummary $summary): void
    {
        $this->newLine();

        if ($summary->counts() === []) {
            $this->info('Nothing to publish — no imported record is waiting on an attribution.');

            return;
        }

        $rows = [];

        foreach ($summary->counts() as $what => $count) {
            $rows[] = [$what, $count];
        }

        $this->table(['', 'count'], $rows);

        $waiting = collect($summary->counts())
            ->filter(fn (int $count, string $what): bool => str_ends_with($what, '.awaiting_ancestor'))
            ->sum();

        if ($waiting > 0) {
            $this->line(sprintf(
                ' %d record%s still waiting on a casting whose attribution has not been approved. '
                .'Run catalog:identify-castings, or work through the review queue, then run this again.',
                $waiting,
                $waiting === 1 ? ' is' : 's are',
            ));
        }
    }
}
