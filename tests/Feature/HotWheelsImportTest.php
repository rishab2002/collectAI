<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Series;
use App\Models\Variation;
use App\Services\Import\HotWheelsWiki\CastingPageParser;
use App\Services\Import\HotWheelsWiki\CatalogImporter;
use App\Services\Import\HotWheelsWiki\ParsedCasting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The importer, driven by the same wikitext the live wiki serves.
 */
class HotWheelsImportTest extends TestCase
{
    use RefreshDatabase;

    private function parsed(): ParsedCasting
    {
        return (new CastingPageParser)->parse(
            file_get_contents(base_path('tests/Fixtures/wiki/nissan-350z.versions.wikitext')),
            pageId: 3161,
            title: 'Nissan 350Z',
            url: 'https://hotwheels.fandom.com/?curid=3161',
            revisionId: 790637,
        );
    }

    public function test_it_creates_the_catalog_chain_for_one_year(): void
    {
        (new CatalogImporter)->import($this->parsed(), 2006);

        $casting = Casting::where('name', 'Nissan 350Z')->firstOrFail();

        $this->assertSame(1, Brand::where('name', 'Hot Wheels')->count());
        $this->assertSame('2006 First Editions', Series::firstOrFail()->name);

        // Version 1 must be created explicitly — nothing in the app does it
        // automatically, and a release has nothing to point at without it.
        $version = $casting->versions()->where('version_number', 1)->firstOrFail();

        $release = Release::firstOrFail();
        $this->assertSame($version->getKey(), $release->casting_version_id);
        $this->assertSame(2006, $release->year);
        $this->assertSame('019', $release->collector_number);

        // Seven table rows under one release: the wiki's rowspan blocks are
        // variations of a single release, not releases of their own.
        $this->assertSame(1, Release::count());
        $this->assertGreaterThanOrEqual(4, Variation::count());
    }

    public function test_toy_number_lands_on_the_variation(): void
    {
        (new CatalogImporter)->import($this->parsed(), 2006);

        $this->assertSame('J3260', Variation::firstOrFail()->toy_number);
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $importer = new CatalogImporter;

        $importer->import($this->parsed(), 2006);

        $before = [
            'castings' => Casting::count(),
            'versions' => CastingVersion::count(),
            'series' => Series::count(),
            'releases' => Release::count(),
            'variations' => Variation::count(),
            'sources' => RecordSource::count(),
        ];

        $summary = $importer->import($this->parsed(), 2006);

        $after = [
            'castings' => Casting::count(),
            'versions' => CastingVersion::count(),
            'series' => Series::count(),
            'releases' => Release::count(),
            'variations' => Variation::count(),
            'sources' => RecordSource::count(),
        ];

        $this->assertSame($before, $after);
        $this->assertSame(0, $summary->count('casting.created'));
        $this->assertSame(0, $summary->count('variation.created'));
    }

    public function test_a_reimport_moves_the_revision_and_timestamp_forward(): void
    {
        $importer = new CatalogImporter;
        $importer->import($this->parsed(), 2006);

        $source = Casting::firstOrFail()->source;
        $this->assertSame(790637, $source->source_revision_id);

        $later = (new CastingPageParser)->parse(
            file_get_contents(base_path('tests/Fixtures/wiki/nissan-350z.versions.wikitext')),
            pageId: 3161,
            title: 'Nissan 350Z',
            url: 'https://hotwheels.fandom.com/?curid=3161',
            revisionId: 999999,
        );

        $importer->import($later, 2006);

        $this->assertSame(1, RecordSource::where('sourceable_type', Casting::class)->count());
        $this->assertSame(999999, $source->fresh()->source_revision_id);
    }

    public function test_every_imported_record_carries_attribution(): void
    {
        (new CatalogImporter)->import($this->parsed(), 2006);

        // The wiki is CC-BY-SA, so a row the public catalog can't attribute is
        // one that shouldn't have been written.
        foreach ([Casting::firstOrFail(), Release::firstOrFail(), Variation::firstOrFail()] as $record) {
            $source = $record->source;

            $this->assertNotNull($source, $record::class.' was imported without provenance');
            $this->assertSame('https://hotwheels.fandom.com/?curid=3161', $source->source_url);
            $this->assertSame(RecordSource::SOURCE_HOT_WHEELS_FANDOM, $source->source);
        }
    }

    public function test_an_imported_casting_stays_pending_and_holds_its_chain_back(): void
    {
        (new CatalogImporter)->import($this->parsed(), 2006);

        $casting = Casting::firstOrFail();

        // The wiki names the real car only in prose, so the importer leaves the
        // model unresolved — which is exactly what approvalBlocker() refuses to
        // publish. This is the settled outcome, not a failure.
        $this->assertTrue($casting->isUnidentified());
        $this->assertFalse($casting->isLive());
        $this->assertNotEmpty($casting->approvalBlockers());

        // And nothing beneath it slips out live under an invisible parent.
        $this->assertFalse(Release::firstOrFail()->isLive());
        $this->assertFalse(Variation::firstOrFail()->isLive());
        $this->assertFalse(CastingVersion::firstOrFail()->isLive());
    }

    public function test_the_brand_and_series_the_wiki_states_do_go_live(): void
    {
        (new CatalogImporter)->import($this->parsed(), 2006);

        // These carry no unresolved inference, so provenance alone is enough.
        $this->assertTrue(Brand::firstOrFail()->isLive());
        $this->assertTrue(Series::firstOrFail()->isLive());
    }

    public function test_a_published_series_carries_an_attribution(): void
    {
        (new CatalogImporter)->import($this->parsed(), 2006);

        // A series goes live, so the public catalog will show it, so it needs a
        // source like anything else the wiki gave us.
        $series = Series::firstOrFail();

        $this->assertNotNull($series->source);
        $this->assertSame('series:2006 First Editions', $series->source->source_key);
    }

    public function test_a_series_shared_by_two_castings_keeps_one_provenance_row(): void
    {
        $importer = new CatalogImporter;
        $importer->import($this->parsed(), 2006);

        // A second casting sold in the same series must resolve to the existing
        // series rather than colliding on its source key.
        $other = (new CastingPageParser)->parse(
            file_get_contents(base_path('tests/Fixtures/wiki/nissan-350z.versions.wikitext')),
            pageId: 9999,
            title: 'Another Casting',
            url: 'https://hotwheels.fandom.com/?curid=9999',
            revisionId: 5,
        );

        $importer->import($other, 2006);

        $this->assertSame(1, Series::count());
        $this->assertSame(1, RecordSource::where('sourceable_type', Series::class)->count());

        // The attribution stays with the page that introduced it rather than
        // flipping to whichever casting was imported most recently.
        $this->assertSame('https://hotwheels.fandom.com/?curid=3161', Series::firstOrFail()->source->source_url);
    }

    public function test_casting_version_one_is_not_attributed_to_the_wiki(): void
    {
        (new CatalogImporter)->import($this->parsed(), 2006);

        // Version 1 is a requirement of our schema, not a claim the wiki makes.
        $this->assertNull(CastingVersion::firstOrFail()->source);
    }

    public function test_raising_the_threshold_imports_everything_pending(): void
    {
        (new CatalogImporter(threshold: 1.01))->import($this->parsed(), 2006);

        $this->assertFalse(Brand::firstOrFail()->isLive());
        $this->assertFalse(Series::firstOrFail()->isLive());
    }

    public function test_the_capture_review_threshold_is_untouched_by_the_import_one(): void
    {
        // The two dials must stay separate: lowering the import threshold to get
        // a bulk run moving must never start auto-publishing photographs.
        config(['catalog.import.auto_approve_threshold' => 0.5]);

        $this->assertSame(1.0, (float) config('catalog.review.auto_approve_threshold'));
    }

    public function test_a_year_the_page_does_not_cover_writes_nothing(): void
    {
        $summary = (new CatalogImporter)->import($this->parsed(), 1999);

        $this->assertSame(0, Casting::count());
        $this->assertSame(0, Release::count());
        $this->assertNotEmpty($summary->skips());
    }

    public function test_an_existing_casting_is_reused_rather_than_duplicated(): void
    {
        // A casting the capture pipeline already created, with no provenance.
        $existing = Casting::factory()->create(['name' => 'Nissan 350Z']);

        (new CatalogImporter)->import($this->parsed(), 2006);

        $this->assertSame(1, Casting::where('name', 'Nissan 350Z')->count());
        $this->assertSame($existing->getKey(), Casting::firstOrFail()->getKey());
        $this->assertNotNull($existing->fresh()->source);
    }

    public function test_the_command_reports_without_writing_on_a_dry_run(): void
    {
        Http::fake([
            '*' => Http::response([
                'query' => [
                    'pages' => [[
                        'pageid' => 21769,
                        'title' => 'List of 2006 Hot Wheels',
                        'revisions' => [[
                            'revid' => 1,
                            'slots' => ['main' => ['content' => file_get_contents(base_path('tests/Fixtures/wiki/list-2011-nightburnerz.wikitext'))]],
                        ]],
                    ]],
                ],
            ]),
        ]);

        $this->artisan('import:hot-wheels', ['year' => 2006, '--dry-run' => true, '--limit' => 1])
            ->assertSuccessful();

        $this->assertSame(0, Casting::count());
        $this->assertSame(0, Release::count());
    }
}
