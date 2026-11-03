<?php

namespace App\Console\Commands;

use App\Models\ScanDetection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The labelled examples for training collectAI's own recognizer: every car a
 * collector confirmed in a scan *and agreed to share*, as an image plus what it
 * is. Phase 2 of docs/multi-car-recognition.md.
 *
 * Writes DIR/images/<variation>/<detection>.jpg and DIR/manifest.jsonl, one
 * line per image. Nothing from outside collectAI goes in — no scraped or
 * unlicensed images (CLAUDE.md, Data sources).
 */
class ExportTrainingData extends Command
{
    protected $signature = 'catalog:export-training-data {dir : Directory to write into}';

    protected $description = 'Export consented, confirmed scan crops with their labels for training';

    public function handle(): int
    {
        $dir = rtrim($this->argument('dir'), '/');
        @mkdir($dir.'/images', 0775, true);
        $manifest = fopen($dir.'/manifest.jsonl', 'w');
        $written = 0;

        ScanDetection::query()
            ->where('decision', ScanDetection::ADDED)
            ->whereNotNull('crop_path')
            ->whereNotNull('variation_id')
            ->whereHas('scan', fn ($q) => $q->where('consent', true))
            ->with('scan', 'variation.release.castingVersion', 'variation.release.series.brand')
            ->chunkById(500, function ($detections) use ($dir, $manifest, &$written): void {
                foreach ($detections as $d) {
                    $bytes = Storage::disk($d->disk)->get($d->crop_path);

                    if ($bytes === null) {
                        continue;
                    }

                    $file = "images/{$d->variation_id}/{$d->id}.jpg";
                    @mkdir(dirname($dir.'/'.$file), 0775, true);
                    file_put_contents($dir.'/'.$file, $bytes);

                    $variation = $d->variation;
                    fwrite($manifest, json_encode([
                        'image' => $file,
                        'variation_id' => $d->variation_id,
                        'casting_id' => $variation?->release->castingVersion->casting_id,
                        'brand' => $variation?->release->series->brand->name,
                        'colour' => $variation?->color,
                        'colour_family' => $d->colour_family,
                        'wheel_type' => $variation?->rim_type,
                        // How the label was arrived at: a recognizer suggestion the
                        // collector confirmed, or a car they named themselves —
                        // usually a loose car the recognizer couldn't read.
                        'source' => $d->labelled_by_hand ? 'named_by_collector' : 'confirmed_suggestion',
                        'layout' => $d->scan->layout,
                    ], JSON_UNESCAPED_UNICODE)."\n");
                    $written++;
                }
            });

        fclose($manifest);
        $this->info("Wrote {$written} labelled image(s) to {$dir}.");

        return self::SUCCESS;
    }
}
