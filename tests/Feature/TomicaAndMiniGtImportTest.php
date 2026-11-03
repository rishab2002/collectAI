<?php

namespace Tests\Feature;

use App\Console\Commands\ImportWikiCatalog;
use App\Models\Brand;
use App\Models\Casting;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Series;
use App\Models\Variation;
use App\Services\Import\FandomWiki\ApiPageSource;
use App\Services\Import\FandomWiki\CategorySource;
use App\Services\Import\FandomWiki\MiniGtLists;
use App\Services\Import\FandomWiki\PageSource;
use App\Services\Import\FandomWiki\TomicaArticles;
use App\Services\Import\FandomWiki\Wiki;
use App\Services\Import\FandomWiki\WikiPage;
use App\Services\Import\ImportSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tomica (by toy-line category) and Mini GT (by yearly item list), on articles
 * captured from the live wikis on 2026-11-03.
 */
class TomicaAndMiniGtImportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ImportWikiCatalog::$source = null;

        parent::tearDown();
    }

    /**
     * @return list<WikiPage>
     */
    private function tomicaPages(): array
    {
        $pages = [];

        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/tomica-wiki/sample-articles.json')), true) as $title => $page) {
            $pages[] = new WikiPage($title, $page['pageid'], $page['revid'], $page['text'], $page['categories']);
        }

        return $pages;
    }

    private function miniGtList(): WikiPage
    {
        return new WikiPage('2021 Models', 307, 7242, file_get_contents(base_path('tests/Fixtures/minigt-wiki/2021-models.wikitext')));
    }

    /**
     * A source serving the fixtures to the command, without the network.
     */
    private function fakeSource(): object
    {
        $tomica = $this->tomicaPages();
        $list = $this->miniGtList();

        return new class($tomica, $list) implements CategorySource, PageSource
        {
            public function __construct(private array $tomica, private WikiPage $list) {}

            public function categoryMembers(string $category): iterable
            {
                return array_filter($this->tomica, fn (WikiPage $page): bool => in_array($category, $page->categories, true));
            }

            public function page(string $title): ?WikiPage
            {
                return $title === $this->list->title ? $this->list : null;
            }

            public function pages(array $titles): iterable
            {
                return in_array($this->list->title, $titles, true) ? [$this->list] : [];
            }
        };
    }

    public function test_tlv_variants_are_one_casting_keyed_by_their_base_code(): void
    {
        $summary = new ImportSummary;
        $castings = collect((new TomicaArticles(Wiki::tomica()))->castings($this->tomicaPages(), $summary));

        $cedric = $castings->first(fn ($c) => $c->name === 'Nissan Cedric');
        $this->assertSame('casting:Tomica_Limited_Vintage|LV-01', $cedric->sourceKey());
        $this->assertSame(['LV-01a', 'LV-01b', 'LV-01c'], array_map(fn ($row) => $row->toyNumber, $cedric->versions));
        $this->assertSame(2004, $cedric->versions[0]->year);
        $this->assertSame('Tomica Limited Vintage', $cedric->versions[0]->seriesName);

        // A Classic Tomica number is reused for different cars, so the name is
        // the casting and the number is the release's collector number.
        $selega = $castings->first(fn ($c) => $c->name === 'Hino Selega');
        $this->assertSame('No. 101', $selega->versions[0]->collectorNumber);
        $this->assertSame('Classic Tomica', $selega->versions[0]->seriesName);

        $this->assertSame('Tomica Premium', $castings->first(fn ($c) => $c->name === 'Nismo R34 GT-R Z-tune')->versions[0]->seriesName);

        // A title with no code keeps its whole name.
        $this->assertTrue($castings->contains(fn ($c) => str_starts_with($c->name, 'LV-N Abunai Deka Vol.01')));

        // A year-summary page is not a car.
        $this->assertFalse($castings->contains(fn ($c) => $c->name === 'Tomica 1995'));
        $this->assertContains('Tomica 1995: no year category', $summary->problems());
    }

    public function test_mini_gt_items_are_keyed_by_item_number_with_kaido_house_apart(): void
    {
        $castings = collect((new MiniGtLists(Wiki::miniGt()))->castings($this->miniGtList(), new ImportSummary));

        $this->assertGreaterThan(100, $castings->count());

        $first = $castings->first(fn ($c) => $c->sourceKey() === 'item:KHMG001');
        $this->assertSame('Datsun 510 Pro Street OG Green', $first->name);
        $this->assertSame('Kaido House', $first->versions[0]->seriesName);
        $this->assertSame(2021, $first->versions[0]->year);

        $bmw = $castings->first(fn ($c) => $c->sourceKey() === 'item:MGT00119');
        $this->assertSame('Mini GT', $bmw->versions[0]->seriesName);
        $this->assertSame('MGT00119', $bmw->versions[0]->toyNumber);
        $this->assertSame('https://minigt.fandom.com/wiki/BMW_M3_AC_Schnitzer_S3_Sport_Black', $bmw->sourceUrl());

        $this->assertSame(['2016 Models', '2017 Models'], array_slice(MiniGtLists::listTitles(), 0, 2));
    }

    public function test_the_command_imports_both_wikis_under_their_own_brands_and_sources(): void
    {
        ImportWikiCatalog::$source = $this->fakeSource();

        $this->artisan('catalog:import-wiki-catalog', ['wiki' => 'tomica'])->assertSuccessful();
        $this->artisan('catalog:import-wiki-catalog', ['wiki' => 'mini-gt'])->assertSuccessful();

        $tomica = Brand::where('name', 'Tomica')->sole();
        $this->assertSame('Takara Tomy', $tomica->parent_company);
        $this->assertSame('Mini GT', Brand::where('name', 'Mini GT')->sole()->name);

        $cedric = Casting::where('name', 'Nissan Cedric')->sole();
        $this->assertSame(3, Variation::query()
            ->whereHas('release.castingVersion', fn ($q) => $q->where('casting_id', $cedric->id))->count());
        $this->assertFalse($cedric->isLive(), 'Imported castings wait to be identified, like every other import.');

        $this->assertTrue(RecordSource::where('source', RecordSource::SOURCE_TOMICA_FANDOM)->exists());
        $this->assertTrue(RecordSource::where('source', RecordSource::SOURCE_MINIGT_FANDOM)->where('source_key', 'item:MGT00119')->exists());
        $this->assertEqualsCanonicalizing(
            ['Classic Tomica', 'Tomica Limited Vintage', 'Tomica Limited Vintage Neo', 'Tomica Premium', 'Kaido House', 'Mini GT'],
            Series::pluck('name')->unique()->values()->all(),
        );
    }

    public function test_a_second_run_creates_nothing(): void
    {
        ImportWikiCatalog::$source = $this->fakeSource();
        $this->artisan('catalog:import-wiki-catalog', ['wiki' => 'mini-gt'])->assertSuccessful();
        $counts = [Casting::count(), Release::count(), Variation::count()];

        $this->artisan('catalog:import-wiki-catalog', ['wiki' => 'mini-gt'])->assertSuccessful();

        $this->assertSame($counts, [Casting::count(), Release::count(), Variation::count()]);
    }

    public function test_the_command_refuses_the_dump_wikis_and_unknown_ones(): void
    {
        $this->artisan('catalog:import-wiki-catalog', ['wiki' => 'hot-wheels'])
            ->expectsOutputToContain('catalog:import-wiki')
            ->assertFailed();

        $this->artisan('catalog:import-wiki-catalog', ['wiki' => 'schuco'])->assertFailed();
    }

    public function test_the_api_reads_a_category_with_each_pages_categories(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['list'] ?? null) === 'categorymembers') {
                return Http::response(['query' => ['categorymembers' => [['title' => 'LV-01a Nissan Cedric']]]]);
            }

            // Categories split across a continuation, as the API does.
            if (! isset($query['clcontinue'])) {
                return Http::response([
                    'continue' => ['clcontinue' => '1|x', 'continue' => '||'],
                    'query' => ['pages' => [[
                        'pageid' => 1, 'title' => 'LV-01a Nissan Cedric',
                        'revisions' => [['revid' => 9, 'slots' => ['main' => ['content' => 'text']]]],
                        'categories' => [['title' => 'Category:Tomica Limited Vintage']],
                    ]]],
                ]);
            }

            return Http::response(['query' => ['pages' => [[
                'pageid' => 1, 'title' => 'LV-01a Nissan Cedric',
                'categories' => [['title' => 'Category:2004 Tomica']],
            ]]]]);
        });

        $pages = iterator_to_array((new ApiPageSource('https://tomica.fandom.com/api.php'))->categoryMembers('Tomica Limited Vintage'), false);

        $this->assertCount(1, $pages);
        $this->assertSame('text', $pages[0]->wikitext);
        $this->assertSame(['Tomica Limited Vintage', '2004 Tomica'], $pages[0]->categories);
    }
}
