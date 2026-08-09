<?php

namespace App\Services\Import\HotWheelsWiki;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Pages read from the wiki's MediaWiki API.
 *
 * The delta path, and the way to resolve the handful of pages the dump can't
 * answer for — a redirect target, a page edited since the dump was cut. Not the
 * way to import the catalog: that is what the dump is for, and asking this for
 * 11,041 pages would be a crawler with extra steps.
 *
 * Titles are batched to the API's limit, so a year's worth of castings is a
 * handful of requests rather than one per car.
 */
final class ApiPageSource implements PageSource
{
    /**
     * The API's cap for an anonymous client. Exceeding it doesn't error, it
     * silently truncates the batch, so this is a limit to respect rather than
     * discover.
     */
    private const BATCH = 50;

    public function __construct(
        private readonly string $endpoint = 'https://hotwheels.fandom.com/api.php',
        private readonly string $userAgent = 'collectAI catalog importer (https://collectai.club)',
    ) {}

    public function page(string $title): ?WikiPage
    {
        foreach ($this->pages([$title]) as $page) {
            return $page;
        }

        return null;
    }

    public function pages(array $titles): iterable
    {
        foreach (array_chunk(array_values($titles), self::BATCH) as $batch) {
            $response = $this->request()->get($this->endpoint, [
                'action' => 'query',
                'prop' => 'revisions',
                'rvprop' => 'content|ids',
                'rvslots' => 'main',
                // The API's own separator. A title containing one would break
                // this, and MediaWiki forbids the character in titles for
                // exactly that reason.
                'titles' => implode('|', $batch),
                'format' => 'json',
                'formatversion' => '2',
            ])->throw();

            foreach ($response->json('query.pages', []) as $page) {
                $wikitext = data_get($page, 'revisions.0.slots.main.content');

                // A title the wiki doesn't have comes back flagged rather than
                // omitted, so this is the normal shape of "no such page".
                if (($page['missing'] ?? false) || ! is_string($wikitext)) {
                    continue;
                }

                yield new WikiPage(
                    title: $page['title'],
                    pageId: (int) $page['pageid'],
                    revisionId: data_get($page, 'revisions.0.revid'),
                    wikitext: $wikitext,
                );
            }
        }
    }

    /**
     * Identifies the importer to the wiki. Fandom's terms ask for a real user
     * agent, and an anonymous bulk reader that doesn't say who it is is the kind
     * that gets a whole netblock blocked.
     */
    private function request(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->acceptJson()
            ->retry(3, 500, throw: false)
            ->timeout(30);
    }
}
