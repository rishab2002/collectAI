<?php

namespace App\Services\Import\HotWheelsWiki;

/**
 * One page as a source hands it over, before anything has been read out of it.
 *
 * The revision id is what a re-import compares against to decide whether a page
 * has moved since it was last read, so it travels with the text rather than
 * being looked up separately.
 */
final class WikiPage
{
    public function __construct(
        public readonly string $title,
        public readonly int $pageId,
        public readonly ?int $revisionId,
        public readonly string $wikitext,
    ) {}

    /**
     * The article this page redirects to, or null if it is a real article.
     */
    public function redirectsTo(): ?string
    {
        return CastingPageParser::redirectTarget($this->wikitext);
    }
}
