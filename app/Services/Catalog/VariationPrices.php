<?php

namespace App\Services\Catalog;

use App\Models\PriceHistory;
use Illuminate\Support\Collection;

/**
 * The current price of each variation: its most recent observation.
 */
final class VariationPrices
{
    /**
     * @param  iterable<int>  $variationIds
     * @return Collection<int, PriceHistory> keyed by variation id
     */
    public function latestFor(iterable $variationIds): Collection
    {
        $ids = collect($variationIds)->map(fn ($id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        // Postgres DISTINCT ON: the newest row per variation in one pass.
        return PriceHistory::query()
            ->fromRaw('(SELECT DISTINCT ON (variation_id) * FROM price_histories WHERE variation_id IN ('.$ids->implode(',').') ORDER BY variation_id, observed_at DESC, id DESC) AS price_histories')
            ->get()
            ->keyBy('variation_id');
    }
}
