<?php

namespace App\Services\Import\HotWheelsWiki;

/**
 * Where the importer gets wikitext from.
 *
 * Two implementations, for two genuinely different jobs. The dump is the bulk
 * path — one 16 MB download for all 11,041 pages, which is the whole reason the
 * import isn't a crawler. The API is the delta path: the dump is a snapshot with
 * a date on it, and catching up on what changed since is a handful of requests,
 * not another full download.
 *
 * Behind one interface so the importer doesn't know or care which it was handed,
 * and so a run can start from the dump and finish against the API.
 */
interface PageSource
{
    /**
     * A single page by title, or null if the source doesn't have it.
     */
    public function page(string $title): ?WikiPage;

    /**
     * The named pages, in whatever order the source can produce them most
     * cheaply — the dump yields them in the order it stores them, which is not
     * the order they were asked for.
     *
     * Returns an iterable rather than an array because the dump is streamed: a
     * bulk import must not depend on holding every page in memory at once.
     *
     * @param  list<string>  $titles
     * @return iterable<WikiPage>
     */
    public function pages(array $titles): iterable;
}
