<?php

namespace App\Services\Prices;

/**
 * One price a source found for one variation.
 */
final class PriceQuote
{
    public function __construct(
        public readonly float $price,
        public readonly string $currency,
        /** sold | listing | msrp — PriceHistory::KIND_* */
        public readonly string $kind,
        public readonly int $sampleSize,
        public readonly string $source,
        public readonly ?string $url = null,
    ) {}
}
