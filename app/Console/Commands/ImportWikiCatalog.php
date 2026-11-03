<?php

namespace App\Console\Commands;

use App\Services\Import\FandomWiki\ApiPageSource;
use App\Services\Import\FandomWiki\CastingPage;
use App\Services\Import\FandomWiki\CatalogImporter;
use App\Services\Import\FandomWiki\CategorySource;
use App\Services\Import\FandomWiki\MiniGtLists;
use App\Services\Import\FandomWiki\PageSource;
use App\Services\Import\FandomWiki\TomicaArticles;
use App\Services\Import\FandomWiki\Wiki;
use App\Services\Import\ImportSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the wikis that are not organised by year index — Tomica (by toy-line
 * category) and Mini GT (by yearly item lists) — through the same
 * CatalogImporter as Hot Wheels and Matchbox, so provenance, idempotency and the
 * pending-until-identified rule all behave identically.
 *
 * Neither wiki publishes a dump, so this reads the API: about 75 requests for
 * Tomica's five lines and one for Mini GT.
 */
class ImportWikiCatalog extends Command
{
    protected $signature = 'catalog:import-wiki-catalog
        {wiki : tomica or mini-gt}
        {--limit= : Stop after this many castings, for a trial run}
        {--dry-run : Import inside a transaction that is rolled back}';

    protected $description = 'Import Tomica (incl. Limited Vintage / Neo) or Mini GT from their wikis';

    /**
     * Set by tests to avoid the network.
     */
    public static ?object $source = null;

    public function handle(): int
    {
        $key = (string) $this->argument('wiki');

        if (! in_array($key, ['tomica', 'mini-gt'], true)) {
            $this->error('This command imports tomica or mini-gt. Hot Wheels and Matchbox use catalog:import-wiki.');

            return self::FAILURE;
        }

        $wiki = Wiki::fromKey($key);
        $source = self::$source ?? new ApiPageSource($wiki->apiEndpoint());
        $summary = new ImportSummary;

        $this->line(" Source: the {$wiki->host} API.");
        $castings = $key === 'tomica'
            ? $this->tomica($wiki, $source, $summary)
            : $this->miniGt($wiki, $source, $summary);

        if ($limit = (int) $this->option('limit')) {
            $castings = array_slice($castings, 0, $limit);
        }

        $this->info(sprintf('%d castings to import.', count($castings)));

        if ($this->option('dry-run')) {
            $this->warn(' Dry run — everything below is rolled back.');
            DB::beginTransaction();
        }

        try {
            $importer = new CatalogImporter($wiki);
            $progress = $this->output->createProgressBar(count($castings));

            foreach ($castings as $casting) {
                $summary->merge($importer->import($casting));
                $progress->advance();
            }

            $progress->finish();
            $this->newLine(2);
        } finally {
            if ($this->option('dry-run')) {
                DB::rollBack();
            }
        }

        $this->table(['', 'count'], array_map(
            fn (string $what, int $count): array => [$what, $count],
            array_keys($summary->counts()),
            $summary->counts(),
        ));

        if ($summary->problems() !== []) {
            $this->warn(sprintf('%d skipped:', count($summary->problems())));

            foreach (array_slice($summary->problems(), 0, 20) as $problem) {
                $this->line("  {$problem}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return list<CastingPage>
     */
    private function tomica(Wiki $wiki, CategorySource $source, ImportSummary $summary): array
    {
        $pages = [];

        // An article filed under two lines is read once; TomicaArticles picks
        // its line by priority from the categories it carries.
        foreach (array_keys(TomicaArticles::LINES) as $category) {
            $this->line(" Reading Category:{$category}");

            foreach ($source->categoryMembers($category) as $page) {
                $pages[$page->pageId] = $page;
            }
        }

        return (new TomicaArticles($wiki))->castings(array_values($pages), $summary);
    }

    /**
     * @return list<CastingPage>
     */
    private function miniGt(Wiki $wiki, PageSource $source, ImportSummary $summary): array
    {
        $castings = [];
        $parser = new MiniGtLists($wiki);

        foreach ($source->pages(MiniGtLists::listTitles()) as $list) {
            $this->line(" Reading {$list->title}");
            $castings = [...$castings, ...$parser->castings($list, $summary)];
        }

        return $castings;
    }
}
