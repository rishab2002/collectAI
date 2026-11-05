<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\Make;
use App\Models\Release;
use App\Models\Series;
use App\Models\Submission;
use App\Models\SubmissionPhoto;
use App\Models\SubmissionProposal;
use App\Models\User;
use App\Models\Variation;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A proposal can outlive the record it proposes: the table is polymorphic,
     * so there is no foreign key and nothing in the database keeps the two in
     * step. One such row — a proposal for a VehicleModel that had been deleted
     * — took the whole review screen down with a 500 and left the queue
     * impossible to get past.
     */
    public function test_a_proposal_whose_record_is_gone_does_not_take_the_screen_down(): void
    {
        $submission = Submission::factory()->create();
        $casting = Casting::factory()->create(['name' => "'92 Honda Prelude"]);
        $model = VehicleModel::factory()->create();
        SubmissionProposal::factory()->create(['submission_id' => $submission, 'proposable_id' => $casting->id, 'proposable_type' => $casting->getMorphClass()]);
        $orphan = SubmissionProposal::factory()->create(['submission_id' => $submission, 'proposable_id' => $model->id, 'proposable_type' => $model->getMorphClass()]);

        // Deleted the way a record was before anything tidied up after it.
        VehicleModel::withoutEvents(fn () => $model->delete());
        $this->assertDatabaseHas('submission_proposals', ['id' => $orphan->id]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee("'92 Honda Prelude");
    }

    public function test_deleting_a_record_takes_its_proposal_with_it(): void
    {
        $casting = Casting::factory()->create();
        $proposal = SubmissionProposal::factory()->create([
            'proposable_id' => $casting->id, 'proposable_type' => $casting->getMorphClass(),
        ]);

        $casting->delete();

        $this->assertDatabaseMissing('submission_proposals', ['id' => $proposal->id]);
    }

    public function test_guests_cannot_open_a_submission(): void
    {
        $submission = Submission::factory()->create();

        $this->get(route('admin.submissions.show', $submission))
            ->assertRedirect(route('login'));
    }

    public function test_a_non_admin_cannot_see_that_a_submission_exists(): void
    {
        $submission = Submission::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.submissions.show', $submission))
            ->assertNotFound();
    }

    public function test_the_review_queue_links_to_each_submission(): void
    {
        $submission = Submission::factory()->create();
        SubmissionProposal::factory()->create(['submission_id' => $submission]);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.submissions.show', $submission));
    }

    public function test_the_screen_shows_the_photos_and_the_proposed_records(): void
    {
        $submission = Submission::factory()->completed()->withHints()->create();

        SubmissionPhoto::factory()->create([
            'submission_id' => $submission,
            'type' => SubmissionPhoto::TYPE_CARD_FRONT,
        ]);

        $variation = $this->pendingChain();

        SubmissionProposal::factory()
            ->forProposable($variation)
            ->confidence(0.62)
            ->create(['submission_id' => $submission]);

        $this->actingAs($this->admin())
            ->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Card Front')
            ->assertSee('Variation')
            // The distinguishing attributes are what the reviewer is actually
            // ruling on, so they have to be on screen rather than behind a link.
            ->assertSee('Chrome')
            ->assertSee('62.0% confidence')
            ->assertSee('Below the auto-approval threshold');
    }

    public function test_the_screen_names_the_parents_an_approval_would_publish(): void
    {
        $submission = Submission::factory()->create();
        $variation = $this->pendingChain();

        SubmissionProposal::factory()
            ->forProposable($variation)
            ->create(['submission_id' => $submission]);

        // Approving the leaf promotes six ancestors. The reviewer clicking one
        // button deserves to know that before they click it.
        $this->actingAs($this->admin())
            ->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Approving also publishes:')
            ->assertSee('Night Burnerz')
            ->assertSee('Hot Wheels')
            ->assertSee('Prelude');
    }

    public function test_approving_publishes_the_record_and_its_ancestors(): void
    {
        $admin = $this->admin();
        $submission = Submission::factory()->create();
        $variation = $this->pendingChain();

        $proposal = SubmissionProposal::factory()
            ->forProposable($variation)
            ->create(['submission_id' => $submission]);

        $this->actingAs($admin)
            ->post(route('admin.submissions.proposals.approve', [$submission, $proposal]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue($variation->fresh()->isLive());
        $this->assertTrue($variation->release->fresh()->isLive());
        $this->assertSame(SubmissionProposal::DECISION_APPROVED, $proposal->fresh()->decision);
        $this->assertSame($admin->id, $proposal->fresh()->decided_by);
    }

    public function test_approving_also_rules_on_the_proposals_of_the_parents_it_published(): void
    {
        $admin = $this->admin();
        $submission = Submission::factory()->create();
        $variation = $this->pendingChain();

        // The realistic case, and what SubmissionSeeder builds: one photo
        // proposes several levels of the hierarchy at once.
        $castingProposal = SubmissionProposal::factory()
            ->forProposable($variation->release->castingVersion->casting)
            ->create(['submission_id' => $submission]);

        $variationProposal = SubmissionProposal::factory()
            ->forProposable($variation)
            ->create(['submission_id' => $submission]);

        $this->actingAs($admin)
            ->post(route('admin.submissions.proposals.approve', [$submission, $variationProposal]));

        // The casting went live as a dependency, so its own proposal is settled
        // too — otherwise the queue would keep asking for a ruling on a record
        // that is already public.
        $this->assertSame(SubmissionProposal::DECISION_APPROVED, $castingProposal->fresh()->decision);
        $this->assertSame($admin->id, $castingProposal->fresh()->decided_by);
        $this->assertNotNull($castingProposal->fresh()->decided_at);

        $this->assertDatabaseMissing('submission_proposals', [
            'submission_id' => $submission->id,
            'decision' => SubmissionProposal::DECISION_PENDING,
        ]);
    }

    public function test_rejecting_marks_the_record_rejected_without_touching_its_parents(): void
    {
        $submission = Submission::factory()->create();
        $variation = $this->pendingChain();

        $proposal = SubmissionProposal::factory()
            ->forProposable($variation)
            ->create(['submission_id' => $submission]);

        $this->actingAs($this->admin())
            ->post(route('admin.submissions.proposals.reject', [$submission, $proposal]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(Variation::STATUS_REJECTED, $variation->fresh()->status);
        $this->assertSame(Release::STATUS_PENDING, $variation->release->fresh()->status);
    }

    public function test_an_unidentified_casting_cannot_be_approved_from_the_screen(): void
    {
        $submission = Submission::factory()->create();

        // Not fantasy, no model: a real car the pipeline couldn't name. Going
        // live would put a car in the catalog nobody can search for.
        $casting = Casting::factory()->pending()->create([
            'model_id' => null,
            'is_fantasy' => false,
            'name' => 'Unknown Coupe',
        ]);

        $proposal = SubmissionProposal::factory()
            ->forProposable($casting)
            ->create(['submission_id' => $submission]);

        $this->actingAs($this->admin())
            ->get(route('admin.submissions.show', $submission))
            ->assertOk()
            // escape: false — the heading is literal Blade text, so the
            // apostrophe reaches the page as itself rather than as &#039;.
            ->assertSee("Can't be approved yet", escape: false)
            ->assertSee('Identify the real make and model');

        $this->actingAs($this->admin())
            ->post(route('admin.submissions.proposals.approve', [$submission, $proposal]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertFalse($casting->fresh()->isLive());
        $this->assertSame(SubmissionProposal::DECISION_PENDING, $proposal->fresh()->decision);
    }

    public function test_a_blocked_ancestor_stops_a_descendant_being_approved(): void
    {
        $submission = Submission::factory()->create();

        $casting = Casting::factory()->pending()->create([
            'model_id' => null,
            'is_fantasy' => false,
        ]);

        $version = CastingVersion::factory()->pending()->create(['casting_id' => $casting]);
        $release = Release::factory()->pending()->create([
            'casting_version_id' => $version,
            'series_id' => Series::factory()->pending(),
        ]);

        $proposal = SubmissionProposal::factory()
            ->forProposable($release)
            ->create(['submission_id' => $submission]);

        // The release itself is fine; approving it would publish the casting
        // underneath, which isn't.
        $this->actingAs($this->admin())
            ->post(route('admin.submissions.proposals.approve', [$submission, $proposal]))
            ->assertSessionHas('error');

        $this->assertFalse($release->fresh()->isLive());
    }

    public function test_a_proposal_cannot_be_ruled_on_twice(): void
    {
        $submission = Submission::factory()->create();

        $proposal = SubmissionProposal::factory()
            ->approved($this->admin())
            ->create(['submission_id' => $submission]);

        $this->actingAs($this->admin())
            ->post(route('admin.submissions.proposals.reject', [$submission, $proposal]))
            ->assertSessionHas('error');

        $this->assertSame(SubmissionProposal::DECISION_APPROVED, $proposal->fresh()->decision);
    }

    public function test_a_proposal_belonging_to_another_submission_is_not_reachable(): void
    {
        $submission = Submission::factory()->create();
        $other = Submission::factory()->create();

        $proposal = SubmissionProposal::factory()->create(['submission_id' => $other]);

        // Scoped bindings: the id is real, but not on this submission.
        $this->actingAs($this->admin())
            ->post(route('admin.submissions.proposals.approve', [$submission, $proposal]))
            ->assertNotFound();

        $this->assertSame(SubmissionProposal::DECISION_PENDING, $proposal->fresh()->decision);
    }

    public function test_a_decided_proposal_is_shown_without_approve_and_reject_buttons(): void
    {
        // Named rather than left to faker: the assertion below is on rendered
        // text, and a generated name with an apostrophe in it would be escaped
        // in the page and not in the expectation.
        $admin = User::factory()->admin()->create(['name' => 'Catalog Admin']);
        $submission = Submission::factory()->create();

        $proposal = SubmissionProposal::factory()
            ->approved($admin)
            ->create(['submission_id' => $submission]);

        $this->actingAs($admin)
            ->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Approved by '.$admin->name)
            ->assertDontSee(route('admin.submissions.proposals.approve', [$submission, $proposal]));
    }

    public function test_the_screen_shows_what_was_read_with_per_field_confidence(): void
    {
        $submission = Submission::factory()->completed()->create();

        $this->actingAs($this->admin())
            ->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Read off the packaging')
            ->assertSee("'11 Honda Civic")
            // The score sits next to the field it scores, so a reviewer knows
            // the toy number is the one to check by eye.
            ->assertSee('58%')
            ->assertSee('99%')
            // Structure, not findings — these would be two meaningless rows.
            ->assertDontSee('Schema Version')
            ->assertDontSee('schema_version');
    }

    public function test_an_unreadable_field_is_shown_as_a_finding_not_a_blank(): void
    {
        $submission = Submission::factory()->completed()->create();
        $output = $submission->ocr_output;
        $output['toy_number'] = null;
        $output['confidence']['toy_number'] = 0.0;
        $submission->update(['ocr_output' => $output]);

        // "The model looked and couldn't read it" and "nobody has looked" are
        // different things to a reviewer, so a null gets a row of its own.
        $this->actingAs($this->admin())
            ->get(route('admin.submissions.show', $submission))
            ->assertOk()
            ->assertSee('Not readable');
    }

    public function test_a_photo_captured_to_the_local_disk_is_actually_displayable(): void
    {
        $path = 'submissions/'.uniqid('test-', true).'.jpg';
        Storage::disk('local')->put($path, 'not really a jpeg');

        try {
            $photo = SubmissionPhoto::factory()->create([
                'disk' => 'local',
                'path' => $path,
            ]);

            // Laravel's local file server rejects unsigned requests for a disk
            // that isn't declared public, so a plain url() 403s and every photo
            // on the review screen renders as a broken image. CAPTURE_DISK=local
            // is documented as the way to exercise capture without R2, which it
            // only does if the photos come back out again.
            $this->actingAs($this->admin())
                ->get($photo->url())
                ->assertOk();
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * The worked example from CLAUDE.md, every row pending: a Hot Wheels
     * Night Burnerz release of a Honda Prelude the catalog doesn't know yet.
     */
    private function pendingChain(): Variation
    {
        $make = Make::factory()->pending()->create(['name' => 'Honda']);
        $model = VehicleModel::factory()->pending()->create([
            'make_id' => $make,
            'name' => 'Prelude',
        ]);

        $casting = Casting::factory()->pending()->create([
            'model_id' => $model,
            'name' => "'92 Honda Prelude",
        ]);

        $version = CastingVersion::factory()->pending()->create(['casting_id' => $casting]);

        $brand = Brand::factory()->pending()->create(['name' => 'Hot Wheels']);
        $series = Series::factory()->pending()->create([
            'brand_id' => $brand,
            'name' => 'Night Burnerz',
        ]);

        $release = Release::factory()->pending()->create([
            'casting_version_id' => $version,
            'series_id' => $series,
            'year' => 2011,
        ]);

        return Variation::factory()->pending()->create([
            'release_id' => $release,
            'finish' => 'Chrome',
            'color' => 'Red',
        ]);
    }
}
