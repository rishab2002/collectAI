<?php

namespace App\Console\Commands;

use App\Services\Import\FandomWiki\Wiki;

/**
 * `catalog:import-wiki hot-wheels`, under the name it has always had.
 *
 * Kept because the runbooks and CLAUDE.md say `catalog:import-hot-wheels
 * --year=2012`, and a command that disappears under them is a worse problem
 * than an alias. Everything else lives in ImportFandomWiki.
 */
class ImportHotWheelsWiki extends ImportFandomWiki
{
    protected $signature = 'catalog:import-hot-wheels
        {--year= : One year index to import (default 2011 when no range is given)}
        {--from= : First year of a range}
        {--to= : Last year of a range}
        {--dump= : Path to the wiki XML dump (.7z or a decompressed .xml). Without it, the API is used}
        {--limit= : Stop after this many casting pages, for a trial run}
        {--dry-run : Import inside a transaction that is rolled back, reporting what a real run would do}';

    protected $description = 'Import Hot Wheels castings, releases and variations from the Fandom wiki, by year';

    protected function wiki(): Wiki
    {
        return Wiki::hotWheels();
    }
}
