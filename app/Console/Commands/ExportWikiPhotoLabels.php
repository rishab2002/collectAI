<?php

namespace App\Console\Commands;

use App\Services\Import\FandomWiki\ApiPageSource;
use App\Services\Import\FandomWiki\DumpPageSource;
use App\Services\Import\FandomWiki\TrainingPhotoLabels;
use App\Services\Import\FandomWiki\Wiki;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Lists every wiki photo of an imported car with its label, for the trainer to
 * download (`python -m train.collect`). Training only — see TrainingPhotoLabels.
 */
class ExportWikiPhotoLabels extends Command
{
    protected $signature = 'catalog:export-wiki-photo-labels
        {wiki : hot-wheels or matchbox}
        {--dump= : The wiki dump (.xml or .xml.7z); without it, pages are read from the API}
        {--out= : Where to write the JSONL (default: storage/app/recognition/wiki-labels-<wiki>.jsonl)}';

    protected $description = 'List wiki photos of imported cars with their labels, for training the visual model';

    public function handle(): int
    {
        try {
            $wiki = Wiki::fromKey((string) $this->argument('wiki'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $out = $this->option('out') ?: storage_path("app/recognition/wiki-labels-{$wiki->key}.jsonl");
        @mkdir(dirname($out), 0775, true);
        $pages = $this->option('dump') !== null ? new DumpPageSource($this->option('dump')) : new ApiPageSource($wiki->apiEndpoint());

        $handle = fopen($out, 'w');
        $count = $withVariation = 0;
        $castings = [];

        foreach ((new TrainingPhotoLabels($wiki))->labels($pages) as $label) {
            fwrite($handle, json_encode($label, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $count++;
            $withVariation += $label['variation_id'] !== null ? 1 : 0;
            $castings[$label['casting_id']] = true;
        }

        fclose($handle);

        $this->info(sprintf('Wrote %s: %d photos of %d castings (%d labelled down to the exact version).', $out, $count, count($castings), $withVariation));
        $this->line(' Training only: nothing here is added to the site. Next: python -m train.collect (README §5.10).');

        return self::SUCCESS;
    }
}
