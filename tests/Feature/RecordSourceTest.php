<?php

namespace Tests\Feature;

use App\Models\Casting;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Provenance behaviour the importer will lean on: reaching a record's origin
 * from the record, and working out what a re-import has to revisit.
 */
class RecordSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_catalog_record_reaches_its_attribution(): void
    {
        $casting = Casting::factory()->create();

        RecordSource::factory()->forSourceable($casting)->create([
            'source_url' => 'https://hotwheels.fandom.com/wiki/Twin_Mill',
        ]);

        // The public catalog has to show this alongside an imported row, so the
        // path from the row to the credit has to be one relation, not a lookup
        // the browse screen has to remember to build.
        $this->assertSame(
            'https://hotwheels.fandom.com/wiki/Twin_Mill',
            $casting->fresh()->source->source_url,
        );
    }

    public function test_every_importable_table_can_carry_provenance(): void
    {
        // One casting article yields a casting, its releases and their
        // variations, so the relation has to exist on all of them and not just
        // on the table the page is named after.
        $variation = Variation::factory()->create();

        RecordSource::factory()->forSourceable($variation)->create();
        RecordSource::factory()->forSourceable($variation->release)->create();

        $this->assertInstanceOf(RecordSource::class, $variation->fresh()->source);
        $this->assertInstanceOf(RecordSource::class, $variation->release->fresh()->source);
    }

    public function test_a_page_and_its_rows_are_keyed_apart(): void
    {
        $page = RecordSource::pageKey(12345);

        // A casting article is one page but many releases. Keying every row by
        // the page alone would collide on the second one, so the row keys hang
        // off the page key rather than being invented per call site.
        $this->assertSame('page:12345', $page);
        $this->assertSame('page:12345:row:7', RecordSource::rowKey($page, 7));
    }

    public function test_a_reimport_revisits_records_older_than_the_current_revision(): void
    {
        $stale = RecordSource::factory()->fromRevision(100)->create();
        $current = RecordSource::factory()->fromRevision(200)->create();
        $unversioned = RecordSource::factory()->unversioned()->create();

        $revisit = RecordSource::query()
            ->staleAgainst(RecordSource::SOURCE_HOT_WHEELS_FANDOM, 200)
            ->pluck('id');

        // Unversioned rows are revisited too: a source that can't say what
        // revision it served can never be shown to be current, and re-reading is
        // cheaper than assuming it hasn't moved.
        $this->assertEqualsCanonicalizing([$stale->id, $unversioned->id], $revisit->all());
        $this->assertNotContains($current->id, $revisit->all());
    }

    public function test_staleness_is_scoped_to_the_source_it_is_measured_against(): void
    {
        RecordSource::factory()->fromRevision(100)->create(['source' => 'matchbox_fandom']);

        // Revision numbers are per-site. Comparing one wiki's against another's
        // would mark rows stale on the strength of an unrelated counter.
        $this->assertSame(
            0,
            RecordSource::query()
                ->staleAgainst(RecordSource::SOURCE_HOT_WHEELS_FANDOM, 200)
                ->count(),
        );
    }

    public function test_provenance_survives_the_record_being_published(): void
    {
        $release = Release::factory()->pending()->create();
        $source = RecordSource::factory()->forSourceable($release)->create();

        $release->promoteWithDependencies();

        // Approval changes visibility, not origin — the attribution obligation
        // outlives the pending state, and in fact only starts mattering once the
        // row is public.
        $this->assertTrue($release->fresh()->isLive());
        $this->assertSame($source->id, $release->fresh()->source->id);
    }
}
