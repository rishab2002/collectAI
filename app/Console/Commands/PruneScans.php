<?php

namespace App\Console\Commands;

use App\Models\ScanDetection;
use Illuminate\Console\Command;

/**
 * Deletes the pictures of scans whose owner didn't agree to keep them, once
 * they are older than catalog.recognition.unconsented_hours — the backstop
 * for scans that were never finished.
 */
class PruneScans extends Command
{
    protected $signature = 'catalog:prune-scans';

    protected $description = 'Delete crops from unconsented scans older than the retention window';

    public function handle(): int
    {
        $deleted = 0;

        ScanDetection::query()
            ->whereNotNull('crop_path')
            ->whereHas('scan', fn ($q) => $q
                ->where('consent', false)
                ->where('created_at', '<', now()->subHours(config('catalog.recognition.unconsented_hours'))))
            ->chunkById(500, function ($detections) use (&$deleted): void {
                foreach ($detections as $detection) {
                    $detection->deleteCrop();
                    $deleted++;
                }
            });

        $this->info("Deleted {$deleted} picture(s).");

        return self::SUCCESS;
    }
}
