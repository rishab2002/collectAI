<?php

namespace App\Console\Commands;

use App\Services\Prices\PriceTracker;
use Illuminate\Console\Command;

/**
 * The daily price run (scheduled in routes/console.php). Prices the published
 * cars whose last check is oldest, within the day's eBay call budget, so each
 * refreshes about once a month. Safe to run by hand.
 */
class UpdatePrices extends Command
{
    protected $signature = 'catalog:update-prices
        {--limit= : Price at most this many cars (default: PRICE_DAILY_BUDGET)}
        {--msrp-only : Skip eBay and fill MSRP prices only}
        {--dry-run : Query and report, but record nothing}';

    protected $description = 'Refresh car prices: eBay sold, then eBay asking (if enabled), then MSRP';

    public function handle(PriceTracker $tracker): int
    {
        $summary = $tracker->run(
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            msrpOnly: (bool) $this->option('msrp-only'),
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->table(['', 'count'], array_map(
            fn (string $what, int $count): array => [$what, $count],
            array_keys($summary->counts()),
            $summary->counts(),
        ));

        foreach (array_slice($summary->problems(), 0, 20) as $problem) {
            $this->warn("  {$problem}");
        }

        return self::SUCCESS;
    }
}
