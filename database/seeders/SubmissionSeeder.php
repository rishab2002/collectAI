<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\Make;
use App\Models\Series;
use App\Models\Submission;
use App\Models\SubmissionPhoto;
use App\Models\SubmissionProposal;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

/**
 * A worked capture-and-review scenario, so the review screen has something real
 * to render before the AI pipeline exists to produce it.
 *
 * Mirrors CLAUDE.md's example flow: a Hot Wheels 2011 Night Burnerz photo of a
 * car the catalog doesn't know yet, which proposes records at several levels of
 * the hierarchy at once — exactly the case the approval walk has to promote in
 * dependency order.
 */
class SubmissionSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('is_admin', true)->first();

        if ($admin === null) {
            $this->command?->warn('No admin user found — skipping submission seed.');

            return;
        }

        $hotWheels = Brand::where('name', 'Hot Wheels')->first();

        if ($hotWheels === null) {
            $this->command?->warn('Catalog not seeded — skipping submission seed.');

            return;
        }

        $submission = Submission::create([
            'user_id' => $admin->id,
            'state' => Submission::STATE_COMPLETED,
            'hint_brand' => 'Hot Wheels',
            'hint_year' => 2011,
            'hint_series' => 'Night Burnerz',
            'ocr_output' => [
                'brand' => 'Hot Wheels',
                'year' => 2011,
                'series' => 'Night Burnerz',
                'casting_name' => "'92 Honda Prelude",
                'collector_number' => '160/244',
            ],
            'processed_at' => now(),
        ]);

        SubmissionPhoto::create([
            'submission_id' => $submission->id,
            'disk' => 'local',
            'path' => 'submissions/seed-prelude-card-front.jpg',
            'type' => SubmissionPhoto::TYPE_CARD_FRONT,
        ]);

        // The proposed chain, every row left pending. Note these are created
        // without markLive() — this is what unreviewed pipeline output looks
        // like sitting in the catalog tables, invisible to public reads.
        $prelude = VehicleModel::create([
            'make_id' => Make::where('name', 'Honda')->value('id'),
            'name' => 'Prelude',
            'chassis_code' => 'BB4',
        ]);

        $casting = Casting::create([
            'model_id' => $prelude->id,
            'name' => "'92 Honda Prelude",
        ]);

        $version = $casting->versions()->create(['version_number' => 1]);

        // The series already exists and is live, so it isn't proposed — this is
        // the Comparison Agent's "only the missing ones proceed" behaviour.
        $series = Series::where('name', 'Night Burnerz')->first();

        $release = $version->releases()->create([
            'series_id' => $series->id,
            'year' => 2011,
            'collector_number' => '160/244',
        ]);

        $variation = $release->variations()->create([
            'finish' => 'Enamel',
            'color' => 'Red',
            'rim_type' => 'PR5',
        ]);

        // Confidence descends down the hierarchy: the model name is legible on
        // the card, the exact variation attributes are the most interpretive.
        foreach ([
            [$prelude, 0.94],
            [$casting, 0.91],
            [$version, 0.88],
            [$release, 0.86],
            [$variation, 0.62],
        ] as [$proposable, $confidence]) {
            SubmissionProposal::create([
                'submission_id' => $submission->id,
                'proposable_type' => $proposable->getMorphClass(),
                'proposable_id' => $proposable->getKey(),
                'confidence' => $confidence,
            ]);
        }

        $this->command?->info("Seeded submission #{$submission->id} with 5 pending proposals.");
    }
}
