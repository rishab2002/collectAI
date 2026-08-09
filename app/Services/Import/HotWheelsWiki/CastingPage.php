<?php

namespace App\Services\Import\HotWheelsWiki;

use App\Models\RecordSource;

/**
 * A casting article, read down to the facts the catalog stores: the tool's name,
 * and every version of it the wiki lists.
 */
final class CastingPage
{
    /**
     * @param  list<VersionRow>  $versions
     */
    public function __construct(
        public readonly string $title,
        public readonly int $pageId,
        public readonly ?int $revisionId,
        public readonly string $name,
        public readonly ?string $designer,
        public readonly array $versions,
    ) {}

    /**
     * Provenance key for the casting itself. Its releases and variations key off
     * this, so the page and its rows can't drift apart.
     */
    public function sourceKey(): string
    {
        return RecordSource::pageKey($this->pageId);
    }

    public function sourceUrl(): string
    {
        return 'https://hotwheels.fandom.com/wiki/'.str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $this->title)));
    }

    /**
     * The rows worth importing, in table order.
     *
     * @return list<VersionRow>
     */
    public function importableVersions(): array
    {
        return array_values(array_filter(
            $this->versions,
            static fn (VersionRow $version): bool => $version->isImportable(),
        ));
    }

    /**
     * @return list<int>
     */
    public function years(): array
    {
        $years = array_unique(array_map(
            static fn (VersionRow $version): int => (int) $version->year,
            $this->importableVersions(),
        ));

        sort($years);

        return array_values($years);
    }
}
