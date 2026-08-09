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
     * The distinct tools this page describes, in the order it describes them.
     *
     * Usually one — a single unlabelled Versions table, so a single null. A page
     * that files its releases under "Closed Hood Versions" and "Exposed Engine
     * Versions" is describing two physical tools, which is a CastingVersion each
     * rather than a note on one.
     *
     * @return list<string|null>
     */
    public function toolingLabels(): array
    {
        $labels = [];

        foreach ($this->importableVersions() as $version) {
            $labels[$version->toolingLabel ?? ''] = $version->toolingLabel;
        }

        return array_values($labels);
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
