<?php

namespace App\Console\Commands;

use App\Services\Recognition\RecognitionIndex;
use Illuminate\Console\Command;

/**
 * Writes the catalog file the local recognizer matches against.
 */
class ExportRecognitionIndex extends Command
{
    protected $signature = 'catalog:export-recognition-index
        {--path= : Where to write it (default: catalog.recognition.index_path)}
        {--include-pending : Also include records still in review — for running the recognizer yourself}';

    protected $description = 'Export the catalog as the JSON index the local recognizer reads';

    public function handle(RecognitionIndex $index): int
    {
        $path = $this->option('path') ?: config('catalog.recognition.index_path');
        $counts = $index->write($path, (bool) $this->option('include-pending'));

        $this->info(sprintf(
            'Wrote %s: %s castings, %s variations (%s KB)%s.',
            $path,
            number_format($counts['castings']),
            number_format($counts['variations']),
            number_format($counts['bytes'] / 1024),
            $this->option('include-pending') ? ', pending records included' : ', live records only',
        ));

        if ($counts['variations'] === 0) {
            $this->warn('Nothing is live yet, so the recognizer can find cars but name none. See README §4 (making records live), or pass --include-pending for your own use.');
        }

        return self::SUCCESS;
    }
}
