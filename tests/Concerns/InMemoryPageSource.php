<?php

namespace Tests\Concerns;

use App\Services\Import\FandomWiki\PageSource;
use App\Services\Import\FandomWiki\WikiPage;

/**
 * A page source over a fixed set of pages, matching titles the way the dump
 * does (case-insensitive, underscores as spaces). Lets a test give a service
 * exactly the wikitext it needs without HTTP or an archive.
 */
final class InMemoryPageSource implements PageSource
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  array<string, string>  $pages  title => wikitext
     */
    public function __construct(private readonly array $pages) {}

    public function page(string $title): ?WikiPage
    {
        foreach ($this->pages([$title]) as $page) {
            return $page;
        }

        return null;
    }

    public function pages(array $titles): iterable
    {
        $this->asked = [...$this->asked, ...$titles];
        $wanted = array_flip(array_map(fn (string $title): string => $this->normalize($title), $titles));
        $id = 0;

        foreach ($this->pages as $title => $wikitext) {
            $id++;

            if (isset($wanted[$this->normalize($title)])) {
                yield new WikiPage(title: $title, pageId: $id, revisionId: $id, wikitext: $wikitext);
            }
        }
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim(str_replace('_', ' ', $title)));
    }
}
