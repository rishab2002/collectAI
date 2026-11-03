<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Models\ScanDetection;
use App\Services\Recognition\StableKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The labelled photos the visual model learns from (phase 3,
 * docs/multi-car-recognition.md; trained with `python -m train.build`).
 *
 * Three sources, each one we are entitled to use:
 *
 *  - `scan` — cars collectors confirmed or named in a scan *and agreed to
 *    share* (the consent box). Unconsented scans are never exported.
 *  - `own` — catalog photos with no licence attached: the admin's own photos
 *    from Add a car and from approved captures.
 *  - `wiki_licensed` — the wiki photos imported because their file page
 *    carries a reuse licence (FileLicence), with that licence and credit.
 *
 * Unlicensed wiki photos are not a source: Fandom's Terms of Use forbid using
 * its content to train an AI system without Fandom's written consent (see
 * CLAUDE.md, Recognition). Any folder in this same format can be added at
 * training time once such consent exists.
 *
 * Writes DIR/images/<source>/<variation>/<id>.<ext> and DIR/manifest.jsonl.
 */
class ExportTrainingData extends Command
{
    public const SOURCES = ['scan', 'own', 'wiki_licensed'];

    protected $signature = 'catalog:export-training-data
        {dir : Directory to write into}
        {--source=* : Only these sources (scan, own, wiki_licensed); default all}';

    protected $description = 'Export labelled photos — consented scans, own and licensed catalog photos — for training';

    /** @var resource */
    private $manifest;

    private string $dir;

    /** @var array<string, int> */
    private array $written = [];

    public function handle(): int
    {
        $sources = $this->option('source') ?: self::SOURCES;

        if ($unknown = array_diff($sources, self::SOURCES)) {
            $this->error('Unknown source: '.implode(', ', $unknown).'. Use '.implode(', ', self::SOURCES).'.');

            return self::FAILURE;
        }

        $this->dir = rtrim($this->argument('dir'), '/');
        @mkdir($this->dir.'/images', 0775, true);
        $this->manifest = fopen($this->dir.'/manifest.jsonl', 'w');

        if (in_array('scan', $sources, true)) {
            $this->scans();
        }

        if (array_intersect(['own', 'wiki_licensed'], $sources)) {
            $this->photos(in_array('own', $sources, true), in_array('wiki_licensed', $sources, true));
        }

        fclose($this->manifest);

        $total = array_sum($this->written);
        $this->info("Wrote {$total} labelled image(s) to {$this->dir}.");

        foreach ($this->written as $source => $count) {
            $this->line("  {$source}: {$count}");
        }

        return self::SUCCESS;
    }

    private function scans(): void
    {
        ScanDetection::query()
            ->where('decision', ScanDetection::ADDED)
            ->whereNotNull('crop_path')
            ->whereNotNull('variation_id')
            ->whereHas('scan', fn ($q) => $q->where('consent', true))
            ->with('scan', 'variation.release.castingVersion', 'variation.release.series.brand')
            ->chunkById(500, function ($detections): void {
                foreach ($detections as $d) {
                    $variation = $d->variation;

                    $this->write('scan', $d->id, $d->disk, $d->crop_path, $variation, [
                        'colour_family' => $d->colour_family,
                        // How the label was arrived at: a recognizer suggestion
                        // the collector confirmed, or a car they named themselves —
                        // usually a loose car the recognizer couldn't read.
                        'labelled' => $d->labelled_by_hand ? 'named_by_collector' : 'confirmed_suggestion',
                        'layout' => $d->scan->layout,
                    ]);
                }
            });
    }

    private function photos(bool $own, bool $licensed): void
    {
        Photo::query()
            ->when(! $own, fn ($q) => $q->whereNotNull('license'))
            ->when(! $licensed, fn ($q) => $q->whereNull('license'))
            ->with('variation.release.castingVersion', 'variation.release.series.brand')
            ->chunkById(500, function ($photos): void {
                foreach ($photos as $photo) {
                    $this->write($photo->license === null ? 'own' : 'wiki_licensed', $photo->id, $photo->disk, $photo->path, $photo->variation, [
                        'licence' => $photo->license,
                        'credit' => $photo->credit,
                    ]);
                }
            });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function write(string $source, int $id, string $disk, string $path, $variation, array $extra): void
    {
        if ($variation === null || ($bytes = Storage::disk($disk)->get($path)) === null) {
            return;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
        $file = "images/{$source}/{$variation->id}/{$id}.{$extension}";
        @mkdir(dirname($this->dir.'/'.$file), 0775, true);
        file_put_contents($this->dir.'/'.$file, $bytes);

        $castingId = $variation->release->castingVersion->casting_id;

        fwrite($this->manifest, json_encode([
            'image' => $file,
            'source' => $source,
            'variation_id' => $variation->id,
            'casting_id' => $castingId,
            // What the model is labelled with: provenance where there is any,
            // so a model trained on one database serves another (StableKeys).
            'casting_key' => StableKeys::castings([$castingId])[$castingId] ?? 'id:'.$castingId,
            'variation_key' => StableKeys::variations([$variation->id])[$variation->id] ?? 'id:'.$variation->id,
            'brand' => $variation->release->series->brand->name,
            'colour' => $variation->color,
            'wheel_type' => $variation->rim_type,
            ...$extra,
        ], JSON_UNESCAPED_UNICODE)."\n");

        $this->written[$source] = ($this->written[$source] ?? 0) + 1;
    }
}
