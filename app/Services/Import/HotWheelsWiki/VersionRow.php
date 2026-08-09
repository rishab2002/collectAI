<?php

namespace App\Services\Import\HotWheelsWiki;

/**
 * One line of a casting article's Versions table: a single sellable variant of
 * the tool, as the wiki states it.
 *
 * Only the structured columns are here. The Notes column and the Photo column
 * are deliberately absent — Notes is editor-written prose and the wiki is
 * CC-BY-SA, so it is the part that is actually copyrightable, and the images are
 * not ours to take either. Leaving them off the DTO means no later code can
 * import them by reaching for a field that happens to be sitting there.
 *
 * Maps onto the catalog as a Release (year + series + collector number) carrying
 * a Variation (everything describing the physical car).
 */
final class VersionRow
{
    /**
     * @param  int  $line  1-based position on the page, which the row's provenance key is built from.
     * @param  string|null  $toolingLabel  Which tooling this row belongs to, when the page splits its versions by one — "Closed Hood", "Exposed Engine". Null when the page has a single Versions table, which is the usual case.
     * @param  string|null  $seriesPage  The series' wiki article, which identifies it across years better than its name does.
     */
    public function __construct(
        public readonly int $line,
        public readonly ?string $toolingLabel,
        public readonly ?int $year,
        public readonly ?string $collectorNumber,
        public readonly ?string $seriesName,
        public readonly ?string $seriesPage,
        public readonly ?string $seriesPosition,
        public readonly ?string $color,
        public readonly ?string $tampo,
        public readonly ?string $baseColor,
        public readonly ?string $baseType,
        public readonly ?string $windowColor,
        public readonly ?string $interiorColor,
        public readonly ?string $wheelType,
        public readonly ?string $toyNumber,
        public readonly ?string $country,
    ) {}

    /**
     * Whether this row says enough to be a release at all.
     *
     * A Versions table picks up blank spacer rows and the occasional row that
     * only carries a note. Without a year there is nothing to key a release on —
     * `releases` is unique on (casting version, series, year) — so those rows are
     * skipped rather than imported as a release in year zero.
     */
    public function isImportable(): bool
    {
        return $this->year !== null && $this->seriesName !== null;
    }

    /**
     * The variation attributes, in the shape Variation's fillable expects.
     *
     * Base *type* (plastic vs metal) has no column on `variations` and is not
     * folded into base_color, since "Black" and "Black / Plastic" would then be
     * two different variations of the same car. It stays on the DTO for whoever
     * adds the column.
     *
     * @return array<string, string|null>
     */
    public function variationAttributes(): array
    {
        return [
            'color' => $this->color,
            'tampo' => $this->tampo,
            'base_color' => $this->baseColor,
            'window_tint' => $this->windowColor,
            'interior_color' => $this->interiorColor,
            'rim_type' => $this->wheelType,
        ];
    }
}
