<?php

namespace Tests\Feature;

use App\Models\Casting;
use App\Models\Make;
use App\Models\Release;
use App\Models\Submission;
use App\Models\SubmissionProposal;
use App\Models\Variation;
use App\Models\VehicleModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_submission_ocr_output_round_trips_as_structured_json(): void
    {
        $submission = Submission::factory()->completed()->create();

        $this->assertSame('Night Burnerz', $submission->fresh()->ocr_output['series']);
    }
}
