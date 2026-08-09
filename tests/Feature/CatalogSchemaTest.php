<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Make;
use App\Models\RecordSource;
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

    public function test_two_cars_with_different_toy_numbers_are_different_variations(): void
    {
        $release = Release::factory()->create();
        $shared = ['release_id' => $release, 'color' => 'Metalflake Silver'];

        Variation::factory()->bare()->create([...$shared, 'toy_number' => 'X7060']);
        $second = Variation::factory()->bare()->create([...$shared, 'toy_number' => 'X9764']);

        // Real pages do this: the '68 COPO Camaro lists two 2012 cars alike on
        // every descriptive column and told apart only by the number on the
        // base. Without toy_number in the uniqueness index the second insert
        // fails and the car is lost.
        $this->assertSame(2, $release->variations()->count());
        $this->assertTrue($second->exists);
    }

    public function test_the_same_car_assembled_in_two_countries_is_two_variations(): void
    {
        $release = Release::factory()->create();
        $shared = ['release_id' => $release, 'color' => 'Red', 'toy_number' => 'T9671'];

        Variation::factory()->bare()->create([...$shared, 'country' => 'Malaysia']);
        Variation::factory()->bare()->create([...$shared, 'country' => 'Thailand']);

        $this->assertSame(2, $release->variations()->count());
    }

    public function test_a_row_identical_on_every_column_including_toy_number_still_collides(): void
    {
        $release = Release::factory()->create();
        $attributes = ['release_id' => $release, 'color' => 'Red', 'toy_number' => 'T9671', 'country' => 'Malaysia'];

        Variation::factory()->bare()->create($attributes);

        // The wiki lists the same car twice often enough that this is the
        // importer's normal case, not an error — two identical rows are one
        // variation, and the index is what says so.
        $this->expectException(QueryException::class);

        Variation::factory()->bare()->create($attributes);
    }

    public function test_a_proposal_can_exist_without_a_capture_behind_it(): void
    {
        $casting = Casting::factory()->pending()->create();

        $proposal = SubmissionProposal::factory()
            ->forProposable($casting)
            ->create(['submission_id' => null]);

        // The catalog importer infers a casting's make and model from its name,
        // which needs review exactly like a photographed one — but there is no
        // capture session, no photos and nobody who took them.
        $this->assertNull($proposal->fresh()->submission_id);
        $this->assertNull($proposal->fresh()->submission);
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

    public function test_one_source_key_can_only_be_imported_once(): void
    {
        RecordSource::factory()->create(['source_key' => 'page:12345']);

        // The whole basis of a re-runnable importer: a second pass over the same
        // dump has to update this row, not insert a parallel catalog beside it.
        $this->expectException(QueryException::class);

        RecordSource::factory()->create(['source_key' => 'page:12345']);
    }

    public function test_the_same_key_under_a_different_source_is_a_different_record(): void
    {
        RecordSource::factory()->create(['source_key' => 'page:12345']);

        $other = RecordSource::factory()->create([
            'source' => 'matchbox_fandom',
            'source_key' => 'page:12345',
        ]);

        // Page numbering is per-site. Scoping the constraint to the source is
        // what stops a second wiki's page 12345 colliding with this one's.
        $this->assertTrue($other->exists);
    }

    public function test_a_catalog_record_can_only_come_from_one_source(): void
    {
        $casting = Casting::factory()->create();

        RecordSource::factory()->forSourceable($casting)->create();

        // A record picking up facts from a second source is a merge, which is a
        // human decision — not a second provenance row appearing quietly and
        // leaving "where did this come from" with two answers.
        $this->expectException(QueryException::class);

        RecordSource::factory()->forSourceable($casting)->create();
    }

    public function test_an_imported_record_cannot_be_stored_without_attribution(): void
    {
        // NOT NULL alone wouldn't catch this, and a parser that failed to build
        // a URL writes exactly this. The wiki is CC-BY-SA: a row that can't name
        // its source can't legally be shown as imported.
        $this->expectException(QueryException::class);

        RecordSource::factory()->create(['source_url' => '   ']);
    }
}
