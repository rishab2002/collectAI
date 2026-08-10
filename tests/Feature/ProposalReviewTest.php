<?php

namespace Tests\Feature;

use App\Models\Casting;
use App\Models\Make;
use App\Models\RecordSource;
use App\Models\Submission;
use App\Models\SubmissionProposal;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The review queue for proposals with no capture behind them.
 *
 * The interesting behaviour is rejection. On the capture path a proposal and the
 * record it proposes are the same claim, so "no" deletes the row. Here the row
 * arrived from a wiki with its own provenance and the pipeline only annotated
 * it, so "no" has to retract the annotation and leave a real, sourced casting —
 * and the thousands of releases and variations hanging off it — alone.
 */
class ProposalReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_the_queue(): void
    {
        $this->get(route('admin.proposals.index'))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_cannot_see_that_the_queue_exists(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.proposals.index'))
            ->assertNotFound();
    }

    public function test_the_queue_shows_inferred_attributions_and_their_source(): void
    {
        [$casting, $source] = $this->attributed('Ford', 'Thunderbird', "'63 T-Bird", 0.72);

        $this->actingAs($this->admin())
            ->get(route('admin.proposals.index'))
            ->assertOk()
            ->assertSee("'63 T-Bird")
            ->assertSee('Ford Thunderbird')
            ->assertSee('72.0% confidence')
            // The only evidence there is: no photos were taken, so the source
            // page is what a reviewer checks the attribution against.
            ->assertSee($source->source_url);
    }

    public function test_the_queue_leaves_out_proposals_that_belong_to_a_capture(): void
    {
        $submission = Submission::factory()->create();
        $captured = Casting::factory()->pending()->create(['name' => 'Photographed Car']);
        SubmissionProposal::factory()
            ->forProposable($captured)
            ->create(['submission_id' => $submission->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.proposals.index'))
            ->assertOk()
            ->assertDontSee('Photographed Car');
    }

    public function test_the_dashboard_links_to_the_queue_when_something_is_waiting(): void
    {
        $this->attributed('Ford', 'Thunderbird', "'63 T-Bird", 0.72);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.proposals.index'))
            ->assertSee('1 pending');
    }

    public function test_the_queue_says_when_castings_have_no_attribution_at_all(): void
    {
        Casting::factory()->unidentified()->pending()->create();

        // An empty queue and a finished job are different things: a casting the
        // agent never reached produces no review item and would otherwise be
        // invisible from this screen.
        $this->actingAs($this->admin())
            ->get(route('admin.proposals.index'))
            ->assertOk()
            ->assertSee('no attribution at all');
    }

    public function test_approving_publishes_the_casting_its_model_and_its_make(): void
    {
        [$casting] = $this->attributed('Ford', 'Thunderbird', "'63 T-Bird", 0.72);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.proposals.approve', $casting->proposal))
            ->assertRedirect();

        $casting->refresh();

        $this->assertTrue($casting->isLive());
        $this->assertTrue($casting->model->isLive());
        $this->assertTrue($casting->model->make->isLive());
        $this->assertSame($admin->id, $casting->proposal->decided_by);
    }

    public function test_rejecting_an_imported_attribution_retracts_it_and_keeps_the_casting(): void
    {
        [$casting] = $this->attributed('Ford', 'Fairlane', 'Circle Tracker', 0.41);
        $modelId = $casting->model_id;

        $this->actingAs($this->admin())
            ->post(route('admin.proposals.reject', $casting->proposal))
            ->assertRedirect();

        $casting->refresh();

        // The casting is a real, sourced record. Marking it rejected would throw
        // it away and strand every release and variation beneath it under a
        // parent that can never go live.
        $this->assertTrue($casting->exists);
        $this->assertSame(Casting::STATUS_PENDING, $casting->status);
        $this->assertTrue($casting->isUnidentified());
        $this->assertNull($casting->model_id);

        // The model row survives, so the next casting that really is a Fairlane
        // reuses it rather than making a second one.
        $this->assertNotNull(VehicleModel::find($modelId));

        $this->assertSame(SubmissionProposal::DECISION_REJECTED, $casting->proposal->decision);
    }

    public function test_rejecting_says_what_actually_happened(): void
    {
        [$casting] = $this->attributed('Ford', 'Fairlane', 'Circle Tracker', 0.41);

        $this->actingAs($this->admin())
            ->post(route('admin.proposals.reject', $casting->proposal))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Retracted'));
    }

    public function test_rejecting_a_casting_the_pipeline_invented_still_rejects_the_row(): void
    {
        // No provenance: nothing outside this pipeline claims this casting
        // exists, so "no" means the row shouldn't exist.
        $casting = Casting::factory()->pending()->create(['name' => 'Invented Car']);
        SubmissionProposal::factory()
            ->forProposable($casting)
            ->create(['submission_id' => null, 'confidence' => 0.4]);

        $this->actingAs($this->admin())
            ->post(route('admin.proposals.reject', $casting->proposal));

        $this->assertSame(Casting::STATUS_REJECTED, $casting->refresh()->status);
    }

    public function test_a_proposal_already_ruled_on_cannot_be_ruled_on_again(): void
    {
        [$casting] = $this->attributed('Ford', 'Thunderbird', "'63 T-Bird", 0.72);
        $proposal = $casting->proposal;
        $proposal->approve();

        $this->actingAs($this->admin())
            ->post(route('admin.proposals.reject', $proposal))
            ->assertSessionHas('error');

        $this->assertSame(SubmissionProposal::DECISION_APPROVED, $proposal->refresh()->decision);
    }

    public function test_a_captures_proposal_is_sent_to_the_screen_that_has_its_photos(): void
    {
        $submission = Submission::factory()->create();
        $casting = Casting::factory()->pending()->create();
        $proposal = SubmissionProposal::factory()
            ->forProposable($casting)
            ->create(['submission_id' => $submission->id]);

        $this->actingAs($this->admin())
            ->post(route('admin.proposals.approve', $proposal))
            ->assertRedirect(route('admin.submissions.show', $submission->id));

        $this->assertSame(SubmissionProposal::DECISION_PENDING, $proposal->refresh()->decision);
    }

    /**
     * A casting as the identifier leaves one below the threshold: imported,
     * pending, attributed to a pending make and model, with an undecided
     * proposal and no submission.
     *
     * @return array{0: Casting, 1: RecordSource}
     */
    private function attributed(string $make, string $model, string $name, float $confidence): array
    {
        $vehicleModel = VehicleModel::factory()->pending()->create([
            'make_id' => Make::factory()->pending()->create(['name' => $make]),
            'name' => $model,
            'chassis_code' => null,
        ]);

        $casting = Casting::factory()->pending()->create([
            'name' => $name,
            'model_id' => $vehicleModel->id,
            'is_fantasy' => false,
        ]);

        $source = RecordSource::factory()->forSourceable($casting)->create();

        SubmissionProposal::factory()
            ->forProposable($casting)
            ->create(['submission_id' => null, 'confidence' => $confidence]);

        return [$casting->fresh(), $source];
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}
