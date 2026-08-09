<?php

namespace Tests\Feature;

use App\Services\Import\HotWheelsWiki\ApiPageSource;
use App\Services\Import\HotWheelsWiki\DumpPageSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The two ways the importer gets wikitext.
 *
 * Both are exercised for real: the API against a faked transport that asserts
 * the request actually sent, the dump against an archive built by the same
 * 7-Zip binary the importer shells out to. A dump source tested only against a
 * plain XML file would never have run the part that can break.
 */
class PageSourceTest extends TestCase
{
    private function dumpXml(): string
    {
        return <<<'XML'
            <mediawiki xmlns="http://www.mediawiki.org/xml/export-0.11/" version="0.11">
              <siteinfo><sitename>Hot Wheels Wiki</sitename></siteinfo>
              <page>
                <title>Danicar</title>
                <ns>0</ns>
                <id>21620</id>
                <revision>
                  <id>762778</id>
                  <text bytes="42" xml:space="preserve">{{casting|name=Danicar}} &amp; more</text>
                </revision>
              </page>
              <page>
                <title>Template:Casting</title>
                <ns>10</ns>
                <id>99</id>
                <revision><id>5</id><text xml:space="preserve">a template</text></revision>
              </page>
              <page>
                <title>'72 Ford Gran Torino Sport</title>
                <ns>0</ns>
                <id>21739</id>
                <revision>
                  <id>790239</id>
                  <text xml:space="preserve">the torino</text>
                </revision>
              </page>
            </mediawiki>
            XML;
    }

    private function writeDump(string $name, string $contents): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('dump-', true).'-'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_the_dump_yields_only_the_pages_asked_for(): void
    {
        $path = $this->writeDump('wiki.xml', $this->dumpXml());

        $pages = iterator_to_array((new DumpPageSource($path))->pages(['Danicar']));

        $this->assertCount(1, $pages);
        $this->assertSame('Danicar', $pages[0]->title);
        $this->assertSame(21620, $pages[0]->pageId);
        $this->assertSame(762778, $pages[0]->revisionId);
        // Entities are decoded by the parser, so the wikitext arrives as it was
        // written rather than as it was escaped for transport.
        $this->assertSame('{{casting|name=Danicar}} & more', $pages[0]->wikitext);

        unlink($path);
    }

    public function test_the_dump_matches_titles_the_way_wiki_links_write_them(): void
    {
        $path = $this->writeDump('wiki.xml', $this->dumpXml());

        // A link says `List_of_2011_Hot_Wheels`; the page is titled with spaces.
        // MediaWiki treats them as the same page and so must this.
        $page = (new DumpPageSource($path))->page('Danicar');
        $underscored = (new DumpPageSource($path))->page("'72_Ford_Gran_Torino_Sport");

        $this->assertNotNull($page);
        $this->assertNotNull($underscored);
        $this->assertSame("'72 Ford Gran Torino Sport", $underscored->title);

        unlink($path);
    }

    public function test_the_dump_skips_everything_that_is_not_an_article(): void
    {
        $path = $this->writeDump('wiki.xml', $this->dumpXml());

        $titles = collect(iterator_to_array((new DumpPageSource($path))->all()))->pluck('title');

        // Namespace 10 is templates. A dump is mostly not articles, and a
        // casting importer that reads templates and talk pages is doing work
        // that can only produce noise.
        $this->assertNotContains('Template:Casting', $titles->all());
        $this->assertCount(2, $titles);

        unlink($path);
    }

    public function test_the_dump_is_read_through_seven_zip_when_it_is_compressed(): void
    {
        $xml = $this->writeDump('wiki.xml', $this->dumpXml());
        $archive = $xml.'.7z';

        exec(sprintf('7z a -bso0 -bsp0 %s %s', escapeshellarg($archive), escapeshellarg($xml)), $output, $status);
        $this->assertSame(0, $status, 'Building the test archive needs 7z on PATH.');

        // The real path: p7zip is in the Dockerfile precisely so the 16 MB
        // archive never has to be decompressed to disk first.
        $page = (new DumpPageSource($archive))->page('Danicar');

        $this->assertNotNull($page);
        $this->assertSame(21620, $page->pageId);

        unlink($xml);
        unlink($archive);
    }

    public function test_a_missing_dump_says_so_rather_than_yielding_nothing(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array((new DumpPageSource('/nonexistent/wiki.xml'))->pages(['Danicar']));
    }

    public function test_the_api_asks_for_content_and_revision_ids_in_one_request(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [[
                'pageid' => 21620,
                'title' => 'Danicar',
                'revisions' => [['revid' => 762778, 'slots' => ['main' => ['content' => 'wikitext here']]]],
            ]]]]),
        ]);

        $page = (new ApiPageSource)->page('Danicar');

        $this->assertSame('Danicar', $page->title);
        $this->assertSame(762778, $page->revisionId);
        $this->assertSame('wikitext here', $page->wikitext);

        Http::assertSent(function ($request): bool {
            // Content and revision id in one round trip: fetching the text and
            // then asking what revision it was would be two requests per page
            // and a race between them.
            return str_contains($request->url(), 'rvprop=content%7Cids')
                && str_contains($request->url(), 'titles=Danicar')
                // Fandom's terms ask for a real user agent, and an anonymous
                // bulk reader is the kind that gets a netblock blocked.
                && str_contains($request->header('User-Agent')[0], 'collectAI');
        });
    }

    public function test_the_api_batches_titles_rather_than_one_request_per_page(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => []]])]);

        $titles = array_map(static fn (int $n): string => "Casting {$n}", range(1, 120));

        iterator_to_array((new ApiPageSource)->pages($titles));

        // 120 castings in three requests, not 120. This is the difference
        // between a batched reader and a crawler.
        Http::assertSentCount(3);
    }

    public function test_the_api_treats_a_title_the_wiki_lacks_as_absent_not_as_an_error(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [
                ['title' => 'No Such Casting', 'missing' => true],
            ]]]),
        ]);

        // A year index can link to an article nobody has written yet. That is a
        // gap in the source, not a failure of the run.
        $this->assertNull((new ApiPageSource)->page('No Such Casting'));
    }
}
