<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\RecordSource;
use App\Models\Release;
use App\Models\Series;
use App\Models\Submission;
use App\Models\SubmissionProposal;
use App\Models\Variation;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Publishing the imported records that a blocked casting was holding back.
 *
 * Every test here is really about one boundary: this command publishes what was
 * only ever waiting, and decides nothing. The two ways to get that wrong are
 * publishing a record whose casting is still in the review queue — making a
 * ruling that isn't this command's to make — and publishing a capture's pending
 * variation, which was never an imported fact in the first place.
 */
class PublishImportedRecordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_imported_records_once_their_casting_is_live(): void
    {
        $tree = $this->importedTree(castingStatus: Casting::STATUS_LIVE);

        $this->artisan('catalog:publish-imported')->assertSuccessful();

        $this->assertTrue($tree['version']->refresh()->isLive());
        $this->assertTrue($tree['release']->refresh()->isLive());
        $this->assertTrue($tree['variation']->refresh()->isLive());
    }

    public function test_it_leaves_records_whose_casting_is_still_awaiting_review(): void
    {
        $tree = $this->importedTree(castingStatus: Casting::STATUS_PENDING);

        $this->artisan('catalog:publish-imported')->assertSuccessful();

        // Not a blocker — the casting is attributed and perfectly approvable.
        // It is pending because a human hasn't approved the attribution yet, and
        // publishing its releases would make that ruling for them.
        $this->assertFalse($tree['version']->refresh()->isLive());
        $this->assertFalse($tree['release']->refresh()->isLive());
        $this->assertFalse($tree['variation']->refresh()->isLive());
    }

    public function test_it_leaves_records_under_a_casting_that_is_still_unidentified(): void
    {
        $tree = $this->importedTree(castingStatus: Casting::STATUS_PENDING);
        $tree['casting']->forceFill(['model_id' => null])->save();

        $this->artisan('catalog:publish-imported')->assertSuccessful();

        $this->assertFalse($tree['release']->refresh()->isLive());
    }

    public function test_it_does_not_publish_records_a_capture_proposed(): void
    {
        $tree = $this->importedTree(castingStatus: Casting::STATUS_LIVE);

        // Same shape, but proposed rather than imported: no provenance, and a
        // proposal waiting on a human.
        $captured = Variation::factory()->pending()->create(['release_id' => $tree['release']->id]);
        SubmissionProposal::factory()
            ->forProposable($captured)
            ->create(['submission_id' => Submission::factory()]);

        $this->artisan('catalog:publish-imported')->assertSuccessful();

        $this->assertTrue($tree['variation']->refresh()->isLive());
        $this->assertFalse($captured->refresh()->isLive());
    }

    public function test_it_does_not_publish_an_imported_record_that_also_has_an_undecided_proposal(): void
    {
        $tree = $this->importedTree(castingStatus: Casting::STATUS_LIVE);

        SubmissionProposal::factory()
            ->forProposable($tree['variation'])
            ->create(['submission_id' => null]);

        $this->artisan('catalog:publish-imported')->assertSuccessful();

        // Both claims are on the row; the proposal is the stricter one and wins.
        $this->assertFalse($tree['variation']->refresh()->isLive());
    }

    public function test_it_leaves_rejected_records_alone(): void
    {
        $tree = $this->importedTree(castingStatus: Casting::STATUS_LIVE);
        $tree['variation']->markRejected();

        $this->artisan('catalog:publish-imported')->assertSuccessful();

        $this->assertSame(Variation::STATUS_REJECTED, $tree['variation']->refresh()->status);
    }

    public function test_a_dry_run_publishes_nothing_and_leaves_the_transaction_stack_as_it_found_it(): void
    {
        $tree = $this->importedTree(castingStatus: Casting::STATUS_LIVE);

        $before = DB::transactionLevel();

        $this->artisan('catalog:publish-imported', ['--dry-run' => true])->assertSuccessful();

        $this->assertFalse($tree['release']->refresh()->isLive());
        $this->assertFalse($tree['variation']->refresh()->isLive());

        // The level matters as much as the rows. A rehearsal that rolls back
        // from inside DB::transaction() leaves the wrapper to commit afterwards,
        // which at level 1 commits the savepoint RefreshDatabase opened — the
        // rehearsal then looks correct here while quietly leaking every row it
        // wrote into the next test.
        $this->assertSame($before, DB::transactionLevel());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->importedTree(castingStatus: Casting::STATUS_LIVE);

        $this->artisan('catalog:publish-imported')->assertSuccessful();
        $this->artisan('catalog:publish-imported')
            ->expectsOutputToContain('Nothing to publish')
            ->assertSuccessful();
    }

    /**
     * One casting's worth of imported catalogue, as the importer leaves it:
     * brand and series live, everything from the casting down pending, and a
     * provenance row on each record the wiki stated.
     *
     * @return array{casting: Casting, version: CastingVersion, release: Release, variation: Variation}
     */
    private function importedTree(string $castingStatus): array
    {
        $brand = Brand::factory()->create(['name' => 'Hot Wheels']);
        $series = Series::factory()->create(['brand_id' => $brand->id]);

        $casting = Casting::factory()->create([
            'name' => "'63 T-Bird",
            'model_id' => VehicleModel::factory(),
            'is_fantasy' => false,
        ]);
        $casting->forceFill(['status' => $castingStatus])->save();

        $version = CastingVersion::factory()->pending()->create([
            'casting_id' => $casting->id,
            'version_number' => 1,
        ]);

        $release = Release::factory()->pending()->create([
            'casting_version_id' => $version->id,
            'series_id' => $series->id,
        ]);

        $variation = Variation::factory()->pending()->create(['release_id' => $release->id]);

        foreach ([$casting, $version, $release, $variation] as $record) {
            RecordSource::factory()->forSourceable($record)->create();
        }

        return compact('casting', 'version', 'release', 'variation');
    }
}
