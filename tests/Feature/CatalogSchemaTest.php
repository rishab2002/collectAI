<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Make;
use App\Models\Release;
use App\Models\Series;
use App\Models\Submission;
use App\Models\SubmissionProposal;
use App\Models\UserCollectionItem;
use App\Models\Variation;
use App\Models\VehicleModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the invariants that live in the database rather than in PHP, so a
 * later migration can't quietly drop one.
 */
class CatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_variations_identical_on_every_nullable_attribute_collide(): void
    {
        $release = Release::factory()->create();

        Variation::factory()->bare()->create(['release_id' => $release]);

        // Under a plain UNIQUE this would insert: Postgres treats NULL != NULL,
        // so two all-null rows aren't "equal". The COALESCE(col, '') expression
        // index is what actually closes it.
        $this->expectException(QueryException::class);

        Variation::factory()->bare()->create(['release_id' => $release]);
    }

    public function test_genuinely_different_variations_on_one_release_are_accepted(): void
    {
        $release = Release::factory()->create();

        Variation::factory()->bare()->create(['release_id' => $release, 'color' => 'Red']);
        Variation::factory()->bare()->create(['release_id' => $release, 'color' => 'Blue']);

        $this->assertSame(2, $release->variations()->count());
    }

    public function test_status_only_accepts_known_values(): void
    {
        $this->expectException(QueryException::class);

        Make::factory()->create(['status' => 'sort-of-live']);
    }

    public function test_a_release_year_outside_the_plausible_range_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        Release::factory()->create(['year' => 1800]);
    }

    public function test_a_decided_proposal_must_carry_a_decision_timestamp(): void
    {
        $this->expectException(QueryException::class);

        SubmissionProposal::factory()->create([
            'decision' => SubmissionProposal::DECISION_APPROVED,
            'decided_at' => null,
        ]);
    }

    public function test_a_catalog_record_can_only_be_proposed_once(): void
    {
        $casting = Casting::factory()->pending()->create();

        SubmissionProposal::factory()->forProposable($casting)->create();

        // The Comparison Agent is supposed to match a re-photographed car to the
        // existing record rather than propose it again; this makes that a
        // guarantee instead of a convention.
        $this->expectException(QueryException::class);

        SubmissionProposal::factory()->forProposable($casting)->create();
    }

    public function test_names_populated_by_the_pipeline_allow_near_duplicates(): void
    {
        $model = VehicleModel::factory()->create(['name' => 'RX-7', 'chassis_code' => 'FD3S']);

        // Deliberately permitted: a second RX-7 is a different generation, and
        // sorting that out is the Comparison Agent's job, not the DB's.
        $second = VehicleModel::factory()->create([
            'make_id' => $model->make_id,
            'name' => 'RX-7',
            'chassis_code' => 'FC3S',
        ]);

        $this->assertNotSame($model->id, $second->id);
    }

    public function test_admin_controlled_vocabularies_reject_exact_duplicates(): void
    {
        Make::factory()->create(['name' => 'Mazda']);

        $this->expectException(QueryException::class);

        Make::factory()->create(['name' => 'Mazda']);
    }

    public function test_a_fantasy_casting_cannot_also_claim_a_real_model(): void
    {
        $this->expectException(QueryException::class);

        Casting::factory()->create([
            'is_fantasy' => true,
            'model_id' => VehicleModel::factory(),
        ]);
    }

    public function test_fantasy_and_unidentified_castings_are_distinguishable(): void
    {
        $fantasy = Casting::factory()->fantasy()->create();
        $unidentified = Casting::factory()->unidentified()->create();

        // Both have a null model_id, which is exactly why the flag has to exist:
        // the pipeline produces the second kind constantly and it must not be
        // mistaken for a car that was never real.
        $this->assertNull($fantasy->model_id);
        $this->assertNull($unidentified->model_id);

        $this->assertTrue($fantasy->isFantasy());
        $this->assertFalse($fantasy->isUnidentified());

        $this->assertFalse($unidentified->isFantasy());
        $this->assertTrue($unidentified->isUnidentified());
    }

    public function test_one_tooling_can_be_released_under_more_than_one_brand(): void
    {
        // Shared tooling across sister brands (Johnny Lightning / Auto World,
        // Mini GT / Kaido House) is one physical tool sold under two names. It's
        // a single Casting row, with a release under each brand's series — which
        // is only expressible because a casting carries no brand of its own.
        // Do not "fix" this into a constraint requiring the two to agree.
        $version = CastingVersion::factory()->create();

        $first = Release::factory()->create([
            'casting_version_id' => $version,
            'series_id' => Series::factory()->create(['brand_id' => Brand::factory()]),
            'year' => 2011,
        ]);

        $second = Release::factory()->create([
            'casting_version_id' => $version,
            'series_id' => Series::factory()->create(['brand_id' => Brand::factory()]),
            'year' => 2011,
        ]);

        $this->assertNotSame(
            $first->series->brand_id,
            $second->series->brand_id,
            'The two releases should sit under different brands.',
        );

        $this->assertSame($first->castingVersion->casting_id, $second->castingVersion->casting_id);
    }

    public function test_the_brand_that_sold_a_release_is_reached_through_its_series(): void
    {
        $brand = Brand::factory()->create(['name' => 'Auto World']);
        $release = Release::factory()->create([
            'series_id' => Series::factory()->create(['brand_id' => $brand]),
        ]);

        $this->assertSame('Auto World', $release->series->brand->name);

        // There is no second, competing answer on the casting itself.
        $this->assertFalse(
            Schema::hasColumn('castings', 'brand_id'),
            'castings.brand_id was removed deliberately — see CLAUDE.md, Settled decisions.',
        );
    }

    public function test_collection_item_condition_is_a_closed_vocabulary(): void
    {
        $item = UserCollectionItem::factory()->create();

        // Inserted raw on purpose: the ItemCondition cast already rejects junk in
        // PHP, so going through the model would prove nothing about the column.
        // The CHECK is what covers writes that skip Eloquent — imports, seeders
        // and psql.
        $this->expectException(QueryException::class);

        DB::table('user_collection_items')
            ->where('id', $item->id)
            ->update(['condition' => 'nearly mint-ish']);
    }

    public function test_a_condition_outside_the_vocabulary_is_refused_by_the_cast(): void
    {
        $this->expectException(\ValueError::class);

        UserCollectionItem::factory()->create(['condition' => 'nearly mint-ish']);
    }

    public function test_submission_ocr_output_round_trips_as_structured_json(): void
    {
        $submission = Submission::factory()->completed()->create();

        $this->assertSame('Night Burnerz', $submission->fresh()->ocr_output['series']);
    }
}
