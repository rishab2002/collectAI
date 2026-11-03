<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Series;
use App\Models\Variation;
use App\Services\Import\FandomWiki\CastingPage;
use App\Services\Import\FandomWiki\CastingPageParser;
use App\Services\Import\FandomWiki\CatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The importer writing real pages into the catalog.
 *
 * Runs on the same captured wikitext the parser tests use, so what lands in the
 * database is what the wiki actually says rather than what a fixture written
 * alongside the importer would say.
 */
class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $fixture, string $title, int $pageId, ?int $revisionId = 1): CastingPage
    {
        return (new CastingPageParser)->parse(
            file_get_contents(base_path("tests/Fixtures/hotwheels-wiki/{$fixture}.wikitext")),
            $title,
            $pageId,
            $revisionId,
        );
    }

    private function torino(?int $revisionId = 790239): CastingPage
    {
        return $this->page('72-ford-gran-torino-sport', "'72 Ford Gran Torino Sport", 21739, $revisionId);
    }

    public function test_a_page_becomes_a_casting_with_its_releases_and_variations(): void
    {
        $summary = (new CatalogImporter)->import($this->torino());

        $this->assertSame(1, Casting::count());
        $this->assertSame("'72 Ford Gran Torino Sport", Casting::sole()->name);

        // 34 rows collapsing into the releases they describe: several colours of
        // one series-and-year are one release with several variations.
        $this->assertSame(34, Variation::count());
        $this->assertSame(34, $summary->count('variations.created'));
        $this->assertGreaterThan(0, Release::count());
        $this->assertLessThan(Variation::count(), Release::count());
    }

    public function test_rows_sharing_a_series_and_year_become_one_release(): void
    {
        (new CatalogImporter)->import($this->torino());

        $release = Release::query()
            ->where('year', 2011)
            ->sole();

        // The page's first four rows are four colours of the same 2011 New
        // Models car. Four releases would say Hot Wheels sold it four separate
        // times that year.
        $this->assertSame(4, $release->variations()->count());
        $this->assertSame('002', $release->collector_number);
    }

    public function test_a_casting_always_gets_its_first_tooling_version(): void
    {
        (new CatalogImporter)->import($this->torino());

        $version = CastingVersion::sole();

        // Nothing in the app creates version 1 automatically — this is the first
        // bulk writer, so it is the first place that bites.
        $this->assertSame(1, $version->version_number);
        $this->assertSame(Casting::sole()->id, $version->casting_id);
        $this->assertNull($version->retool_year);
        $this->assertSame(Release::count(), $version->releases()->count());
    }

    public function test_a_page_describing_two_tools_gets_a_version_for_each(): void
    {
        (new CatalogImporter)->import($this->page('57-chevy', "'57 Chevy", 21432));

        $versions = CastingVersion::query()->orderBy('version_number')->get();

        $this->assertCount(2, $versions);
        $this->assertSame(['Closed Hood', 'Exposed Engine'], $versions->pluck('notes')->all());
        // The first tool is not a retool; a later one first appears in the
        // earliest year its own rows state.
        $this->assertNull($versions[0]->retool_year);
        $this->assertNotNull($versions[1]->retool_year);
    }

    public function test_the_series_is_shared_rather_than_recreated_per_page(): void
    {
        $importer = new CatalogImporter;
        $importer->import($this->torino());
        $importer->import($this->page('danicar', 'Danicar', 21620));

        // Both pages sell a car under 2011 New Models. Keying the series by the
        // page being imported instead of by the series' own article would give
        // the catalog one copy per casting.
        $this->assertSame(1, Series::query()->where('name', '2011 New Models')->count());
    }

    public function test_the_brand_that_sold_a_release_is_reachable_and_live(): void
    {
        (new CatalogImporter)->import($this->torino());

        $brand = Brand::sole();

        $this->assertSame('Hot Wheels', $brand->name);
        // Brand and series are stated outright by the source, so they publish.
        $this->assertTrue($brand->isLive());
        $this->assertTrue(Series::query()->where('name', '2011 New Models')->sole()->isLive());
    }

    public function test_an_imported_casting_waits_for_a_human_to_identify_it(): void
    {
        (new CatalogImporter)->import($this->torino());

        $casting = Casting::sole();

        // The wiki never states which real vehicle a casting is based on in a
        // structured field. Marking it fantasy would assert there is no real car
        // behind it; leaving it unidentified says the truth, and the approval
        // blocker keeps it out of the public catalog until someone resolves it.
        $this->assertTrue($casting->isUnidentified());
        $this->assertFalse($casting->isFantasy());
        $this->assertFalse($casting->isLive());
        $this->assertNotNull($casting->approvalBlocker());

        // Nothing hanging off it is live either — the catalog never holds a
        // visible record under an invisible parent.
        $this->assertSame(0, Release::query()->live()->count());
        $this->assertSame(0, Variation::query()->live()->count());
    }

    public function test_every_imported_record_can_name_where_it_came_from(): void
    {
        (new CatalogImporter)->import($this->torino());

        $casting = Casting::sole();
        $source = $casting->source;

        $this->assertSame(RecordSource::SOURCE_HOT_WHEELS_FANDOM, $source->source);
        $this->assertSame('page:21739', $source->source_key);
        $this->assertSame(790239, $source->source_revision_id);
        $this->assertSame(
            'https://hotwheels.fandom.com/wiki/%2772_Ford_Gran_Torino_Sport',
            $source->source_url,
        );

        // The licence obligation runs to every imported row, not just the one
        // named after the page.
        $this->assertNotNull(Release::query()->first()->source);
        $this->assertNotNull(Variation::query()->first()->source);
        $this->assertNotNull(Series::query()->first()->source);
    }

    public function test_a_variation_is_keyed_to_the_row_it_came_from(): void
    {
        (new CatalogImporter)->import($this->torino());

        $keys = RecordSource::query()
            ->where('sourceable_type', Variation::class)
            ->pluck('source_key');

        $this->assertContains('page:21739:row:1', $keys->all());
        $this->assertSame($keys->unique()->count(), $keys->count());
    }

    public function test_running_the_same_page_twice_updates_rather_than_duplicates(): void
    {
        $importer = new CatalogImporter;
        $importer->import($this->torino());

        $before = [Casting::count(), Release::count(), Variation::count(), Series::count()];

        $summary = $importer->import($this->torino());

        // The whole basis of a re-runnable importer. Matching on the source key
        // rather than on a name is what makes this hold.
        $this->assertSame($before, [Casting::count(), Release::count(), Variation::count(), Series::count()]);
        $this->assertSame(0, $summary->count('variations.created'));
        $this->assertSame(34, $summary->count('variations.updated'));
    }

    public function test_a_renamed_casting_updates_the_row_it_already_owns(): void
    {
        $importer = new CatalogImporter;
        $importer->import($this->torino());

        $original = Casting::sole()->id;

        $renamed = $this->page('72-ford-gran-torino-sport', "'72 Ford Gran Torino Sport", 21739, 800000);
        $importer->import(new CastingPage(
            title: $renamed->title,
            pageId: $renamed->pageId,
            revisionId: $renamed->revisionId,
            name: "'72 Ford Gran Torino Sport (Retooled)",
            designer: $renamed->designer,
            versions: $renamed->versions,
        ));

        // A name match would miss this and create a second casting beside the
        // first; the source key catches it.
        $this->assertSame(1, Casting::count());
        $this->assertSame($original, Casting::sole()->id);
        $this->assertSame("'72 Ford Gran Torino Sport (Retooled)", Casting::sole()->name);
        $this->assertSame(800000, Casting::sole()->source->source_revision_id);
    }

    public function test_a_reimport_never_republishes_a_record_an_admin_rejected(): void
    {
        $importer = new CatalogImporter;
        $importer->import($this->torino());

        Casting::sole()->markRejected();

        $importer->import($this->torino());

        // `status` is not fillable on any catalog model, which is what makes an
        // update safe to run over a record someone has already ruled on.
        $this->assertSame(Casting::STATUS_REJECTED, Casting::sole()->status);
    }

    public function test_a_page_listing_the_same_car_twice_stores_it_once(): void
    {
        $page = $this->page('danicar', 'Danicar', 21620);

        $summary = (new CatalogImporter)->import($page);

        $this->assertSame(count($page->importableVersions()), Variation::count() + $summary->count('skipped'));

        // If a duplicate reached the insert, Postgres would abort the
        // transaction and take the rest of the page down with it — so the row
        // count standing up at all is the assertion that matters here.
        $this->assertGreaterThan(0, Variation::count());
    }

    public function test_a_page_with_nothing_to_import_is_reported_not_silently_skipped(): void
    {
        $summary = (new CatalogImporter)->import(new CastingPage(
            title: 'Empty Page',
            pageId: 999,
            revisionId: 1,
            name: 'Empty Page',
            designer: null,
            versions: [],
        ));

        // A bulk import that reports only "done" is one nobody can tell went
        // wrong: the failure mode is pages quietly yielding nothing.
        $this->assertSame(0, Casting::count());
        $this->assertSame(1, $summary->count('skipped'));
        $this->assertStringContainsString('Empty Page', $summary->problems()[0]);
    }
}
