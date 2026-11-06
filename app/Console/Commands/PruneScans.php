<?php

namespace App\Console\Commands;

use App\Models\ScanDetection;
use App\Models\ScanPhoto;
use Illuminate\Console\Command;

/**
 * Deletes the pictures of scans whose owner didn't agree to keep them, once
 * they are older than catalog.recognition.unconsented_hours — the backstop
 * for scans that were never finished.
 */
class PruneScans extends Command
{
    protected $signature = 'catalog:prune-scans';

    protected $description = 'Delete the pictures of unconsented scans older than the retention window';

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

        // And the photos those crops were cut from, which are kept so a
        // collector can cut a missed package out by hand. Same promise, same
        // window — a prune that took only the crops would leave the whole
        // photo behind, which is the larger half of what was promised.
        ScanPhoto::query()
            ->whereNotNull('path')
            ->whereHas('scan', fn ($q) => $q
                ->where('consent', false)
                ->where('created_at', '<', now()->subHours(config('catalog.recognition.unconsented_hours'))))
            ->chunkById(500, function ($photos) use (&$deleted): void {
                foreach ($photos as $photo) {
                    $photo->deleteFile();
                    $deleted++;
                }
            });

        $this->info("Deleted {$deleted} picture(s).");

        return self::SUCCESS;
    }
}
