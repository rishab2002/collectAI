<?php

namespace Tests\Feature;

use App\Models\Casting;
use App\Models\RecordSource;
use App\Models\Release;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the provenance invariants the catalog importer is built on. These live
 * in the database rather than in the importer on purpose: "add only genuinely
 * missing records" has to hold for every future importer, not just the first
 * one written.
 */
class RecordSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_source_key_can_only_describe_one_record(): void
    {
        RecordSource::factory()->fromSource(RecordSource::SOURCE_HOT_WHEELS_FANDOM, 'page:12345')->create();

        // This is the constraint that makes re-import idempotent: a second run
        // has to resolve the existing row by (source, source_key) and update it,
        // rather than inserting a duplicate casting.
        $this->expectException(QueryException::class);

        RecordSource::factory()->fromSource(RecordSource::SOURCE_HOT_WHEELS_FANDOM, 'page:12345')->create();
    }

    public function test_the_same_key_in_a_different_source_is_accepted(): void
    {
        RecordSource::factory()->fromSource(RecordSource::SOURCE_HOT_WHEELS_FANDOM, 'page:12345')->create();
        RecordSource::factory()->fromSource('matchbox_wiki', 'page:12345')->create();

        // Page IDs are only unique within a wiki. Scoping the constraint to the
        // pair is what lets a second source be onboarded without renumbering.
        $this->assertSame(2, RecordSource::count());
    }

    public function test_a_record_can_only_have_one_provenance_row(): void
    {
        $casting = Casting::factory()->create();

        RecordSource::factory()->forSourceable($casting)->create();

        $this->expectException(QueryException::class);

        RecordSource::factory()->forSourceable($casting)->fromSource('matchbox_wiki', 'page:999')->create();
    }

    public function test_a_casting_and_its_release_rows_get_distinct_keys(): void
    {
        // The trap the key convention exists to close: one article yields a
        // casting and many releases, and keying them all to the page would make
        // the releases collide with the casting and with each other.
        $casting = RecordSource::pageKey(12345);
        $release = RecordSource::releaseKey(12345, 2011, 'Nightburnerz');
        $otherYear = RecordSource::releaseKey(12345, 2012, 'Nightburnerz');
        $variation = RecordSource::variationKey(12345, 2011, 'Nightburnerz', 0);

        $this->assertSame('page:12345', $casting);
        $this->assertSame('page:12345:release:2011:Nightburnerz', $release);
        $this->assertSame('page:12345:release:2011:Nightburnerz:variation:0', $variation);
        $this->assertCount(4, array_unique([$casting, $release, $otherYear, $variation]));
    }

    public function test_release_keys_are_built_from_identity_not_row_position(): void
    {
        // A row inserted higher up a versions table shifts every row number
        // below it, so positional keys would make the next import resolve each
        // release to its neighbour. Year and series are what the release is.
        $this->assertNotSame(
            RecordSource::releaseKey(12345, 2011, 'Nightburnerz'),
            RecordSource::releaseKey(12345, 2011, 'Muscle Mania'),
        );
    }

    public function test_series_differing_only_in_punctuation_get_distinct_keys(): void
    {
        // The bug this closes only appeared against the live wiki: the key
        // builder used to slug, so "Monster Jam: Monster Duo" and "Monster Jam -
        // Monster Duo" — two series the wiki lists separately — collapsed onto
        // one key and the second import to reach them died on the unique
        // constraint mid-run.
        $keys = [
            RecordSource::seriesKey('Monster Jam: Monster Duo'),
            RecordSource::seriesKey('Monster Jam - Monster Duo'),
            RecordSource::seriesKey('Monster Jam Monster Duo'),
        ];

        $this->assertCount(3, array_unique($keys));

        // Only whitespace is normalised, so the same name written with a stray
        // double space still resolves to the one record.
        $this->assertSame(
            RecordSource::seriesKey('Monster Jam: Monster Duo'),
            RecordSource::seriesKey("Monster Jam:  Monster\tDuo "),
        );
    }

    public function test_a_blank_source_key_is_rejected(): void
    {
        // Postgres is happy to treat '' as a unique value, so the idempotency
        // constraint above would pass while identifying nothing.
        $this->expectException(QueryException::class);

        RecordSource::factory()->fromSource(RecordSource::SOURCE_HOT_WHEELS_FANDOM, '   ')->create();
    }

    public function test_a_blank_source_url_is_rejected(): void
    {
        // The wiki is CC-BY-SA. A row the public catalog cannot attribute is one
        // the importer must not be able to create.
        $this->expectException(QueryException::class);

        RecordSource::factory()->create(['source_url' => '']);
    }

    public function test_provenance_resolves_from_the_catalog_record(): void
    {
        $release = Release::factory()->create();

        RecordSource::factory()
            ->forSourceable($release)
            ->fromSource(RecordSource::SOURCE_HOT_WHEELS_FANDOM, RecordSource::releaseKey(12345, 2011, 'Nightburnerz'))
            ->create();

        $release->refresh();

        $this->assertTrue($release->isImported());
        $this->assertSame('page:12345:release:2011:Nightburnerz', $release->source->source_key);
        $this->assertTrue($release->source->sourceable->is($release));
    }

    public function test_a_record_nothing_imported_has_no_source(): void
    {
        $casting = Casting::factory()->create();

        $this->assertFalse($casting->isImported());
        $this->assertNull($casting->source);
    }

    public function test_a_refresh_run_can_find_what_it_has_not_read_lately(): void
    {
        $cutoff = now()->subDay();

        $stale = RecordSource::factory()->importedAt(now()->subWeek())->create();
        RecordSource::factory()->importedAt(now())->create();

        $found = RecordSource::query()
            ->fromSource(RecordSource::SOURCE_HOT_WHEELS_FANDOM)
            ->staleSince($cutoff)
            ->get();

        $this->assertTrue($found->pluck('id')->contains($stale->id));
        $this->assertCount(1, $found);
    }

    public function test_an_unversioned_source_is_allowed_to_have_no_revision(): void
    {
        // Not every source is versioned — a MediaWiki dump is, a CSV export isn't.
        $source = RecordSource::factory()->atRevision(null)->create();

        $this->assertNull($source->fresh()->source_revision_id);
    }
}
