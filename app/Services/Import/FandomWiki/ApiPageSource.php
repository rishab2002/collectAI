<?php

namespace App\Services\Import\FandomWiki;

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
final class ApiPageSource implements CategorySource, PageSource
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
    /**
     * Every article in a category, fifty a request, with its content and its
     * categories. Two steps — list the members, then read them — because the
     * API pages a category's members and a page's categories independently,
     * and merging both continuations in one loop is how pages lose categories.
     *
     * @return iterable<WikiPage>
     */
    public function categoryMembers(string $category): iterable
    {
        $titles = [];
        $continue = [];

        do {
            $response = $this->request()->get($this->endpoint, [
                'action' => 'query',
                'list' => 'categorymembers',
                'cmtitle' => 'Category:'.$category,
                'cmnamespace' => 0,
                'cmlimit' => 500,
                'format' => 'json',
                'formatversion' => '2',
                ...$continue,
            ])->throw();

            foreach ($response->json('query.categorymembers', []) as $member) {
                $titles[] = (string) $member['title'];
            }

            $continue = $response->json('continue', []);
        } while ($continue !== []);

        foreach (array_chunk($titles, self::BATCH) as $batch) {
            $pages = [];
            $continue = [];

            // A page's categories can spill into a continuation even when its
            // content arrived; keep asking until the batch is whole.
            do {
                $response = $this->request()->get($this->endpoint, [
                    'action' => 'query',
                    'prop' => 'revisions|categories',
                    'rvprop' => 'content|ids',
                    'rvslots' => 'main',
                    'cllimit' => 'max',
                    'titles' => implode('|', $batch),
                    'format' => 'json',
                    'formatversion' => '2',
                    ...$continue,
                ])->throw();

                foreach ($response->json('query.pages', []) as $page) {
                    if ($page['missing'] ?? false) {
                        continue;
                    }

                    $entry = $pages[$page['pageid']] ?? ['page' => $page, 'categories' => []];

                    if (isset($page['revisions'])) {
                        $entry['page'] = $page;
                    }

                    foreach ($page['categories'] ?? [] as $categoryRow) {
                        $entry['categories'][] = preg_replace('/^Category:/', '', (string) $categoryRow['title']);
                    }

                    $pages[$page['pageid']] = $entry;
                }

                $continue = $response->json('continue', []);
            } while ($continue !== []);

            foreach ($pages as $entry) {
                $wikitext = data_get($entry['page'], 'revisions.0.slots.main.content');

                if (! is_string($wikitext)) {
                    continue;
                }

                yield new WikiPage(
                    title: $entry['page']['title'],
                    pageId: (int) $entry['page']['pageid'],
                    revisionId: data_get($entry['page'], 'revisions.0.revid'),
                    wikitext: $wikitext,
                    categories: array_values(array_unique($entry['categories'])),
                );
            }
        }
    }

    private function request(): PendingRequest
    {
        return Http::withUserAgent($this->userAgent)
            ->acceptJson()
            ->retry(3, 500, throw: false)
            ->timeout(30);
    }
}
