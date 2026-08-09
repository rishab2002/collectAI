<?php

namespace Tests\Feature;

use App\Services\Import\HotWheelsWiki\CastingPageParser;
use App\Services\Import\HotWheelsWiki\YearIndexParser;
use App\Services\Import\WikitableParser;
use App\Services\Import\Wikitext;
use Tests\TestCase;

/**
 * The parsers, against wikitext taken verbatim from the live wiki.
 *
 * The fixtures hold only the {{casting}} infobox and the tables — no article
 * prose, no Notes column, no images — which is both what the importer reads and
 * what the settled import rules allow it to take.
 */
class HotWheelsWikiParsingTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/wiki/{$name}.wikitext"));
    }

    public function test_a_rowspan_block_resolves_to_full_rows(): void
    {
        $rows = WikitableParser::parseFirst($this->fixture('nissan-350z.versions'));

        // The trap this parser exists for: the wiki writes four wheel variants
        // as one row of thirteen cells followed by three rows of three. Zipping
        // cells against headings positionally would read a wheel type as a
        // collector number.
        $this->assertGreaterThanOrEqual(4, count($rows));

        foreach (array_slice($rows, 0, 4) as $index => $row) {
            $this->assertSame('019', $row['Col #'], "row {$index} lost its spanned collector number");
            $this->assertSame('J3260', $row['Toy #'], "row {$index} lost its spanned toy number");
        }

        // ...while the cell that isn't spanned still differs per row.
        $wheels = array_map(
            fn (array $row): string => Wikitext::plain($row['Wheel Type']),
            array_slice($rows, 0, 4),
        );

        $this->assertSame(['Grey CM6', 'FTE', 'Chrome Lip CM6', 'LW'], $wheels);
    }

    public function test_a_row_separator_before_the_header_does_not_hide_the_table(): void
    {
        // Casting pages disagree about whether "|-" precedes the header row. The
        // 350Z writes "{|" straight into "!Col #"; the '07 Shelby GT-500 doesn't.
        // Treating the first block as the header regardless parsed every page of
        // the second kind to zero rows — and because a page with no rows looks
        // identical to a casting with no releases that year, the importer
        // reported it as a routine skip rather than as a failure.
        $rows = WikitableParser::parseFirst($this->fixture('shelby-gt500.separator-before-header'));

        $this->assertNotEmpty($rows, 'the header row was consumed as data');
        $this->assertArrayHasKey('Col #', $rows[0]);

        $releases = (new CastingPageParser)->releases($this->fixture('shelby-gt500.separator-before-header'));
        $years = array_map(fn ($release): int => $release->year, $releases);

        $this->assertContains(2011, $years, "the 2011 Nightburnerz release wasn't found");
    }

    public function test_a_collector_number_keeps_only_the_car_and_not_the_run(): void
    {
        $releases = (new CastingPageParser)->releases($this->fixture('shelby-gt500.separator-before-header'));

        $release = array_values(array_filter($releases, fn ($r): bool => $r->year === 2011))[0];

        // The cell reads "111/244" — the car's number and the size of the year's
        // whole run. Only the first is a fact about this release.
        $this->assertSame('111', $release->collectorNumber);
        $this->assertSame('Nightburnerz', $release->seriesName);
        $this->assertSame('1/10', $release->seriesPosition);
    }

    public function test_releases_are_read_from_every_versions_table_on_the_page(): void
    {
        // Casting pages often split their versions across two tables, and they
        // aren't ordered by year — the XS-IVE's 2011 release sits in the second.
        // Reading only the first dropped it silently, as a casting that simply
        // had no 2011 release.
        $releases = (new CastingPageParser)->releases($this->fixture('xsive.two-tables'));
        $years = array_map(fn ($release): int => $release->year, $releases);

        $this->assertGreaterThan(1, count($releases));
        $this->assertContains(2011, $years, 'the second table was not read');
    }

    public function test_a_wordier_interior_heading_still_maps(): void
    {
        // The column is "Interior / Engine Color" on some pages and "Interior
        // Color" on others — the same fact either way.
        $releases = (new CastingPageParser)->releases($this->fixture('xsive.two-tables'));

        $twentyEleven = array_values(array_filter($releases, fn ($r): bool => $r->year === 2011))[0];

        $this->assertSame('Blue', $twentyEleven->variations[0]->interiorColor);
    }

    public function test_the_casting_name_comes_from_the_infobox(): void
    {
        $parser = new CastingPageParser;

        $this->assertSame(
            'Nissan 350Z',
            $parser->castingName($this->fixture('nissan-350z.versions'), 'Nissan 350Z (2006)'),
        );

        // Falls back to the title when there's no infobox to read.
        $this->assertSame('Some Casting', $parser->castingName('no infobox here', 'Some Casting'));
    }

    public function test_rows_group_into_releases_by_year_and_series(): void
    {
        $casting = (new CastingPageParser)->parse(
            $this->fixture('nissan-350z.versions'),
            pageId: 3161,
            title: 'Nissan 350Z',
            url: 'https://hotwheels.fandom.com/?curid=3161',
        );

        $this->assertSame('Nissan 350Z', $casting->name);
        $this->assertNotEmpty($casting->releases);

        $first = $casting->releases[0];

        $this->assertSame(2006, $first->year);
        $this->assertSame('2006 First Editions', $first->seriesName);
        $this->assertSame('019', $first->collectorNumber);

        // The series cell stacks the series and the car's place in it with <br>.
        $this->assertSame('19/38', $first->seriesPosition);

        // Seven table rows, one release: four wheel variants of the yellow car
        // and three of the blue one.
        $this->assertGreaterThanOrEqual(4, count($first->variations));
        $this->assertSame('Metalflake Yellow', $first->variations[0]->color);
        $this->assertSame('J3260', $first->variations[0]->toyNumber);
    }

    public function test_the_base_column_keeps_the_colour_and_drops_the_material(): void
    {
        $casting = (new CastingPageParser)->parse(
            $this->fixture('nissan-350z.versions'),
            pageId: 3161,
            title: 'Nissan 350Z',
            url: 'https://hotwheels.fandom.com/?curid=3161',
        );

        // The cell is "Chrome<br>/<br>Plastic" under a "Base Color / Type"
        // heading. Keeping the whole string would make it disagree with the same
        // base read off a page that omits the material.
        $this->assertSame('Chrome', $casting->releases[0]->variations[0]->baseColor);
    }

    public function test_prose_and_images_are_never_carried_through(): void
    {
        $casting = (new CastingPageParser)->parse(
            $this->fixture('nissan-350z.versions'),
            pageId: 3161,
            title: 'Nissan 350Z',
            url: 'https://hotwheels.fandom.com/?curid=3161',
        );

        foreach ($casting->releases as $release) {
            foreach ($release->variations as $variation) {
                foreach ($variation->attributes() as $field => $value) {
                    $this->assertStringNotContainsStringIgnoringCase('[[File:', (string) $value, "{$field} carried image markup");
                    $this->assertStringNotContainsStringIgnoringCase('Named Nissan Z', (string) $value, "{$field} carried the Notes prose");
                }
            }
        }
    }

    public function test_the_year_index_yields_deduplicated_casting_pages(): void
    {
        $titles = (new YearIndexParser)->castingTitles($this->fixture('list-2011-nightburnerz'));

        // The '07 Shelby appears three times in the index — once per colour —
        // and all three rows link to the same article.
        $this->assertContains("'07 Shelby GT-500", $titles);
        $this->assertContains('Nissan 350Z', $titles);
        $this->assertSame(array_values(array_unique($titles)), $titles);

        // The link target is taken, not the label the index renders.
        $this->assertNotContains("'07 Ford Shelby GT-500", $titles);
    }

    public function test_invisible_characters_do_not_survive(): void
    {
        // 138 zero-width spaces sit in the 2011 index alone, mostly trailing a
        // series position. They survive trim(), so "1/50\u{200b}" would never
        // match "1/50" on the next import.
        $this->assertSame('1/50', Wikitext::plain("1/50\u{200B}"));
        $this->assertSame('Chrome', Wikitext::plain("\u{00A0}Chrome \u{200B}"));
    }

    public function test_a_piped_link_resolves_to_its_target_and_its_label(): void
    {
        $cell = "[['07 Shelby GT-500|'07 Ford Shelby GT-500]] (2nd Color)";

        $this->assertSame("'07 Shelby GT-500", Wikitext::linkTarget($cell));
        $this->assertSame("'07 Ford Shelby GT-500 (2nd Color)", Wikitext::plain($cell));
    }

    public function test_a_cell_containing_a_pipe_is_not_mistaken_for_attributes(): void
    {
        // "[[List of 2006 Hot Wheels|2006]]" has a pipe inside the link, and the
        // attribute split must not fire on it.
        $rows = WikitableParser::parseFirst(<<<'WIKI'
            {| class="wikitable"
            ! Year
            ! Series
            |-
            | [[List of 2006 Hot Wheels|2006]]
            | bgcolor="blue"|Nightburnerz
            |}
            WIKI);

        $this->assertSame('[[List of 2006 Hot Wheels|2006]]', $rows[0]['Year']);
        $this->assertSame('Nightburnerz', $rows[0]['Series']);
    }
}
