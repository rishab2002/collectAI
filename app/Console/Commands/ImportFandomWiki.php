<?php

namespace App\Console\Commands;

use App\Services\Import\FandomWiki\ApiPageSource;
use App\Services\Import\FandomWiki\CastingPageParser;
use App\Services\Import\FandomWiki\CatalogImporter;
use App\Services\Import\FandomWiki\DumpPageSource;
use App\Services\Import\FandomWiki\PageSource;
use App\Services\Import\FandomWiki\Wiki;
use App\Services\Import\FandomWiki\WikiPage;
use App\Services\Import\FandomWiki\YearIndexParser;
use App\Services\Import\ImportSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports castings, releases and variations from a Fandom diecast wiki — the
 * Hot Wheels wiki or the Matchbox wiki, which share one page format.
 *
 * Works from the wiki's year indexes. One year is a run small enough to read the
 * output of and check against the source; `--from`/`--to` takes a range in one
 * pass, reading every index first and then each casting article once, however
 * many of the years it appears in. That matters on the dump: a casting sold in
 * forty years is one article, and reading the archive forty times to find it
 * would be most of the run.
 */
class ImportFandomWiki extends Command
{
    protected $signature = 'catalog:import-wiki
        {wiki=hot-wheels : Which wiki — hot-wheels or matchbox}
        {--year= : One year index to import (default 2011 when no range is given)}
        {--from= : First year of a range}
        {--to= : Last year of a range}
        {--dump= : Path to the wiki XML dump (.7z or a decompressed .xml). Without it, the API is used}
        {--limit= : Stop after this many casting pages, for a trial run}
        {--dry-run : Import inside a transaction that is rolled back, reporting what a real run would do}';

    protected $description = 'Import castings, releases and variations from a Fandom diecast wiki, by year';

    public function handle(): int
    {
        try {
            $wiki = $this->wiki();
            $years = $this->years();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $source = $this->source($wiki);
        $titles = $this->castingTitles($wiki, $source, $years);

        if ($titles === null) {
            return self::FAILURE;
        }

        if ($limit = (int) $this->option('limit')) {
            $titles = array_slice($titles, 0, $limit);
        }

        $this->info(sprintf('%d casting articles to import.', count($titles)));

        if ($titles === []) {
            // Said out loud rather than passed on. An empty list reaching the
            // page source used to mean "every page" on the dump.
            $this->warn('The year index names no casting articles, so there is nothing to import.');

            return self::SUCCESS;
        }

        $summary = $this->option('dry-run')
            ? $this->rehearse($wiki, $source, $titles)
            : $this->importAll($wiki, $source, $titles);

        $this->report($summary);

        return self::SUCCESS;
    }

    protected function wiki(): Wiki
    {
        return Wiki::fromKey((string) $this->argument('wiki'));
    }

    /**
     * @return list<int>
     */
    private function years(): array
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if ($from === null && $to === null) {
            return [(int) ($this->option('year') ?? 2011)];
        }

        if ($this->option('year') !== null) {
            throw new InvalidArgumentException('Give either --year or --from/--to, not both.');
        }

        $from = (int) ($from ?? $to);
        $to = (int) ($to ?? $from);

        if ($from > $to || $from < 1900 || $to > 2100) {
            throw new InvalidArgumentException("“{$from}–{$to}” is not a usable range of years.");
        }

        return range($from, $to);
    }

    /**
     * Every casting article the requested years' indexes name, de-duplicated,
     * in year order. Null when none of the indexes exist.
     *
     * @param  list<int>  $years
     * @return list<string>|null
     */
    private function castingTitles(Wiki $wiki, PageSource $source, array $years): ?array
    {
        $indexes = [];

        foreach ($years as $year) {
            $indexes[$wiki->yearIndexTitle($year)] = $year;
        }

        $this->info(count($indexes) === 1
            ? 'Reading '.array_key_first($indexes)
            : sprintf('Reading %d year indexes, %d–%d', count($indexes), min($years), max($years)));

        // One pass over the source for every index, in whatever order it holds
        // them, then put back into year order so a run reads chronologically.
        $found = [];

        foreach ($source->pages(array_keys($indexes)) as $page) {
            $found[$page->title] = $page;
        }

        $parser = new YearIndexParser;
        $titles = [];
        $missing = [];

        foreach ($indexes as $title => $year) {
            if (! isset($found[$title])) {
                $missing[] = $title;

                continue;
            }

            foreach ($parser->castingTitles($found[$title]->wikitext) as $casting) {
                $titles[$casting] = true;
            }
        }

        if (count($missing) === count($indexes)) {
            $this->error(count($indexes) === 1
                ? "The source has no page called “{$missing[0]}”."
                : 'The source has none of those year indexes.');

            return null;
        }

        if ($missing !== []) {
            $this->warn(sprintf(' No index for %d year(s): %s', count($missing), implode(', ', array_map(
                static fn (string $title): int => (int) YearIndexParser::yearFromTitle($title),
                $missing,
            ))));
        }

        return array_keys($titles);
    }

    /**
     * A run that writes everything a real one would, then rolls it back.
     *
     * Deliberately not "parse and skip the insert". What a rehearsal is for is
     * catching the writes that *collide* — a source key two pages both claim, a
     * variation the uniqueness index reads as one already imported — and a dry
     * run that never reaches the insert reports those as a clean import and then
     * dies partway through the real one.
     *
     * The cost is one transaction held open for the whole run rather than one
     * per page. Fine for a year; reach for --limit before rehearsing the dump.
     *
     * @param  list<string>  $titles
     */
    private function rehearse(Wiki $wiki, PageSource $source, array $titles): ImportSummary
    {
        $this->warn(' Dry run — everything below is rolled back.');

        DB::beginTransaction();

        try {
            return $this->importAll($wiki, $source, $titles);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  list<string>  $titles
     */
    private function importAll(Wiki $wiki, PageSource $source, array $titles): ImportSummary
    {
        $parser = new CastingPageParser($wiki);
        $importer = new CatalogImporter($wiki);
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

        $unresolved = array_values(array_unique(array_diff($redirects, $seen)));

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

            // Two index entries can redirect to the same article, and a range
            // run names far more titles than a year does. An article already
            // imported this run is not imported a second time.
            if (in_array($page->title, $seen, true)) {
                continue;
            }

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

    private function source(Wiki $wiki): PageSource
    {
        $dump = $this->option('dump');

        if ($dump === null) {
            // Fine for a year — a few hundred castings batch into a handful of
            // requests. The dump is what a range is for.
            $this->line(" Source: the {$wiki->host} API.");

            return new ApiPageSource($wiki->apiEndpoint());
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
