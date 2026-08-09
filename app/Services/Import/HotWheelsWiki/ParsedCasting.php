<?php

namespace App\Services\Import\HotWheelsWiki;

/**
 * One casting article: the tool's name, where it came from, and every release
 * the page's ==Versions== table lists.
 */
readonly class ParsedCasting
{
    /**
     * @param  list<ParsedRelease>  $releases
     */
    public function __construct(
        public string $name,
        public int $pageId,
        public string $title,
        public string $url,
        public ?int $revisionId = null,
        public array $releases = [],
    ) {}

    /**
     * The releases sold in one year — the unit the importer runs on, since the
     * settled starting point is a single year rather than the whole wiki.
     *
     * @return list<ParsedRelease>
     */
    public function releasesIn(int $year): array
    {
        return array_values(array_filter(
            $this->releases,
            fn (ParsedRelease $release): bool => $release->year === $year,
        ));
    }
}
