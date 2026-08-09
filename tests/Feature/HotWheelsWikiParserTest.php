<?php

namespace Tests\Feature;

use App\Services\Import\HotWheelsWiki\CastingPage;
use App\Services\Import\HotWheelsWiki\CastingPageParser;
use App\Services\Import\HotWheelsWiki\VersionRow;
use App\Services\Import\HotWheelsWiki\YearIndexParser;
use Tests\TestCase;

/**
 * The parser, against wikitext captured verbatim from the wiki rather than
 * written to suit it.
 *
 * Hand-written wikitext would only ever exercise the shapes already known about,
 * and the shapes that break a table parser are the ones nobody thought of — the
 * rowspan that states a colour once for two rows, the year cell that is a bare
 * link on one line and a piped one on the next. `List of 2011 Hot Wheels` is the
 * starting point per CLAUDE.md, so its pages are what these run on.
 */
class HotWheelsWikiParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/hotwheels-wiki/{$name}.wikitext"));
    }

    private function torino(): CastingPage
    {
        return (new CastingPageParser)->parse(
            $this->fixture('72-ford-gran-torino-sport'),
            "'72 Ford Gran Torino Sport",
            21739,
            790239,
        );
    }

    private function danicar(): CastingPage
    {
        return (new CastingPageParser)->parse($this->fixture('danicar'), 'Danicar', 21620, 762778);
    }

    public function test_the_casting_takes_its_name_from_the_infobox(): void
    {
        $page = $this->torino();

        $this->assertSame("'72 Ford Gran Torino Sport", $page->name);
        $this->assertSame('Brendon Vetuskey', $page->designer);
    }

    public function test_a_version_row_reads_across_all_of_its_columns(): void
    {
        $first = $this->torino()->importableVersions()[0];

        $this->assertSame(2011, $first->year);
        // "002/244" is a position in the year's run — only the left half names
        // this car, the right half is the size of the run.
        $this->assertSame('002', $first->collectorNumber);
        $this->assertSame('2011 New Models', $first->seriesName);
        $this->assertSame('2/50', $first->seriesPosition);
        $this->assertSame('Metallic Green', $first->color);
        $this->assertSame('Green stripes', $first->tampo);
        $this->assertSame('Clear', $first->windowColor);
        $this->assertSame('Chrome', $first->interiorColor);
        $this->assertSame('5SP', $first->wheelType);
        $this->assertSame('T9672', $first->toyNumber);
        $this->assertSame('Malaysia', $first->country);
    }

    public function test_a_stacked_base_cell_splits_into_a_colour_and_a_material(): void
    {
        $first = $this->torino()->importableVersions()[0];

        // "Green<br>/<br>Plastic". Left whole, this variation's base_color would
        // be "Green / Plastic" and would never match the same car recorded from
        // a photo — or from a page that stacked it differently.
        $this->assertSame('Green', $first->baseColor);
        $this->assertSame('Plastic', $first->baseType);
    }

    public function test_a_series_link_carries_identity_as_well_as_a_name(): void
    {
        $muscleMania = collect($this->torino()->importableVersions())
            ->firstWhere(fn (VersionRow $row): bool => $row->year === 2012);

        // The display text is the name a collector recognises; the link target
        // is what tells this Muscle Mania apart from every other year's.
        $this->assertSame('Muscle Mania: Ford', $muscleMania->seriesName);
        $this->assertSame('Muscle Mania - Ford Series (2012)', $muscleMania->seriesPage);
    }

    public function test_a_rowspan_keeps_the_row_beneath_it_aligned(): void
    {
        // The 2012 Muscle Mania green pair states colour, tampo, base, window,
        // interior, toy number and country once and spans them down; the second
        // row states only the wheel it differs by. Zipping cells against headers
        // would read that row's wheel as its collector number.
        $spanned = collect($this->torino()->importableVersions())
            ->filter(fn (VersionRow $row): bool => $row->toyNumber === 'V5420')
            ->values();

        $this->assertCount(2, $spanned);

        foreach ($spanned as $row) {
            $this->assertSame(2012, $row->year);
            $this->assertSame('117', $row->collectorNumber);
            $this->assertSame('Green', $row->color);
            $this->assertSame('Malaysia', $row->country);
        }

        $this->assertSame('White MC5', $spanned[0]->wheelType);
        $this->assertSame('White rim, Black MC5', $spanned[1]->wheelType);
    }

    public function test_a_row_that_only_states_what_changed_inherits_the_rest(): void
    {
        // Danicar's Circuit Champions row gives a year, a series and a toy
        // number, and inherits colour, tampo, base, window, interior, wheel and
        // country from the row above via mid-row rowspans.
        $circuit = collect($this->danicar()->importableVersions())
            ->firstWhere(fn (VersionRow $row): bool => $row->seriesName === 'Circuit Champions');

        $this->assertNotNull($circuit);
        $this->assertSame('T9944', $circuit->toyNumber);
        $this->assertSame('Red', $circuit->color);
        $this->assertSame('Tinted (Orange)', $circuit->windowColor);
        $this->assertSame('Gold & Black OH5', $circuit->wheelType);
        // This row genuinely has no collector number — the wiki leaves it blank —
        // which is a different claim from inheriting the one above it.
        $this->assertNull($circuit->collectorNumber);
    }

    public function test_the_notes_and_photo_columns_are_not_read_at_all(): void
    {
        $encoded = json_encode($this->danicar()->versions);

        // The licence line: facts are free, the editors' prose is not. Notes is
        // where "Walmart card release includes License Plate sticker" lives, and
        // the DTO has nowhere to put it precisely so that no later change can
        // start importing it by accident.
        $this->assertStringNotContainsString('License Plate sticker', $encoded);
        $this->assertStringNotContainsString('Base Code', $encoded);
        $this->assertStringNotContainsString('.jpg', $encoded);
        $this->assertStringNotContainsString('.JPG', $encoded);
    }

    public function test_a_header_named_differently_still_finds_its_column(): void
    {
        // Danicar heads the column "Notes / Variations" where the Torino heads it
        // "Notes", and the columns either side have to survive that. Matching
        // headers by name rather than position is what makes it survivable.
        $first = $this->danicar()->importableVersions()[0];

        $this->assertSame('Red Rim, Black OH5', $first->wheelType);
        $this->assertSame('Malaysia', $first->country);
    }

    public function test_a_well_formed_page_loses_no_rows(): void
    {
        $page = $this->torino();

        // The filter is for malformed rows, not a licence to drop rows the
        // parser found hard. Every one of this page's 34 versions survives it,
        // including the one whose collector number is blank.
        $this->assertCount(34, $page->versions);
        $this->assertCount(34, $page->importableVersions());
        $this->assertContainsOnlyInstancesOf(VersionRow::class, $page->versions);
    }

    public function test_a_row_with_no_year_is_not_importable(): void
    {
        // Synthetic rather than captured: the spacer and header-repeat rows this
        // guards against are what a *badly* maintained page has, and the point
        // is to be sure of the rule before one turns up in 11,000 pages.
        $page = (new CastingPageParser)->parse(<<<'WIKI'
            {{casting|name=Test Casting}}
            ==Versions==
            {| class="wikitable"
            ! Col # !! Year !! Series !! Color !! Toy #
            |-
            |001/244
            |[[List of 2011 Hot Wheels|2011]]
            |[[2011 New Models]]<br>1/50
            |Red
            |T0001
            |-
            |
            |
            |
            |
            |
            |}
            WIKI, 'Test Casting', 1);

        // `releases` is unique on (casting version, series, year), so a row with
        // no year has nothing to key on — importing it would put a release in
        // the catalog that no page claims exists.
        $this->assertCount(2, $page->versions);
        $this->assertCount(1, $page->importableVersions());
        $this->assertSame('T0001', $page->importableVersions()[0]->toyNumber);
    }

    public function test_the_page_spans_the_years_its_rows_state(): void
    {
        // The infobox says "2011 - Present" and the rows say otherwise. The rows
        // win: they are the fact, the infobox is a summary of it.
        $this->assertSame([2011, 2012, 2013], array_slice($this->torino()->years(), 0, 3));
    }

    public function test_the_source_url_is_a_link_that_resolves(): void
    {
        $this->assertSame(
            'https://hotwheels.fandom.com/wiki/%2772_Ford_Gran_Torino_Sport',
            $this->torino()->sourceUrl(),
        );
    }

    public function test_the_year_index_yields_the_casting_articles_to_visit(): void
    {
        $titles = (new YearIndexParser)->castingTitles($this->fixture('list-of-2011-hot-wheels'));

        $this->assertContains('Danicar', $titles);
        $this->assertContains("'72 Ford Gran Torino Sport", $titles);
        // The piped link's target is the article; the display text is a
        // typographic variant of it and would 404.
        $this->assertContains("'10 Ford Shelby GT500 Super Snake", $titles);
    }

    public function test_the_year_index_lists_each_casting_once(): void
    {
        $titles = (new YearIndexParser)->castingTitles($this->fixture('list-of-2011-hot-wheels'));

        // A casting appears once per colour in the index — the Gran Torino three
        // times. The catalog wants the tool once.
        $this->assertSame(array_values(array_unique($titles)), $titles);
        $this->assertGreaterThan(150, count($titles));
    }

    public function test_the_year_index_does_not_mistake_a_series_for_a_casting(): void
    {
        $titles = (new YearIndexParser)->castingTitles($this->fixture('list-of-2011-hot-wheels'));

        // The Series column is full of links, and following them would import
        // "2011 New Models" as a physical tool.
        $this->assertNotContains('2011 New Models', $titles);
        $this->assertNotContains('List of 2011 Hot Wheels', $titles);
        $this->assertNotContains('Nightburnerz Series (2011)', $titles);
    }

    public function test_a_page_that_splits_its_versions_by_tooling_keeps_both(): void
    {
        $page = (new CastingPageParser)->parse($this->fixture('57-chevy'), "'57 Chevy", 21432, 1);

        // This page has no `==Versions==` heading at all — it files its releases
        // under "Closed Hood Versions" and "Exposed Engine Versions". Reading
        // only a section by that exact name drops the entire page; reading only
        // the first table drops half of it.
        $this->assertSame(['Closed Hood', 'Exposed Engine'], $page->toolingLabels());

        $closed = array_filter(
            $page->importableVersions(),
            fn (VersionRow $row): bool => $row->toolingLabel === 'Closed Hood',
        );

        $this->assertNotEmpty($closed);
        $this->assertNotEmpty(array_filter(
            $page->importableVersions(),
            fn (VersionRow $row): bool => $row->toolingLabel === 'Exposed Engine',
        ));
    }

    public function test_rows_are_numbered_across_the_whole_page(): void
    {
        $page = (new CastingPageParser)->parse($this->fixture('57-chevy'), "'57 Chevy", 21432, 1);

        // The line number is what a row's provenance key is built from. Numbered
        // per section, the first row of the second tooling would collide with
        // the first row of the first.
        $lines = array_map(fn (VersionRow $row): int => $row->line, $page->versions);

        $this->assertSame($lines, array_unique($lines));
        $this->assertSame(range(1, count($lines)), array_values($lines));
    }

    public function test_aftermarket_customs_are_not_imported_as_releases(): void
    {
        $page = (new CastingPageParser)->parse(<<<'WIKI'
            {{casting|name=Test Casting}}
            ==Versions==
            {| class="wikitable"
            ! Col # !! Year !! Series !! Color !! Toy #
            |-
            |001/244 || [[2011]] || [[2011 New Models]] || Red || T0001
            |}
            ==[[Code 3]] Versions==
            {| class="wikitable"
            ! Col # !! Year !! Series !! Color !! Toy #
            |-
            |002/244 || [[2011]] || [[Some Customizer]] || Gold || X0001
            |}
            WIKI, 'Test Casting', 1);

        // A Code 3 car is one a third party repainted after it left the factory.
        // Hot Wheels never sold it, so it is not a release — importing it would
        // put customs in a catalog of retail cars.
        $this->assertCount(1, $page->importableVersions());
        $this->assertSame('T0001', $page->importableVersions()[0]->toyNumber);
    }

    public function test_a_table_that_opens_with_a_row_separator_still_has_column_names(): void
    {
        $page = (new CastingPageParser)->parse($this->fixture('ferrari-ff'), 'Ferrari FF', 21584, 1);

        // This table writes `|-` before its header row. Treating "before the
        // first row separator" as the header row reads all thirteen headers as
        // the first data row, leaves the table with no column names, and every
        // field on every row comes back null — silently, since a page with no
        // importable rows looks the same as a page with nothing to import. Nine
        // percent of a sixty-page sample were being dropped this way.
        $first = $page->importableVersions()[0];

        $this->assertSame(2011, $first->year);
        $this->assertSame('045', $first->collectorNumber);
        $this->assertSame('Red', $first->color);
        $this->assertSame('PR5', $first->wheelType);
        $this->assertSame('T9715', $first->toyNumber);
        // "Base Color<br />/<br />Type" — the self-closing spelling of the same
        // break the other pages write as "<br>".
        $this->assertSame('Grey', $first->baseColor);
    }

    public function test_a_redirect_is_recognised_rather_than_read_as_an_empty_page(): void
    {
        // Three of the first sixty castings the 2011 index links to are
        // redirects. A redirect parses as a page with no versions, so without
        // this the casting is skipped in silence.
        $this->assertSame(
            "'70 Camaro",
            CastingPageParser::redirectTarget($this->fixture('70-camaro-road-race')),
        );

        // The leading colon some redirects carry is escaping syntax, not part of
        // the title, and a lookup for ":'58 Impala" finds nothing.
        $this->assertSame("'58 Impala", CastingPageParser::redirectTarget("#REDIRECT [[:'58 Impala]]"));
        $this->assertNull(CastingPageParser::redirectTarget($this->fixture('danicar')));
    }

    public function test_a_year_index_title_round_trips(): void
    {
        $this->assertSame('List of 2011 Hot Wheels', YearIndexParser::titleForYear(2011));
        $this->assertSame(2011, YearIndexParser::yearFromTitle('List of 2011 Hot Wheels'));
    }
}
