<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\RecordSource;
use App\Models\Series;
use App\Models\Variation;
use App\Services\Import\FandomWiki\CastingPage;
use App\Services\Import\FandomWiki\CastingPageParser;
use App\Services\Import\FandomWiki\CatalogImporter;
use App\Services\Import\FandomWiki\Wiki;
use App\Services\Import\FandomWiki\YearIndexParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The importer beyond 2011 Hot Wheels: the older year-index layouts, and the
 * Matchbox wiki, which shares the page format.
 *
 * Every fixture here is wikitext captured verbatim from the October 2026 (Hot
 * Wheels) and September 2026 (Matchbox) dumps, for the same reason as
 * HotWheelsWikiParserTest: the shapes that break a parser are the ones nobody
 * wrote a sample of.
 */
class FandomWikiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $wiki, string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/{$wiki}/{$name}.wikitext"));
    }

    private function evora(): CastingPage
    {
        return (new CastingPageParser(Wiki::matchbox()))->parse(
            $this->fixture('matchbox-wiki', 'lotus-evora-2008'),
            'Lotus Evora (2008)',
            2602,
            209346,
        );
    }

    // ── Year indexes ────────────────────────────────────────────────────────

    public function test_an_early_year_is_read_from_its_photo_galleries(): void
    {
        $titles = (new YearIndexParser)->castingTitles($this->fixture('hotwheels-wiki', 'list-of-1970-hot-wheels'));

        $this->assertCount(37, $titles);
        $this->assertContains('Mantis', $titles);
        $this->assertContains('Ferrari 312P', $titles);
        // The caption link, not the tile's `link=Ambulance`: the caption names
        // the casting article, the link parameter an unrelated page.
        $this->assertContains('Ambulance (1970)', $titles);
        $this->assertNotContains('Ambulance', $titles);
        // `link=King 'Kuda Club_Kit` and the caption agree once underscores are
        // read as the spaces MediaWiki treats them as.
        $this->assertContains("King 'Kuda Club Kit", $titles);
    }

    public function test_a_table_headed_car_name_is_read(): void
    {
        $titles = (new YearIndexParser)->castingTitles($this->fixture('hotwheels-wiki', 'list-of-1984-hot-wheels'));

        $this->assertCount(26, $titles);
        $this->assertSame(["'65 Mustang Convertible", 'Baja Bug'], array_slice($titles, 0, 2));
        // The wheel column links too ([[WW]], [[BW]]) and must not be followed.
        $this->assertNotContains('WW', $titles);
    }

    public function test_rows_rendered_by_list_templates_are_read(): void
    {
        $titles = (new YearIndexParser)->castingTitles($this->fixture('hotwheels-wiki', 'list-of-1997-hot-wheels'));

        $this->assertCount(114, $titles);
        $this->assertContains('Firebird Funny Car (1997)', $titles);
        $this->assertContains("'56 Flashsider", $titles);
        // The section heading links its series; that is not a casting.
        $this->assertNotContains('1997 First Editions', $titles);
    }

    public function test_the_2011_index_still_reads_the_way_it_did(): void
    {
        $titles = (new YearIndexParser)->castingTitles($this->fixture('hotwheels-wiki', 'list-of-2011-hot-wheels'));

        $this->assertCount(242, $titles);
    }

    public function test_a_matchbox_index_is_read_with_underscored_links_normalised(): void
    {
        $titles = (new YearIndexParser)->castingTitles($this->fixture('matchbox-wiki', 'list-of-2011-matchbox'));

        $this->assertCount(104, $titles);
        $this->assertSame('Fisker Karma (2011)', $titles[0]);
        $this->assertContains('Lotus Evora (2008)', $titles);
        $this->assertSame([], array_filter($titles, static fn (string $title): bool => str_contains($title, '_')));
    }

    // ── Matchbox casting pages ──────────────────────────────────────────────

    public function test_a_matchbox_range_and_segment_are_one_series_and_the_position_is_split_off(): void
    {
        $first = $this->evora()->versions[0];

        $this->assertSame(2008, $first->year);
        $this->assertSame('MB10', $first->collectorNumber);
        $this->assertSame('1-100: Sports Cars', $first->seriesName);
        // Keyed by name: no single article is this series.
        $this->assertNull($first->seriesPage);
        $this->assertSame('2/17', $first->seriesPosition);
        $this->assertSame('Metalflake Silver', $first->color);
        $this->assertSame('Thailand', $first->country);
    }

    public function test_a_matchbox_series_that_is_one_link_still_keys_by_its_article(): void
    {
        $bestOfBritish = $this->evora()->versions[2];

        $this->assertSame('Best of British', $bestOfBritish->seriesName);
        $this->assertSame('Best of British', $bestOfBritish->seriesPage);
    }

    public function test_a_five_pack_keeps_its_prefix_and_an_na_collector_number_is_absent(): void
    {
        $pack = $this->evora()->versions[6];

        $this->assertSame('5-Pack: Modern Rides', $pack->seriesName);
        // Was "N" — the left half of "N/A" split on its slash.
        $this->assertNull($pack->collectorNumber);
    }

    public function test_a_wheel_written_as_a_picture_is_recorded_by_its_file_name(): void
    {
        $versions = $this->evora()->versions;

        $this->assertSame('M002f', $versions[0]->wheelType);
        $this->assertSame('5SpokeSuperfastSport', $versions[3]->wheelType);
    }

    public function test_regional_toy_numbers_are_kept_together(): void
    {
        $this->assertSame('M0072 (USA) M7348 (INT)', $this->evora()->versions[0]->toyNumber);
    }

    public function test_the_hot_wheels_wiki_keeps_keying_a_series_by_its_first_link(): void
    {
        // compoundSeries is off for Hot Wheels: 1,002 of its rows carry text
        // beside the link, and re-keying them would duplicate every series an
        // earlier import created.
        $page = (new CastingPageParser(Wiki::hotWheels()))->parse(
            "{{casting|name=Test}}\n==Versions==\n{|\n!Col #\n!Year\n!Series\n!Color\n|-\n|1\n|2023\n|[[2023 Hot Wheels Legends Tour]] Trophy Car\n|Red\n|}",
            'Test',
            1,
        );

        $this->assertSame('2023 Hot Wheels Legends Tour', $page->versions[0]->seriesName);
        $this->assertSame('2023 Hot Wheels Legends Tour', $page->versions[0]->seriesPage);
    }

    // ── Importing Matchbox ──────────────────────────────────────────────────

    public function test_a_matchbox_casting_lands_under_matchbox_with_its_own_provenance(): void
    {
        $summary = (new CatalogImporter(Wiki::matchbox()))->import($this->evora());

        $this->assertSame([], array_values(array_filter(
            $summary->problems(),
            static fn (string $problem): bool => ! str_contains($problem, 'identical'),
        )));

        $brand = Brand::sole();
        $this->assertSame('Matchbox', $brand->name);
        $this->assertSame('Mattel', $brand->parent_company);
        $this->assertTrue($brand->isLive());

        $this->assertTrue(Series::where('name', '1-100: Sports Cars')->exists());
        $this->assertSame(15, Variation::count());

        $source = RecordSource::where('sourceable_type', (new Casting)->getMorphClass())->sole();
        $this->assertSame(RecordSource::SOURCE_MATCHBOX_FANDOM, $source->source);
        $this->assertSame('https://matchbox.fandom.com/wiki/Lotus_Evora_%282008%29', $source->source_url);

        // Unidentified, exactly as a Hot Wheels import leaves it: the wiki
        // states no make or model, and the blocker holds everything back.
        $this->assertFalse(Casting::sole()->isLive());
    }

    public function test_the_same_page_id_on_two_wikis_is_two_castings(): void
    {
        // Both wikis number pages from 1; the source column keeps them apart.
        (new CatalogImporter(Wiki::matchbox()))->import($this->evora());

        $hotWheels = (new CastingPageParser(Wiki::hotWheels()))->parse(
            $this->fixture('hotwheels-wiki', 'danicar'),
            'Danicar',
            2602,
        );
        (new CatalogImporter(Wiki::hotWheels()))->import($hotWheels);

        $this->assertSame(['Danicar', 'Lotus Evora (2008)'], Casting::orderBy('name')->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Hot Wheels', 'Matchbox'], Brand::pluck('name')->all());
    }

    public function test_a_page_from_one_wiki_is_refused_by_the_others_importer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CatalogImporter(Wiki::hotWheels()))->import($this->evora());
    }

    // ── The command ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, array{0: string, 1: int}>  $pages
     */
    private function fakeWiki(string $host, array $pages): void
    {
        Http::fake(function ($request) use ($host, $pages) {
            $this->assertSame($host, parse_url($request->url(), PHP_URL_HOST));

            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            $found = [];

            foreach (explode('|', $query['titles'] ?? '') as $title) {
                $found[] = isset($pages[$title])
                    ? ['title' => $title, 'pageid' => $pages[$title][1], 'revisions' => [['revid' => 1, 'slots' => ['main' => ['content' => $pages[$title][0]]]]]]
                    : ['title' => $title, 'missing' => true];
            }

            return Http::response(['query' => ['pages' => $found]]);
        });
    }

    public function test_the_matchbox_wiki_is_imported_through_the_general_command(): void
    {
        $this->fakeWiki('matchbox.fandom.com', [
            'List of 2011 Matchbox' => ["{|\n!Model Name\n|-\n|[[Lotus_Evora_(2008)|Lotus Evora]]\n|}", 5712],
            'Lotus Evora (2008)' => [$this->fixture('matchbox-wiki', 'lotus-evora-2008'), 2602],
        ]);

        $this->artisan('catalog:import-wiki', ['wiki' => 'matchbox', '--year' => 2011])
            ->expectsOutputToContain('1 casting articles to import.')
            ->assertSuccessful();

        $this->assertSame('Lotus Evora (2008)', Casting::sole()->name);
        $this->assertSame('Matchbox', Brand::sole()->name);
    }

    public function test_a_range_reads_each_casting_once_however_many_years_name_it(): void
    {
        $index = "{|\n!Model Name\n|-\n|[[Danicar]]\n|}";

        $this->fakeWiki('hotwheels.fandom.com', [
            'List of 2010 Hot Wheels' => [$index, 1],
            'List of 2011 Hot Wheels' => [$index, 2],
            'Danicar' => [$this->fixture('hotwheels-wiki', 'danicar'), 21620],
        ]);

        $this->artisan('catalog:import-hot-wheels', ['--from' => 2009, '--to' => 2011])
            ->expectsOutputToContain('Reading 3 year indexes, 2009–2011')
            ->expectsOutputToContain('No index for 1 year(s): 2009')
            ->expectsOutputToContain('1 casting articles to import.')
            ->assertSuccessful();

        $this->assertSame(1, Casting::count());
    }

    public function test_an_index_naming_nothing_imports_nothing(): void
    {
        $this->fakeWiki('hotwheels.fandom.com', [
            'List of 2011 Hot Wheels' => ['Nothing a parser recognises.', 1],
        ]);

        $this->artisan('catalog:import-hot-wheels', ['--year' => 2011])
            ->expectsOutputToContain('names no casting articles')
            ->assertSuccessful();

        $this->assertSame(0, Casting::count());
    }

    public function test_an_unknown_wiki_or_a_muddled_range_fails(): void
    {
        $this->artisan('catalog:import-wiki', ['wiki' => 'tomica'])
            ->expectsOutputToContain('Unknown wiki')
            ->assertFailed();

        $this->artisan('catalog:import-wiki', ['--year' => 2011, '--from' => 2010])
            ->expectsOutputToContain('not both')
            ->assertFailed();

        $this->artisan('catalog:import-wiki', ['--from' => 2012, '--to' => 2010])
            ->expectsOutputToContain('not a usable range')
            ->assertFailed();
    }

    public function test_each_wiki_names_its_own_dump(): void
    {
        $this->assertSame(
            'https://s3.amazonaws.com/wikia_xml_dumps/h/ho/hotwheels_pages_current.xml.7z',
            Wiki::hotWheels()->dumpUrl(),
        );
        $this->assertSame(
            'https://s3.amazonaws.com/wikia_xml_dumps/m/ma/matchbox_pages_current.xml.7z',
            Wiki::matchbox()->dumpUrl(),
        );
    }
}
