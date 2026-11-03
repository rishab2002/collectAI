<?php

namespace App\Services\Import\FandomWiki;

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
        ?Wiki $wiki = null,
        /**
         * The infobox `image` — the one picture the article chose to represent
         * the casting, as a file name.
         */
        public readonly ?string $image = null,
        /**
         * An explicit provenance key, for sources where one casting is not one
         * article: a Tomica Limited Vintage tool is several articles (LV-01a,
         * LV-01b…) keyed by their shared base code, and a Mini GT item is a row
         * in a yearly list keyed by its item number. Null means the article's
         * page id, as for the Hot Wheels and Matchbox wikis.
         */
        private readonly ?string $key = null,
    ) {
        $this->wiki = $wiki ?? Wiki::hotWheels();
    }

    /**
     * The site this page was read from, which decides its URL and the brand its
     * releases were sold under.
     */
    public readonly Wiki $wiki;

    /**
     * Provenance key for the casting itself. Its releases and variations key off
     * this, so the page and its rows can't drift apart.
     */
    public function sourceKey(): string
    {
        return $this->key ?? RecordSource::pageKey($this->pageId);
    }

    public function sourceUrl(): string
    {
        return $this->wiki->articleUrl($this->title);
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
