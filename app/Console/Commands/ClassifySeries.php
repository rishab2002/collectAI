<?php

namespace App\Console\Commands;

use App\Services\Catalog\SeriesClassifier;
use App\Services\Import\FandomWiki\ApiPageSource;
use App\Services\Import\FandomWiki\DumpPageSource;
use App\Services\Import\FandomWiki\Wiki;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Places every imported series under a main line (config/catalog_lines.php).
 *
 * Run after an import. Idempotent: a series already placed is left alone —
 * including one an admin placed by hand — unless --reclassify is given, which
 * is what to use after editing the rules.
 */
class ClassifySeries extends Command
{
    protected $signature = 'catalog:classify-series
        {wiki=hot-wheels : Which wiki — hot-wheels or matchbox}
        {--dump= : Path to the wiki XML dump. Without it, the API is used}
        {--reclassify : Re-place series that already have a line}
        {--dry-run : Report where everything would land, change nothing}';

    protected $description = 'Group imported series under main lines for browsing';

    public function handle(): int
    {
        try {
            $wiki = Wiki::fromKey((string) $this->argument('wiki'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $source = $this->option('dump') !== null
            ? new DumpPageSource($this->option('dump'))
            : new ApiPageSource($wiki->apiEndpoint());

        $report = (new SeriesClassifier($wiki))->classify(
            $source,
            reclassify: (bool) $this->option('reclassify'),
            dryRun: (bool) $this->option('dry-run'),
        );

        $total = array_sum($report['lines']);

        $this->table(['Line', 'Releases', 'Share'], array_map(
            fn (string $line, int $count): array => [$line, $count, $total > 0 ? round(100 * $count / $total, 1).'%' : '–'],
            array_keys($report['lines']),
            $report['lines'],
        ));

        if ($this->option('dry-run')) {
            $this->warn(' Dry run — nothing was changed.');
        } else {
            $this->info(sprintf(
                ' Placed %d series; %d are lines themselves; %d already placed and kept.',
                $report['placed'],
                $report['already_lines'],
                $report['kept'],
            ));
        }

        // What to read before adding a rule: the biggest things left over.
        if ($report['other_categories'] !== []) {
            $this->newLine();
            $this->line(' Most common categories on series left in Other (by releases):');

            foreach (array_slice($report['other_categories'], 0, 15, true) as $category => $count) {
                $this->line(sprintf('  %6d  %s', $count, $category));
            }
        }

        if ($report['other_names'] !== []) {
            $this->newLine();
            $this->line(' Largest series left in Other:');

            foreach (array_slice($report['other_names'], 0, 15, true) as $name => $count) {
                $this->line(sprintf('  %6d  %s', $count, $name));
            }
        }

        return self::SUCCESS;
    }
}
