<?php

namespace App\Console\Commands;

use App\Models\ReferenceItem;
use App\Services\Reference\PoliteClient;
use App\Services\Reference\ReferenceSources;
use Illuminate\Console\Command;

/**
 * Reads the permitted reference sources into reference_items — for training
 * and for the Add a car form's suggestions. Never public. Re-running updates
 * what exists and adds what's new.
 */
class ImportReference extends Command
{
    protected $signature = 'catalog:import-reference
        {source?* : hwpriceguide, tsm, inno64, para64, horizon (default: all)}
        {--limit= : Stop after this many products per source}
        {--rate=2 : Requests per second per site}';

    protected $description = 'Import product listings from permitted sources into the private reference list';

    public function handle(): int
    {
        $sources = $this->argument('source') ?: ReferenceSources::KEYS;
        $client = new PoliteClient((float) $this->option('rate'));
        $reader = new ReferenceSources($client);

        foreach ($sources as $source) {
            $before = $client->requests;
            $count = 0;

            try {
                foreach ($reader->items($source, $this->option('limit') ? (int) $this->option('limit') : null) as $item) {
                    [$item['brand'], $item['line']] = ReferenceSources::normaliseBrand($item['brand'], $item['line']);
                    ReferenceItem::updateOrCreate(
                        ['source' => $source, 'source_key' => mb_substr($item['source_key'], 0, 255)],
                        collect($item)->except('source_key')->map(fn ($v) => is_string($v) ? mb_substr($v, 0, 255) : $v)->all(),
                    );
                    $count++;
                }
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info(sprintf('%s: %d products from %d requests.', ReferenceItem::SOURCES[$source] ?? $source, $count, $client->requests - $before));
        }

        return self::SUCCESS;
    }
}
