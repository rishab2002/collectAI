<?php

namespace App\Console\Commands;

use App\Models\ReferenceItem;
use App\Services\Reference\ReferenceLabels;
use Illuminate\Console\Command;

/**
 * One line per reference image with its training label, for
 * `python -m train.collect` (which downloads, embeds and discards each image).
 * Training only — see the reference_items migration.
 */
class ExportReferenceLabels extends Command
{
    protected $signature = 'catalog:export-reference-labels
        {--out= : JSONL to write (default: storage/app/recognition/reference-labels.jsonl)}
        {--images=1 : Images per product — the first is the main shot; more add angles but cost a request each}
        {--source=* : Only these sources}';

    protected $description = 'List reference-product images with their training labels';

    public function handle(ReferenceLabels $labels): int
    {
        $out = $this->option('out') ?: storage_path('app/recognition/reference-labels.jsonl');
        @mkdir(dirname($out), 0775, true);
        $handle = fopen($out, 'w');
        $images = max(1, (int) $this->option('images'));
        $rows = $matched = 0;

        // Brands the catalog lacks first, so a stopped collection has already
        // covered what the wiki photos don't.
        $order = ['tsm', 'inno64', 'para64', 'horizon', 'hwpriceguide'];

        foreach ($order as $source) {
            if ($this->option('source') && ! in_array($source, $this->option('source'), true)) {
                continue;
            }

            ReferenceItem::query()->where('source', $source)->orderBy('id')->chunkById(1000, function ($items) use ($handle, $labels, $images, &$rows, &$matched): void {
                foreach ($items as $item) {
                    $label = $labels->for($item);
                    $matched += $label['matched'] ? 1 : 0;

                    foreach (array_slice($item->image_urls ?? [], 0, $images) as $url) {
                        fwrite($handle, json_encode([
                            'image_url' => $url,
                            'source' => 'ref_'.$item->source,
                            'casting_key' => $label['casting_key'],
                            'variation_key' => $label['variation_key'],
                            'brand' => $item->brand,
                            'name' => $item->name,
                            'page_url' => $item->page_url,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                        $rows++;
                    }
                }
            });
        }

        fclose($handle);
        $this->info("Wrote {$out}: {$rows} images; {$matched} products tied to an existing catalog casting.");

        return self::SUCCESS;
    }
}
