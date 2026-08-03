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
use App\Models\User;
use App\Models\Variation;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_records_default_to_pending(): void
    {
        // Created without going through the factory's 'live' default, i.e. the
        // way the Insertion Agent will create them.
        $make = Make::create(['name' => 'Mazda']);

        $this->assertSame(Make::STATUS_PENDING, $make->fresh()->status);
        $this->assertFalse($make->isLive());
    }

    public function test_live_scope_excludes_pending_and_rejected_records(): void
    {
        Make::factory()->create(['name' => 'Mazda']);
        Make::factory()->pending()->create(['name' => 'Honda']);
        Make::factory()->rejected()->create(['name' => 'Nissan']);

        $this->assertSame(['Mazda'], Make::live()->pluck('name')->all());
        $this->assertCount(2, Make::awaitingReview()->get());
    }

    public function test_approving_a_proposal_promotes_the_whole_ancestor_chain(): void
    {
        $variation = $this->pendingChain();

        $proposal = SubmissionProposal::factory()
            ->forProposable($variation)
            ->create(['submission_id' => Submission::factory()]);

        $proposal->approve(User::factory()->admin()->create());

        // Every ancestor has to be live, or the catalog would contain a visible
        // variation hanging off records the public can't see.
        $this->assertTrue($variation->fresh()->isLive());
        $this->assertTrue($variation->release->fresh()->isLive());
        $this->assertTrue($variation->release->castingVersion->fresh()->isLive());
        $this->assertTrue($variation->release->castingVersion->casting->fresh()->isLive());
        $this->assertTrue($variation->release->series->fresh()->isLive());
        $this->assertTrue($variation->release->castingVersion->casting->brand->fresh()->isLive());
        $this->assertTrue($variation->release->castingVersion->casting->model->fresh()->isLive());
        $this->assertTrue($variation->release->castingVersion->casting->model->make->fresh()->isLive());
    }

    public function test_approving_records_the_decision_and_who_made_it(): void
    {
        $admin = User::factory()->admin()->create();

        $proposal = SubmissionProposal::factory()->create();
        $proposal->approve($admin);

        $proposal->refresh();

        $this->assertSame(SubmissionProposal::DECISION_APPROVED, $proposal->decision);
        $this->assertSame($admin->id, $proposal->decided_by);
        $this->assertNotNull($proposal->decided_at);
    }

    public function test_rejecting_marks_the_record_rejected_without_touching_its_parents(): void
    {
        $variation = $this->pendingChain();
        $release = $variation->release;

        $proposal = SubmissionProposal::factory()
            ->forProposable($variation)
            ->create();

        $proposal->reject(User::factory()->admin()->create());

        $this->assertSame(Variation::STATUS_REJECTED, $variation->fresh()->status);
        // Rejecting a variation says nothing about whether its release was wrong.
        $this->assertSame(Release::STATUS_PENDING, $release->fresh()->status);
        $this->assertSame(SubmissionProposal::DECISION_REJECTED, $proposal->fresh()->decision);
    }

    public function test_the_promotion_walk_handles_a_fantasy_casting_with_no_model(): void
    {
        $casting = Casting::factory()->fantasy()->pending()->create([
            'brand_id' => Brand::factory()->pending(),
        ]);

        $proposal = SubmissionProposal::factory()->forProposable($casting)->create();
        $proposal->approve();

        // A null model is a legitimate absence, not an unapproved parent — the
        // walk skips it rather than erroring.
        $this->assertTrue($casting->fresh()->isLive());
        $this->assertTrue($casting->brand->fresh()->isLive());
        $this->assertNull($casting->fresh()->model_id);
    }

    public function test_already_live_ancestors_are_left_alone(): void
    {
        $brand = Brand::factory()->create();
        $casting = Casting::factory()->pending()->create(['brand_id' => $brand]);

        $originalUpdatedAt = $brand->updated_at;

        SubmissionProposal::factory()->forProposable($casting)->create()->approve();

        $this->assertTrue($casting->fresh()->isLive());
        $this->assertEquals($originalUpdatedAt, $brand->fresh()->updated_at);
    }

    public function test_a_proposal_below_the_threshold_requires_manual_review(): void
    {
        $proposal = SubmissionProposal::factory()->confidence(0.62)->create();

        $this->assertTrue($proposal->requiresManualReview(0.95));
        $this->assertFalse($proposal->requiresManualReview(0.5));
    }

    public function test_a_proposal_with_no_confidence_score_always_requires_review(): void
    {
        $proposal = SubmissionProposal::factory()->create(['confidence' => null]);

        // Treated as unknown rather than as zero, so a pipeline bug that drops
        // the score can't widen what auto-approves.
        $this->assertTrue($proposal->requiresManualReview(0.0));
    }

    /**
     * A full make -> model -> casting -> version -> release -> variation chain,
     * every row pending, as one pipeline run would leave it.
     */
    private function pendingChain(): Variation
    {
        $make = Make::factory()->pending()->create();
        $model = VehicleModel::factory()->pending()->create(['make_id' => $make]);
        $brand = Brand::factory()->pending()->create();

        $casting = Casting::factory()->pending()->create([
            'brand_id' => $brand,
            'model_id' => $model,
        ]);

        $version = CastingVersion::factory()->pending()->create(['casting_id' => $casting]);
        $series = Series::factory()->pending()->create(['brand_id' => $brand]);

        $release = Release::factory()->pending()->create([
            'casting_version_id' => $version,
            'series_id' => $series,
        ]);

        return Variation::factory()->pending()->create(['release_id' => $release]);
    }
}
