<?php

namespace App\Services\Import\HotWheelsWiki;

/**
 * A casting sold in one series in one year, with every variation of it the page
 * lists.
 *
 * The grouping mirrors the releases table's natural key (casting version +
 * series + year) rather than the table's visual rows, because the wiki writes
 * one release as a rowspan block: shared cells carried down, one row per wheel
 * or colour. It is also the grouping the source data forces — the 2011
 * Nightburnerz Nissan 350Z is one release holding two toy numbers.
 */
readonly class ParsedRelease
{
    /**
     * @param  list<ParsedVariation>  $variations
     */
    public function __construct(
        public int $year,
        public string $seriesName,
        public ?string $seriesTarget = null,
        public ?string $seriesPosition = null,
        public ?string $collectorNumber = null,
        public array $variations = [],
    ) {}

    /**
     * The page a series cell links to, which is how two years' tables agree on a
     * series that they label differently. Falls back to the visible name for the
     * rows that write the series as bare text.
     */
    public function seriesIdentity(): string
    {
        return $this->seriesTarget ?? $this->seriesName;
    }

    /**
     * @param  list<ParsedVariation>  $variations
     */
    public function withVariations(array $variations): self
    {
        return new self(
            $this->year,
            $this->seriesName,
            $this->seriesTarget,
            $this->seriesPosition,
            $this->collectorNumber,
            $variations,
        );
    }
}
