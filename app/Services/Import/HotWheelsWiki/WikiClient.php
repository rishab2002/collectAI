<?php

namespace App\Services\Import\HotWheelsWiki;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads pages from the Hot Wheels wiki's MediaWiki API.
 *
 * A note on the settled "use the dump, not a crawler" decision, which this does
 * not contradict but also does not yet implement. That decision is about
 * importing the whole wiki: 11,041 articles one request at a time is the crawl
 * it rules out, against one 16.5 MB download. Importing a single year — the
 * settled starting point — is a different shape: the 2011 index names roughly
 * 200 distinct castings, and batched 50 titles to a request that is five calls,
 * not eleven thousand. The dump reader is what the full-wiki run needs and is
 * genuinely not built yet; this is the right tool for the year-at-a-time case
 * the importer starts from, and the parsers above it read wikitext either way.
 */
class WikiClient
{
    public function __construct(
        private readonly ?string $api = null,
        private readonly ?string $baseUrl = null,
        private readonly ?string $userAgent = null,
    ) {}

    /**
     * One page's wikitext and identifiers, or null if the wiki has no such page.
     *
     * @return array{pageid: int, title: string, revid: int|null, wikitext: string, url: string}|null
     */
    public function page(string $title): ?array
    {
        return $this->pages([$title])[$title] ?? null;
    }

    /**
     * Several pages in one request, keyed by the title asked for.
     *
     * Keyed by the requested title rather than the returned one because the API
     * normalises as it goes — an underscore becomes a space, the first letter is
     * capitalised — and a caller holding a list of link targets needs to find
     * what it asked for. Redirects are followed, so the returned `title` may
     * differ from the key; that difference is the point of keeping both.
     *
     * @param  list<string>  $titles
     * @return array<string, array{pageid: int, title: string, revid: int|null, wikitext: string, url: string}>
     */
    public function pages(array $titles): array
    {
        $titles = array_values(array_unique(array_filter($titles)));

        if ($titles === []) {
            return [];
        }

        if (count($titles) > $this->batchSize()) {
            throw new RuntimeException(
                'Asked for '.count($titles).' titles in one request; the API silently drops anything past '.$this->batchSize().'. Use eachPage() instead.'
            );
        }

        $response = $this->request()->get($this->api(), [
            'action' => 'query',
            'prop' => 'revisions',
            'rvprop' => 'content|ids',
            'rvslots' => 'main',
            'redirects' => 1,
            'format' => 'json',
            'formatversion' => 2,
            'titles' => implode('|', $titles),
        ])->throw()->json();

        return $this->index($titles, $response ?? []);
    }

    /**
     * Every page, fetched in batches, yielded one at a time.
     *
     * A generator so a year's import streams: 200 casting articles held as
     * wikitext at once is tens of megabytes for no reason, and a failure part
     * way through has already imported what it read.
     *
     * @param  list<string>  $titles
     * @return \Generator<string, array{pageid: int, title: string, revid: int|null, wikitext: string, url: string}>
     */
    public function eachPage(array $titles): \Generator
    {
        $batches = array_chunk(array_values(array_unique(array_filter($titles))), $this->batchSize());

        foreach ($batches as $index => $batch) {
            if ($index > 0 && ($delay = $this->delayMs()) > 0) {
                usleep($delay * 1000);
            }

            foreach ($this->pages($batch) as $requested => $page) {
                yield $requested => $page;
            }
        }
    }

    /**
     * @param  list<string>  $requested
     * @return array<string, array{pageid: int, title: string, revid: int|null, wikitext: string, url: string}>
     */
    private function index(array $requested, array $response): array
    {
        $query = $response['query'] ?? [];

        // The API reports its own title rewriting in these two lists, which is
        // the only way back from what it returned to what was asked for.
        $aliases = [];

        foreach (['normalized', 'redirects'] as $step) {
            foreach ($query[$step] ?? [] as $mapping) {
                $aliases[$mapping['to']] = $aliases[$mapping['from']] ?? $mapping['from'];
            }
        }

        $pages = [];

        foreach ($query['pages'] ?? [] as $page) {
            if (($page['missing'] ?? false) || ! isset($page['pageid'])) {
                continue;
            }

            $revision = $page['revisions'][0] ?? null;
            $wikitext = $revision['slots']['main']['content'] ?? $revision['slots']['main']['*'] ?? null;

            if ($wikitext === null) {
                continue;
            }

            $key = $aliases[$page['title']] ?? $page['title'];

            $pages[$key] = [
                'pageid' => (int) $page['pageid'],
                'title' => $page['title'],
                'revid' => isset($revision['revid']) ? (int) $revision['revid'] : null,
                'wikitext' => $wikitext,
                'url' => $this->urlFor((int) $page['pageid']),
            ];
        }

        // Anything the API didn't rewrite and didn't return simply isn't there.
        foreach ($requested as $title) {
            if (! isset($pages[$title]) && isset($pages[trim($title)])) {
                $pages[$title] = $pages[trim($title)];
            }
        }

        return $pages;
    }

    /**
     * Addressed by page id rather than by title. A title is editable and pages
     * get renamed; the id is what the row was actually imported from, and an
     * attribution link that rots is worse than none.
     */
    public function urlFor(int $pageId): string
    {
        return rtrim($this->baseUrl(), '/')."/?curid={$pageId}";
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => $this->userAgent()])
            ->acceptJson()
            ->timeout(30)
            ->retry(3, 500, throw: false);
    }

    private function api(): string
    {
        return $this->api ?? config('catalog.import.hot_wheels_wiki.api');
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?? config('catalog.import.hot_wheels_wiki.base_url');
    }

    private function userAgent(): string
    {
        return $this->userAgent ?? config('catalog.import.hot_wheels_wiki.user_agent');
    }

    private function batchSize(): int
    {
        return max(1, (int) config('catalog.import.hot_wheels_wiki.batch_size', 50));
    }

    private function delayMs(): int
    {
        return max(0, (int) config('catalog.import.hot_wheels_wiki.delay_ms', 0));
    }
}
