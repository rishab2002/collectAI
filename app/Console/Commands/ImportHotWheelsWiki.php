<?php

namespace App\Console\Commands;

use App\Services\Import\HotWheelsWiki\ApiPageSource;
use App\Services\Import\HotWheelsWiki\CastingPageParser;
use App\Services\Import\HotWheelsWiki\CatalogImporter;
use App\Services\Import\HotWheelsWiki\DumpPageSource;
use App\Services\Import\HotWheelsWiki\PageSource;
use App\Services\Import\HotWheelsWiki\WikiPage;
use App\Services\Import\HotWheelsWiki\YearIndexParser;
use App\Services\Import\ImportSummary;
use Illuminate\Console\Command;

/**
 * Imports one year of Hot Wheels from the wiki.
 *
 * A year at a time because that is how the wiki indexes castings, and because a
 * run that can be judged is worth more than one that can't: `--year=2011` is
 * roughly 150 castings, small enough to read the output of and check against the
 * source.
 */
class ImportHotWheelsWiki extends Command
{
    protected $signature = 'catalog:import-hot-wheels
        {--year=2011 : The year index to import}
        {--dump= : Path to the wiki XML dump (.7z or a decompressed .xml). Without it, the API is used}
        {--limit= : Stop after this many casting pages, for a trial run}';

    protected $description = 'Import a year of Hot Wheels castings, releases and variations from the Fandom wiki';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $source = $this->source();
        $index = YearIndexParser::titleForYear($year);

        $this->info("Reading {$index}");

        $indexPage = $source->page($index);

        if ($indexPage === null) {
            $this->error("The source has no page called “{$index}”.");

            return self::FAILURE;
        }

        $titles = (new YearIndexParser)->castingTitles($indexPage->wikitext);

        if ($limit = (int) $this->option('limit')) {
            $titles = array_slice($titles, 0, $limit);
        }

        $this->info(sprintf('%d casting articles to import.', count($titles)));

        $summary = $this->importAll($source, $titles);

        $this->report($summary);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $titles
     */
    private function importAll(PageSource $source, array $titles): ImportSummary
    {
        $parser = new CastingPageParser;
        $importer = new CatalogImporter;
        $summary = new ImportSummary;

        $progress = $this->output->createProgressBar(count($titles));
        $progress->start();

        // Redirects are resolved in a second pass rather than one at a time. The
        // dump is a stream — chasing a target mid-run would mean decompressing
        // the archive again per redirect.
        $redirects = [];
        $seen = [];

        foreach ($this->fetch($source, $titles, $redirects, $seen, $summary) as $page) {
            $summary->merge($importer->import(
                $parser->parse($page->wikitext, $page->title, $page->pageId, $page->revisionId),
            ));

            $progress->advance();
        }

        $unresolved = array_values(array_diff($redirects, $seen));

        if ($unresolved !== []) {
            $this->newLine();
            $this->line(sprintf(' Following %d redirect(s).', count($unresolved)));

            foreach ($this->fetch($source, $unresolved, $redirects, $seen, $summary) as $page) {
                $summary->merge($importer->import(
                    $parser->parse($page->wikitext, $page->title, $page->pageId, $page->revisionId),
                ));
            }
        }

        $progress->finish();
        $this->newLine(2);

        return $summary;
    }

    /**
     * Yields the real articles among the titles, noting which are redirects and
     * which the source didn't have.
     *
     * @param  list<string>  $titles
     * @param  list<string>  $redirects
     * @param  list<string>  $seen
     * @return iterable<WikiPage>
     */
    private function fetch(
        PageSource $source,
        array $titles,
        array &$redirects,
        array &$seen,
        ImportSummary $summary,
    ): iterable {
        $missing = array_flip($titles);

        foreach ($source->pages($titles) as $page) {
            unset($missing[$page->title]);
            $seen[] = $page->title;

            if (($target = $page->redirectsTo()) !== null) {
                $redirects[] = $target;
                $summary->record('redirects');

                continue;
            }

            yield $page;
        }

        foreach (array_keys($missing) as $title) {
            $summary->problem("{$title}: the source has no such page");
        }
    }

    private function source(): PageSource
    {
        $dump = $this->option('dump');

        if ($dump === null) {
            // Fine for a year — 150 castings batch into a handful of requests.
            // The dump is what the full 11,041-page import is for.
            $this->line(' Source: the wiki API.');

            return new ApiPageSource;
        }

        $this->line(" Source: {$dump}");

        return new DumpPageSource($dump);
    }

    private function report(ImportSummary $summary): void
    {
        $rows = [];

        foreach ($summary->counts() as $what => $count) {
            $rows[] = [$what, $count];
        }

        $this->table(['', 'count'], $rows);

        if ($summary->problems() === []) {
            return;
        }

        // Printed rather than counted. A bulk import's failure mode is pages
        // quietly yielding nothing, and a total alone hides which ones.
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
