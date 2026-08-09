<?php

namespace App\Console\Commands;

use App\Services\Import\HotWheelsWiki\CastingPageParser;
use App\Services\Import\HotWheelsWiki\CatalogImporter;
use App\Services\Import\HotWheelsWiki\ImportSummary;
use App\Services\Import\HotWheelsWiki\WikiClient;
use App\Services\Import\HotWheelsWiki\YearIndexParser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Imports one year of the Hot Wheels wiki.
 *
 * The year index is only a worklist — it carries no colour, wheel or base data,
 * so it can't describe a variation. Each casting page it links to is opened and
 * read for the releases it lists in that year.
 */
class ImportHotWheelsYear extends Command
{
    protected $signature = 'import:hot-wheels
        {year=2011 : The catalog year to import}
        {--limit= : Stop after this many casting pages, for a quick trial run}
        {--dry-run : Parse and report without writing anything}';

    protected $description = 'Import a year of Hot Wheels releases from the public wiki';

    public function handle(WikiClient $client): int
    {
        $year = (int) $this->argument('year');

        if ($year < 1968 || $year > 2100) {
            $this->error("No year index exists for {$year}. The wiki runs 1968 to 2027.");

            return self::FAILURE;
        }

        $indexTitle = "List of {$year} Hot Wheels";
        $this->info("Reading {$indexTitle}…");

        $index = $client->page($indexTitle);

        if ($index === null) {
            $this->error("The wiki has no page titled “{$indexTitle}”.");

            return self::FAILURE;
        }

        $titles = (new YearIndexParser)->castingTitles($index['wikitext']);

        if ($titles === []) {
            $this->error('The index parsed to zero casting links — the page layout has probably changed.');

            return self::FAILURE;
        }

        if (($limit = $this->option('limit')) !== null) {
            $titles = array_slice($titles, 0, max(1, (int) $limit));
        }

        $this->line(sprintf('%d distinct casting pages to read.', count($titles)));

        $parser = new CastingPageParser;
        $importer = new CatalogImporter;
        $summary = new ImportSummary;
        $dryRun = (bool) $this->option('dry-run');

        $bar = $this->output->createProgressBar(count($titles));
        $bar->start();

        $missing = [];
        $failed = [];

        foreach ($client->eachPage($titles) as $requested => $page) {
            $bar->advance();

            try {
                $parsed = $parser->parse(
                    $page['wikitext'],
                    pageId: $page['pageid'],
                    title: $page['title'],
                    url: $page['url'],
                    revisionId: $page['revid'],
                );

                if ($dryRun) {
                    foreach ($parsed->releasesIn($year) as $release) {
                        $summary->created('release');

                        foreach ($release->variations as $ignored) {
                            $summary->created('variation');
                        }
                    }

                    continue;
                }

                $importer->import($parsed, $year, $summary);
            } catch (Throwable $exception) {
                // One malformed article shouldn't cost the other 199. Collected
                // and reported at the end so a bad page is visible rather than
                // scrolling past inside a progress bar.
                $failed[$requested] = $exception->getMessage();
            }
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($titles as $title) {
            if (! isset($failed[$title])) {
                $missing[] = $title;
            }
        }

        $this->report($summary, $dryRun);

        if ($failed !== []) {
            $this->newLine();
            $this->warn(sprintf('%d page(s) failed to import:', count($failed)));

            foreach ($failed as $title => $message) {
                $this->line("  <fg=red>{$title}</>: {$message}");
            }
        }

        return self::SUCCESS;
    }

    private function report(ImportSummary $summary, bool $dryRun): void
    {
        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        $counts = $summary->counts();

        if ($counts === []) {
            $this->warn('Nothing matched that year.');

            return;
        }

        $this->table(
            ['What', 'Count'],
            array_map(fn (string $key, int $count): array => [$key, $count], array_keys($counts), $counts),
        );

        if ($dryRun) {
            return;
        }

        // Worth saying plainly, because it looks like a failure and isn't: the
        // wiki states no make or model in a structured column, so an imported
        // casting is "a real car, not identified yet" and cannot go live. Every
        // release and variation under it stays pending with it.
        $pending = $summary->count('casting.created') - $summary->count('casting.published');

        if ($pending > 0) {
            $this->newLine();
            $this->line("<comment>{$pending} casting(s) imported as pending.</comment> The wiki doesn't state a make and model in a structured column, so they're unattributed until something identifies them — releases and variations under them stay pending too.");
        }

        foreach (array_slice($summary->skips(), 0, 10) as $skip) {
            $this->line("  <fg=gray>skipped</> {$skip}");
        }
    }
}
