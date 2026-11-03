<?php

namespace App\Console\Commands;

use App\Services\Import\FandomWiki\ApiPageSource;
use App\Services\Import\FandomWiki\DumpPageSource;
use App\Services\Import\FandomWiki\Wiki;
use App\Services\Import\FandomWiki\WikiPhotoImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * One photo per imported casting, from its wiki article — only where the file
 * is licensed for reuse, and credited. See WikiPhotoImporter and FileLicence.
 *
 * Photos land on `catalog.capture.disk` (R2 in production). Run after an
 * import; re-running skips every casting that already has a photo.
 */
class ImportWikiPhotos extends Command
{
    protected $signature = 'catalog:import-wiki-photos
        {wiki=hot-wheels : Which wiki — hot-wheels or matchbox}
        {--dump= : Path to the wiki XML dump, for the casting articles. Without it, the API is used}
        {--limit= : Stop after attaching this many photos}
        {--dry-run : Count what is licensed for reuse; download nothing}';

    protected $description = 'Attach one freely licensed, credited wiki photo to each imported casting';

    public function handle(): int
    {
        try {
            $wiki = Wiki::fromKey((string) $this->argument('wiki'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $pages = $this->option('dump') !== null
            ? new DumpPageSource($this->option('dump'))
            : new ApiPageSource($wiki->apiEndpoint());

        if ($this->option('dry-run')) {
            $this->warn(' Dry run — licences are checked, nothing is downloaded.');
        }

        $summary = (new WikiPhotoImporter($wiki))->import(
            $pages,
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->table(['', 'count'], array_map(
            fn (string $what, int $count): array => [$what, $count],
            array_keys($summary->counts()),
            $summary->counts(),
        ));

        // The number people will ask about, said in words.
        $this->line(sprintf(
            ' %d of the images are licensed for reuse; %d are not and were left alone.',
            $summary->count('images.licensed'),
            $summary->count('images.not_licensed_for_reuse'),
        ));

        foreach (array_slice($summary->problems(), 0, 20) as $problem) {
            $this->warn("  {$problem}");
        }

        return self::SUCCESS;
    }
}
