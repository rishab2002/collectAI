<?php

namespace App\Services\Catalog;

use App\Models\Variation;

/**
 * What a manual entry produced, and whether it went live.
 */
final class ManualEntryResult
{
    /**
     * @param  list<string>  $blockers  Why the variation was saved but held back. Empty when it is live.
     */
    public function __construct(
        public readonly Variation $variation,
        public readonly array $blockers = [],
        /** True when the car was deliberately held for review rather than published. */
        public readonly bool $queued = false,
    ) {}

    public function isLive(): bool
    {
        return ! $this->queued && $this->blockers === [];
    }
}
