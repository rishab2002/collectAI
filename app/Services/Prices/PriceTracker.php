<?php

namespace App\Services\Prices;

use App\Models\PriceCheck;
use App\Models\PriceHistory;
use App\Models\Variation;
use App\Services\Import\ImportSummary;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;

/**
 * Keeps each published car's price about a month fresh.
 *
 * Every day it takes the variations whose last check is oldest (never-checked
 * first) and prices them until the day's API budget is spent, so the whole
 * catalog cycles through roughly every `refresh_days` without bursts. Each
 * variation gets one price per check, from the first source that has one:
 * eBay sold, then (if enabled) eBay asking, then MSRP.
 */
final class PriceTracker
{
    public function __construct(private readonly EbayClient $ebay) {}

    /**
     * @return Collection<int, Variation>
     */
    public function due(int $limit): Collection
    {
        $cutoff = now()->subDays((int) config('catalog.prices.refresh_days'));

        return Variation::query()
            ->select('variations.*')
            ->where('variations.status', Variation::STATUS_LIVE)
            ->leftJoin('price_checks', 'price_checks.variation_id', '=', 'variations.id')
            ->where(fn ($q) => $q->whereNull('price_checks.checked_at')->orWhere('price_checks.checked_at', '<', $cutoff))
            ->orderByRaw('price_checks.checked_at ASC NULLS FIRST')
            ->orderBy('variations.id')
            ->limit($limit)
            ->with(['release.series.brand', 'release.series.parent', 'release.castingVersion.casting'])
            ->get();
    }

    public function run(?int $limit = null, bool $msrpOnly = false, bool $dryRun = false): ImportSummary
    {
        $summary = new ImportSummary;
        $budget = (int) config('catalog.prices.daily_budget');
        $useEbay = ! $msrpOnly && $this->ebay->configured()
            && (config('catalog.prices.ebay.insights') || config('catalog.prices.ebay.listings_fallback'));

        if (! $useEbay && ! $msrpOnly) {
            $summary->problem('eBay is not set up (EBAY_CLIENT_ID / EBAY_CLIENT_SECRET, and EBAY_INSIGHTS or EBAY_LISTINGS_FALLBACK) — only MSRP prices will be found.');
        }

        foreach ($this->due($limit ?? $budget) as $variation) {
            if ($useEbay && $this->ebay->calls >= $budget) {
                $summary->record('stopped_at_budget');

                break;
            }

            $callsBefore = $this->ebay->calls;

            try {
                $quote = ($useEbay ? $this->fromEbay($variation) : null) ?? $this->msrp($variation);
            } catch (RequestException $e) {
                // A rejected credential won't fix itself on the next car.
                if (in_array($e->response->status(), [401, 403], true)) {
                    throw $e;
                }

                $summary->problem("variation {$variation->id}: eBay error {$e->response->status()}");
                $this->check($variation, 'error', $this->ebay->calls - $callsBefore, $dryRun);

                continue;
            }

            $this->record($variation, $quote, $this->ebay->calls - $callsBefore, $dryRun);
            $summary->record('priced.'.($quote->kind ?? 'none'));
        }

        $summary->record('ebay_calls', $this->ebay->calls);

        return $summary;
    }

    private function fromEbay(Variation $variation): ?PriceQuote
    {
        $sample = (int) config('catalog.prices.sample');
        $currency = config('catalog.prices.ebay.currency');

        foreach ($this->queries($variation) as $query) {
            if (config('catalog.prices.ebay.insights')) {
                $sales = $this->relevant($this->ebay->sold($query), $variation, $currency);

                if ($sales !== []) {
                    $used = array_slice($sales, 0, $sample);

                    return new PriceQuote($this->median(array_column($used, 'price')), $currency, PriceHistory::KIND_SOLD, count($used), 'eBay', EbayClient::searchUrl($query, sold: true));
                }
            }

            if (config('catalog.prices.ebay.listings_fallback')) {
                $listings = $this->relevant($this->ebay->listings($query), $variation, $currency);

                if ($listings !== []) {
                    $used = array_slice($listings, 0, $sample);

                    return new PriceQuote($this->median(array_column($used, 'price')), $currency, PriceHistory::KIND_LISTING, count($used), 'eBay', EbayClient::searchUrl($query, sold: false));
                }
            }
        }

        return null;
    }

    /**
     * Searches from most to least specific. The toy number, where known, is
     * the sharpest key a listing can carry; the year and colour narrow a
     * nameplate that was sold for decades.
     *
     * @return list<string>
     */
    public function queries(Variation $variation): array
    {
        $brand = $variation->release->series->brand->name;
        $casting = $variation->release->castingVersion->casting->name;
        $queries = [];

        if (filled($variation->toy_number) && preg_match('/^[A-Z]{1,4}\d{2,5}$/', $variation->toy_number) === 1) {
            $queries[] = "{$brand} {$casting} {$variation->toy_number}";
        }

        $queries[] = trim("{$brand} {$casting} {$variation->release->year} ".($variation->color ?? ''));

        return array_values(array_unique($queries));
    }

    /**
     * Results that are plausibly this car: in the right currency, naming every
     * significant word of the casting, and not a lot or a bundle (whose price
     * is for several cars).
     *
     * @param  list<array{title: string, price: float, currency: string}>  $items
     * @return list<array{title: string, price: float, currency: string}>
     */
    private function relevant(array $items, Variation $variation, string $currency): array
    {
        $words = array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower($variation->release->castingVersion->casting->name)) ?: [],
            fn (string $word): bool => mb_strlen($word) > 2,
        );

        return array_values(array_filter($items, function (array $item) use ($words, $currency): bool {
            $title = mb_strtolower($item['title']);

            if ($item['currency'] !== $currency || $item['price'] <= 0 || preg_match('/\b(lot|lots|bundle|set of|collection of)\b/', $title) === 1) {
                return false;
            }

            foreach ($words as $word) {
                if (! str_contains($title, $word)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function msrp(Variation $variation): ?PriceQuote
    {
        $series = $variation->release->series;
        $line = $series->parent->name ?? $series->name;
        $entry = config("catalog_prices.{$series->brand->name}.{$line}");

        return is_array($entry) && isset($entry['price'])
            ? new PriceQuote((float) $entry['price'], $entry['currency'] ?? 'USD', PriceHistory::KIND_MSRP, 1, 'MSRP')
            : null;
    }

    /**
     * @param  list<float>  $prices
     */
    private function median(array $prices): float
    {
        sort($prices);
        $count = count($prices);
        $middle = intdiv($count, 2);

        return round($count % 2 === 1 ? $prices[$middle] : ($prices[$middle - 1] + $prices[$middle]) / 2, 2);
    }

    private function record(Variation $variation, ?PriceQuote $quote, int $calls, bool $dryRun): void
    {
        $this->check($variation, $quote->kind ?? 'none', $calls, $dryRun);

        if ($quote === null || $dryRun) {
            return;
        }

        $latest = $variation->priceHistories()->latest('observed_at')->first();

        // An unchanged MSRP is the same fact again, not a new observation.
        if ($quote->kind === PriceHistory::KIND_MSRP && $latest?->kind === PriceHistory::KIND_MSRP && (float) $latest->price === $quote->price) {
            return;
        }

        $variation->priceHistories()->create([
            'price' => $quote->price,
            'currency' => $quote->currency,
            'kind' => $quote->kind,
            'sample_size' => $quote->sampleSize,
            'source' => $quote->source,
            'url' => $quote->url,
            'observed_at' => now(),
        ]);
    }

    private function check(Variation $variation, string $outcome, int $calls, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        PriceCheck::updateOrCreate(
            ['variation_id' => $variation->getKey()],
            ['checked_at' => now(), 'outcome' => $outcome, 'calls' => $calls],
        );
    }
}
